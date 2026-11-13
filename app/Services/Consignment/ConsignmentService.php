<?php

namespace App\Services\Consignment;

use App\Models\Consignor;
use App\Models\ConsignmentMovement;
use App\Models\ConsignmentPayment;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Services\Accounting\AutoJournalService;
use App\Services\StockMutationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Konsinyasi: penitip menaruh barang, koperasi menjual, tiap periode koperasi membayar
 * bagian penitip (harga titip x terjual) lewat Instruksi Pembayaran. Barang bukan milik
 * koperasi, jadi titip/tarik hanya mengubah stok (tanpa jurnal persediaan).
 */
class ConsignmentService
{
    public function __construct(private readonly StockMutationService $mutations) {}

    /** Titip masuk (in) atau tarik keluar (out). */
    public function move(Consignor $consignor, Product $product, ?int $warehouseId, string $type, int $qty, string $date, ?string $note, ?int $userId): ConsignmentMovement
    {
        if ((int) $product->consignor_id !== (int) $consignor->id) {
            throw ValidationException::withMessages(['product_id' => 'Barang ini bukan titipan penitip tersebut.']);
        }
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => 'Jumlah harus lebih dari 0.']);
        }

        return DB::transaction(function () use ($consignor, $product, $warehouseId, $type, $qty, $date, $note, $userId) {
            $product = Product::whereKey($product->id)->lockForUpdate()->first();
            $pivot = $warehouseId ? ProductWarehouse::firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId], ['stock' => 0]) : null;
            if ($pivot) {
                $pivot = ProductWarehouse::whereKey($pivot->id)->lockForUpdate()->first();
            }
            $available = $pivot ? (int) $pivot->stock : (int) $product->stock;
            if ($type === 'out' && $qty > $available) {
                throw ValidationException::withMessages(['qty' => "Stok titipan di gudang ini tinggal {$available}."]);
            }
            $delta = $type === 'in' ? $qty : -$qty;
            $before = (int) $product->stock;
            $product->increment('stock', $delta);
            $pivot?->increment('stock', $delta);

            $movement = ConsignmentMovement::create([
                'consignor_id' => $consignor->id, 'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'type' => $type,
                'qty' => $qty, 'date' => $date, 'note' => $note, 'created_by' => $userId,
            ]);
            $this->mutations->recordMutation($product, $warehouseId, 'consignment', $movement->id, $type, $qty, $before, $before + $delta,
                ($type === 'in' ? 'Titip masuk konsinyasi' : 'Ditarik penitip').($note ? ': '.$note : ''), $userId);

            return $movement;
        });
    }

    /**
     * Rincian barang titipan yang terjual dalam periode (dari kasir), per barang.
     *
     * @return list<array{product_id:int,title:string,unit:string,qty:float,stock:int,consign_price:int,sell_price:int,sales:int,payment:int}>
     */
    public function salesRows(Consignor $consignor, string $from, string $to): array
    {
        $rows = DB::table('transaction_details as d')
            ->join('transactions as t', 't.id', '=', 'd.transaction_id')
            ->where('d.consignor_id', $consignor->id)
            ->whereBetween('t.created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->groupBy('d.product_id')
            ->selectRaw('d.product_id, SUM(d.qty * COALESCE(d.conversion_factor, 1)) as qty, SUM(d.price) as sales, SUM(d.consignment_cost) as cost')
            ->get();
        $products = Product::with('units')->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

        return $rows->map(function ($r) use ($products) {
            $p = $products[$r->product_id] ?? null;
            $qty = (float) $r->qty;

            return [
                'product_id' => (int) $r->product_id,
                'title' => $p?->title ?? '(barang dihapus)',
                'unit' => strtoupper((string) ($p?->units->firstWhere('pivot.is_base', true)?->name ?? 'PCS')),
                'qty' => $qty == (int) $qty ? (int) $qty : round($qty, 2),
                'stock' => (int) ($p?->stock ?? 0),
                'consign_price' => $qty ? (int) round($r->cost / $qty) : (int) ($p?->buy_price ?? 0),
                'sell_price' => (int) ($p?->sell_price ?? 0),
                'sales' => (int) $r->sales,
                'payment' => (int) $r->cost,
            ];
        })->sortBy('title')->values()->all();
    }

    /** Awal periode berikutnya: sehari sesudah IP terakhir, atau awal bulan ini. */
    public function nextPeriodFrom(Consignor $consignor): string
    {
        $last = $consignor->payments()->max('period_to');

        return $last ? Carbon::parse($last)->addDay()->toDateString() : now()->startOfMonth()->toDateString();
    }

    /** Ringkasan hutang ke penitip: total bagian penitip dari penjualan − yang sudah dibayar. */
    public function balances(): array
    {
        $owed = DB::table('transaction_details')->whereNotNull('consignor_id')->groupBy('consignor_id')
            ->selectRaw('consignor_id, SUM(consignment_cost) as cost, SUM(price) as sales')->get()->keyBy('consignor_id');
        $paid = ConsignmentPayment::where('status', 'paid')->groupBy('consignor_id')->selectRaw('consignor_id, SUM(total) as t')->pluck('t', 'consignor_id');
        $billed = ConsignmentPayment::groupBy('consignor_id')->selectRaw('consignor_id, SUM(total) as t')->pluck('t', 'consignor_id');
        $stock = Product::whereNotNull('consignor_id')->groupBy('consignor_id')
            ->selectRaw('consignor_id, COUNT(*) as items, SUM(stock) as qty, SUM(stock * sell_price) as value')->get()->keyBy('consignor_id');
        $out = [];
        foreach (Consignor::pluck('id') as $id) {
            $cost = (int) ($owed[$id]->cost ?? 0);
            $out[$id] = [
                'sales' => (int) ($owed[$id]->sales ?? 0), 'owed' => $cost, 'paid' => (int) ($paid[$id] ?? 0),
                'unbilled' => $cost - (int) ($billed[$id] ?? 0), 'balance' => $cost - (int) ($paid[$id] ?? 0),
                'items' => (int) ($stock[$id]->items ?? 0), 'stock' => (int) ($stock[$id]->qty ?? 0), 'stock_value' => (int) ($stock[$id]->value ?? 0),
            ];
        }

        return $out;
    }

    public function createPayment(Consignor $consignor, array $data, ?int $userId): ConsignmentPayment
    {
        $items = $this->salesRows($consignor, $data['period_from'], $data['period_to']);
        if (! $items) {
            throw ValidationException::withMessages(['period_from' => 'Tidak ada barang titipan yang terjual di periode ini.']);
        }
        $number = trim($data['number'] ?? '') ?: DocumentNumber::next('consignment', $data['date']);
        $seq = DocumentNumber::parseSeq($number) ?? DocumentNumber::nextSeq('consignment', $data['date']);
        if (ConsignmentPayment::where('number', $number)->exists()) {
            throw ValidationException::withMessages(['number' => "Nomor {$number} sudah dipakai."]);
        }

        return ConsignmentPayment::create([
            'number' => $number, 'year' => Carbon::parse($data['date'])->year, 'seq' => $seq, 'consignor_id' => $consignor->id,
            'date' => $data['date'], 'period_from' => $data['period_from'], 'period_to' => $data['period_to'], 'items' => $items,
            'total' => array_sum(array_column($items, 'payment')), 'sales_total' => array_sum(array_column($items, 'sales')),
            'signer' => $data['signer'] ?? null, 'created_by' => $userId,
        ]);
    }

    public function markPaid(ConsignmentPayment $payment, string $date, string $cashAccount): void
    {
        $payment->update(['status' => 'paid', 'paid_at' => $date, 'cash_account' => $cashAccount]);
        AutoJournalService::markDirty('consignment_payment', (string) $payment->id);
    }
}
