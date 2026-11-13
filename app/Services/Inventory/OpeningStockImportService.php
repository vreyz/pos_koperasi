<?php

namespace App\Services\Inventory;

use App\Models\ConsignmentMovement;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMutation;
use App\Services\Cooperative\SpreadsheetReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saldo awal stok dari berkas "IMPORT_ITEM" iPos (kolom KODE ITEM, KONVERSI SATUAN DASAR, STOK,
 * KODE SUPPLIER, KETERANGAN). Stok di aplikasi DIGANTI dengan stok berkas per tanggal saldo awal:
 * barang yang tidak ada di berkas menjadi 0. Baris KETERANGAN "KONSINYASI" dicatat sebagai titipan
 * penitip (KODE SUPPLIER), bukan persediaan koperasi. Tanpa jurnal: nilai persediaan awal sudah
 * ada di neraca saldo awal (11501).
 */
class OpeningStockImportService
{
    public const REFERENCE = 'opening_balance';

    public const CONSIGNMENT_NOTE = 'Saldo awal titipan';

    /** Mutasi yang boleh ditimpa: hasil impor stok sebelumnya, bukan transaksi nyata. */
    private const REPLACEABLE = ['opening_balance', 'ipos_import'];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return array{rows: list<array{product: Product, qty: int, consignor: ?string}>, skipped_units: int, unmatched: list<int>}
     */
    public function plan(string $path): array
    {
        $sheet = $this->reader->readSheet($path, null);
        $header = SpreadsheetReader::locateHeader($sheet, [
            'code' => ['kode item'], 'barcode' => ['barcode'], 'conversion' => ['konversi satuan dasar'],
            'stock' => ['stok'], 'supplier' => ['kode supplier'], 'note' => ['keterangan'],
        ], ['code', 'stock']);
        if (! $header) {
            throw new RuntimeException('Kolom KODE ITEM dan STOK tidak ditemukan.');
        }
        [$headerRow, $col] = $header;

        $products = Product::all();
        $bySku = $products->keyBy(fn ($p) => (string) $p->sku);
        $byBarcode = $products->keyBy(fn ($p) => (string) $p->barcode);
        $rows = [];
        $skippedUnits = 0;
        $unmatched = [];
        foreach ($sheet as $rowNumber => $row) {
            $code = trim((string) ($row[$col['code']] ?? ''));
            if ($rowNumber <= $headerRow || $code === '') {
                continue;
            }
            // Baris satuan besar (konversi > 1) hanya daftar satuan; stoknya ada di baris satuan dasar.
            if (isset($col['conversion']) && (float) ($row[$col['conversion']] ?? 1) > 1) {
                $skippedUnits++;

                continue;
            }
            $product = $bySku[$code] ?? $byBarcode[trim((string) ($row[$col['barcode'] ?? -1] ?? ''))] ?? null;
            if (! $product) {
                $unmatched[] = $rowNumber;

                continue;
            }
            $isConsignment = strtoupper(trim((string) ($row[$col['note'] ?? -1] ?? ''))) === 'KONSINYASI';
            $supplier = trim((string) ($row[$col['supplier'] ?? -1] ?? ''));
            $rows[$product->id] = [
                'product' => $product,
                'qty' => max(0, (int) round((float) ($row[$col['stock']] ?? 0))),
                'consignor' => $isConsignment ? ($supplier !== '' ? $supplier : 'Penitip tanpa nama') : null,
            ];
        }

        return ['rows' => array_values($rows), 'skipped_units' => $skippedUnits, 'unmatched' => $unmatched];
    }

    /** Mutasi stok "nyata" (kasir, penerimaan, transfer, opname, titip/tarik) yang akan ikut tertimpa. */
    public function realMovementCount(): int
    {
        $openingMovements = ConsignmentMovement::where('note', self::CONSIGNMENT_NOTE)->pluck('id');

        return StockMutation::whereNotIn('reference_type', self::REPLACEABLE)
            ->where(fn ($q) => $q->where('reference_type', '!=', 'consignment')->orWhereNotIn('reference_id', $openingMovements))
            ->count();
    }

    /**
     * @param  array{rows: list<array{product: Product, qty: int, consignor: ?string}>}  $plan
     * @return array{products: int, qty: int, consignment_qty: int, consignors: int, value: int, missing_cost: int, zeroed: int}
     */
    public function apply(array $plan, string $date, int $warehouseId, ?int $userId = null): array
    {
        $at = Carbon::parse($date)->startOfDay();

        return DB::transaction(function () use ($plan, $at, $warehouseId, $userId) {
            $old = ConsignmentMovement::where('note', self::CONSIGNMENT_NOTE)->pluck('id');
            StockMutation::where('reference_type', 'consignment')->whereIn('reference_id', $old)->delete();
            ConsignmentMovement::whereIn('id', $old)->delete();
            StockMutation::whereIn('reference_type', self::REPLACEABLE)->delete();

            $zeroed = Product::where('stock', '!=', 0)->count();
            Product::query()->update(['stock' => 0]);
            ProductWarehouse::query()->update(['stock' => 0]);

            $stats = ['products' => 0, 'qty' => 0, 'consignment_qty' => 0, 'consignors' => 0, 'value' => 0, 'missing_cost' => 0, 'zeroed' => $zeroed];
            $consignors = [];
            foreach ($plan['rows'] as $row) {
                $product = $row['product'];
                $qty = $row['qty'];
                $consignorId = null;
                if ($row['consignor'] !== null) {
                    $consignorId = $consignors[$row['consignor']] ??= Consignor::firstOrCreate(['name' => $row['consignor']])->id;
                }
                // Langsung ke tabel: model di $plan masih memegang stok lama, jadi save() bisa dianggap "tidak berubah".
                Product::whereKey($product->id)->update(['stock' => $qty, 'consignor_id' => $consignorId ?? $product->consignor_id]);
                ProductWarehouse::updateOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId], ['stock' => $qty]);
                if ($qty === 0) {
                    continue;
                }

                $reference = [self::REFERENCE, null, 'Saldo awal stok'];
                if ($consignorId) {
                    $movement = ConsignmentMovement::create(['consignor_id' => $consignorId, 'product_id' => $product->id, 'warehouse_id' => $warehouseId,
                        'type' => 'in', 'qty' => $qty, 'date' => $at->toDateString(), 'note' => self::CONSIGNMENT_NOTE, 'created_by' => $userId]);
                    $reference = ['consignment', $movement->id, 'Titip masuk konsinyasi: '.self::CONSIGNMENT_NOTE];
                    $stats['consignment_qty'] += $qty;
                } else {
                    $stats['qty'] += $qty;
                    $stats['value'] += $qty * (int) $product->buy_price;
                    $stats['missing_cost'] += (int) $product->buy_price === 0 ? 1 : 0;
                }
                (new StockMutation)->forceFill([
                    'product_id' => $product->id, 'warehouse_id' => $warehouseId, 'reference_type' => $reference[0], 'reference_id' => $reference[1],
                    'mutation_type' => 'in', 'qty' => $qty, 'stock_before' => 0, 'stock_after' => $qty, 'notes' => $reference[2],
                    'created_by' => $userId, 'created_at' => $at, 'updated_at' => $at,
                ])->save();
                $stats['products']++;
            }
            $stats['consignors'] = count($consignors);

            return $stats;
        });
    }
}
