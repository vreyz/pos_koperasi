<?php

namespace App\Services\Accounting;

use App\Models\Customer;
use App\Models\Financing;
use App\Models\FinancingInstallment;
use App\Models\GoodsReceiving;
use App\Models\JournalEntry;
use App\Models\MemberContribution;
use App\Models\PayablePayment;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\ShuPeriod;
use App\Models\StockOpname;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\Cooperative\PayrollReceivableService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jurnal otomatis dari menu asal (kasir, penerimaan barang, retur, stock opname, piutang,
 * hutang supplier, pembiayaan, rekap potongan, simpanan, SHU), mulai tanggal di pengaturan
 * (bawaan 1 Januari 2027).
 *
 * Cara kerja: setiap sumber punya kunci (auto_key). Sinkronisasi menyusun jurnal yang
 * seharusnya; bila belum ada dibuat, bila isinya berubah diganti, bila sumbernya hilang
 * dihapus. Jurnal di tahun yang sudah ditutup tidak diubah (dilaporkan sebagai "terkunci").
 */
class AutoJournalService
{
    public const FROM_KEY = 'accounting.auto_from';

    public const FROM_DEFAULT = '2027-01-01';

    public const KANTIN_KEY = 'accounting.kantin_warehouse_ids';

    /** Lingkup yang berubah selama satu request; disinkronkan setelah respons terkirim. */
    private static array $dirty = [];

    private static bool $flushRegistered = false;

    private array $stats = ['dibuat' => 0, 'diganti' => 0, 'dihapus' => 0, 'sama' => 0, 'terkunci' => 0, 'gagal' => 0];

    private array $messages = [];

    public function __construct(private readonly JournalService $journal, private readonly EventCatalog $catalog) {}

    public static function autoFrom(): string
    {
        return (string) (Setting::get(self::FROM_KEY, self::FROM_DEFAULT) ?: self::FROM_DEFAULT);
    }

    public static function enabledFor($date): bool
    {
        return $date && Carbon::parse($date)->toDateString() >= self::autoFrom();
    }

    /** Catat lingkup yang perlu disinkronkan; dijalankan sekali di akhir request (tidak memperlambat kasir). */
    public static function markDirty(string $scope, string $id): void
    {
        self::$dirty[$scope.'|'.$id] = [$scope, $id];
        if (! self::$flushRegistered) {
            self::$flushRegistered = true;
            app()->terminating(fn () => app(self::class)->flushDirty());
        }
    }

    public function flushDirty(): void
    {
        $items = self::$dirty;
        self::$dirty = [];
        self::$flushRegistered = false;
        foreach ($items as [$scope, $id]) {
            try {
                $this->syncScope($scope, $id);
            } catch (Throwable $e) {
                Log::warning("Jurnal otomatis {$scope}:{$id} gagal: ".$e->getMessage());
            }
        }
    }

    public function syncScope(string $scope, string $id): void
    {
        match ($scope) {
            'sales' => $this->syncSalesDay($id),
            'financing' => ($f = Financing::find($id)) ? $this->syncFinancing($f) : $this->removeKeys("fin:{$id}:"),
            'gr' => $this->syncGoodsReceiving((int) $id),
            'return' => $this->syncReturn((int) $id),
            'opname' => $this->syncOpname((int) $id),
            'payable' => $this->syncPayablePayment((int) $id),
            'receivable' => $this->syncReceivablePayment((int) $id),
            'contribution' => $this->syncContribution((int) $id),
            'recap' => $this->syncRecap($id),
            'shu' => $this->syncShu((int) $id),
            default => null,
        };
    }

    /** Sinkronkan semua sumber dalam rentang tanggal (bawaan: sejak tanggal mulai s.d. hari ini). */
    public function syncAll(?string $from = null, ?string $to = null): array
    {
        $from = max($from ?? self::autoFrom(), self::autoFrom());
        // Tanpa batas akhir: data bertanggal sesudah hari ini (mis. rekap potongan bulan depan) tetap ikut.
        $to = $to ?? '2999-12-31';
        if ($from > $to) {
            return ['stats' => $this->stats, 'messages' => ['Jurnal otomatis mulai '.Carbon::parse(self::autoFrom())->translatedFormat('j F Y').'.']];
        }
        $end = Carbon::parse($to)->endOfDay();

        $days = Transaction::whereBetween('created_at', [$from, $end])->selectRaw('DATE(created_at) as d')->distinct()->pluck('d')
            ->merge(JournalEntry::where('auto_key', 'like', 'pos:%')->whereBetween('date', [$from, $to])->pluck('date')->map(fn ($d) => $d->toDateString()))
            ->unique();
        foreach ($days as $d) {
            $this->safely(fn () => $this->syncSalesDay(Carbon::parse($d)->toDateString()));
        }
        Financing::where(fn ($q) => $q->where('disbursed_at', '>=', $from)->orWhereHas('installments', fn ($i) => $i->where('paid_at', '>=', $from)))
            ->get()->each(fn ($f) => $this->safely(fn () => $this->syncFinancing($f)));
        GoodsReceiving::whereBetween('received_at', [$from, $end])->pluck('id')->each(fn ($id) => $this->safely(fn () => $this->syncGoodsReceiving($id)));
        SalesReturn::whereBetween('completed_at', [$from, $end])->pluck('id')->each(fn ($id) => $this->safely(fn () => $this->syncReturn($id)));
        StockOpname::whereBetween('finalized_at', [$from, $end])->pluck('id')->each(fn ($id) => $this->safely(fn () => $this->syncOpname($id)));
        PayablePayment::whereBetween('paid_at', [$from, $to])->pluck('id')->each(fn ($id) => $this->safely(fn () => $this->syncPayablePayment($id)));
        ReceivablePayment::whereBetween('paid_at', [$from, $to])->pluck('id')->each(fn ($id) => $this->safely(fn () => $this->syncReceivablePayment($id)));
        MemberContribution::whereIn('source', ['manual', 'tunai', 'transfer'])->whereBetween('created_at', [$from, $end])->pluck('id')
            ->each(fn ($id) => $this->safely(fn () => $this->syncContribution($id)));
        $f = Carbon::parse($from);
        $periods = MemberContribution::query()->whereIn('source', ['import', 'potong_gaji'])
            ->where(fn ($q) => $q->where('period_year', '>', $f->year)->orWhere(fn ($w) => $w->where('period_year', $f->year)->where('period_month', '>=', $f->month)))
            ->selectRaw('period_year, period_month')->distinct()->get()
            ->map(fn ($r) => sprintf('%04d-%02d', $r->period_year, $r->period_month));
        foreach ($periods as $p) {
            $this->safely(fn () => $this->syncRecap($p));
        }
        ShuPeriod::where('status', 'final')->pluck('id')->each(fn ($id) => $this->safely(fn () => $this->syncShu($id)));

        return ['stats' => $this->stats, 'messages' => $this->messages];
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->stats['gagal']++;
            $this->messages[] = $e->getMessage();
            Log::warning('Jurnal otomatis gagal: '.$e->getMessage());
        }
    }

    // ------------------------------------------------------------------ inti

    /**
     * Pastikan jurnal untuk satu kunci sama dengan yang seharusnya.
     *
     * @param  list<array{date:string,description:string,division:string,event_code:string,lines:list<array>}>  $entries
     */
    public function apply(string $key, array $entries, $source = null): string
    {
        $entries = array_values(array_filter(array_map(function ($e) {
            $e['lines'] = $this->journal->normalize($e['lines']);

            return $e;
        }, $entries), fn ($e) => count($e['lines']) >= 2));
        $fingerprint = sha1(json_encode($entries));
        $existing = JournalEntry::where('auto_key', $key)->get();

        if ($existing->isNotEmpty() && $existing->every(fn ($e) => $e->fingerprint === $fingerprint) && $existing->count() === count($entries)) {
            $this->stats['sama']++;

            return 'sama';
        }
        $dates = $existing->pluck('date')->map->toDateString()->merge(array_column($entries, 'date'));
        foreach ($dates->unique() as $d) {
            if ($this->yearClosed($d)) {
                $this->stats['terkunci']++;
                $this->messages[] = "{$key}: data berubah di tahun yang sudah ditutup; buat jurnal koreksi manual.";

                return 'terkunci';
            }
        }

        DB::transaction(function () use ($existing, $entries, $key, $fingerprint, $source) {
            $existing->each(fn ($e) => $e->delete());
            foreach ($entries as $e) {
                $this->journal->post([
                    'date' => $e['date'], 'description' => $e['description'], 'division' => $e['division'], 'event_code' => $e['event_code'],
                    'auto_key' => $key, 'fingerprint' => $fingerprint, 'source' => $source, 'created_by' => null,
                ], $e['lines']);
            }
        });
        $result = $existing->isEmpty() ? 'dibuat' : (count($entries) ? 'diganti' : 'dihapus');
        if ($existing->isNotEmpty() || count($entries)) {
            $this->stats[$result]++;
        }

        return $result;
    }

    private function removeKeys(string $prefix): void
    {
        JournalEntry::where('auto_key', 'like', $prefix.'%')->pluck('auto_key')->unique()->each(fn ($k) => $this->apply($k, []));
    }

    private function yearClosed(string $date): bool
    {
        return JournalEntry::where('event_code', 'TB-01')->whereDate('date', Carbon::parse($date)->year.'-12-31')->exists();
    }

    private static function D(string $acc, int|float $amt, ?int $customer = null, ?string $memo = null): array
    {
        return ['account' => $acc, 'debit' => (int) round($amt), 'credit' => 0, 'customer_id' => $customer, 'memo' => $memo];
    }

    private static function K(string $acc, int|float $amt, ?int $customer = null, ?string $memo = null): array
    {
        return ['account' => $acc, 'debit' => 0, 'credit' => (int) round($amt), 'customer_id' => $customer, 'memo' => $memo];
    }

    private function loanAccount(Financing $f): string
    {
        if ($f->type === 'barang') {
            return '11303';
        }

        return $f->customer?->employee_type === 'PPNPN' ? '11302' : '11301';
    }

    // ------------------------------------------------------------------ Toko & Kantin

    /** Gudang/outlet kantin: dari pengaturan, atau yang namanya mengandung "kantin". */
    public function kantinWarehouseIds(): array
    {
        $set = trim((string) Setting::get(self::KANTIN_KEY, ''));
        if ($set !== '') {
            return array_map('intval', array_filter(explode(',', $set)));
        }

        return Warehouse::query()->leftJoin('outlets', 'outlets.id', '=', 'warehouses.outlet_id')
            ->where(fn ($q) => $q->where('warehouses.name', 'like', '%kantin%')->orWhere('outlets.name', 'like', '%kantin%'))
            ->pluck('warehouses.id')->all();
    }

    /** Rekap penjualan kasir per hari per gudang. Kantin: uang muka lalu bagi hasil. */
    public function syncSalesDay(string $date): void
    {
        if (! self::enabledFor($date)) {
            return;
        }
        $kantin = $this->kantinWarehouseIds();
        $txs = Transaction::query()
            ->whereDate('created_at', $date)
            ->with(['tenders', 'details:id,transaction_id,price', 'profits:id,transaction_id,total', 'receivable:id,transaction_id,source'])
            ->get();
        $byWarehouse = $txs->groupBy(fn ($t) => (int) $t->warehouse_id);
        $keys = JournalEntry::where('auto_key', 'like', "pos:{$date}:%")->pluck('auto_key')->unique()->all();

        foreach ($byWarehouse as $warehouseId => $list) {
            $isKantin = in_array($warehouseId, $kantin, true);
            $debits = [];
            $revenue = 0;
            $hpp = 0;
            foreach ($list as $t) {
                $parts = $this->paymentParts($t);
                if (! $parts) {
                    continue;
                }
                foreach ($parts as [$acc, $amount, $cust]) {
                    $k = $acc.'|'.($cust ?? '');
                    $debits[$k] = [$acc, ($debits[$k][1] ?? 0) + $amount, $cust];
                    $revenue += $amount;
                }
                $hpp += max(0, (int) $t->details->sum('price') - (int) $t->discount - (int) $t->profits->sum('total'));
            }
            $key = "pos:{$date}:{$warehouseId}";
            $keys = array_diff($keys, [$key]);
            if ($revenue <= 0) {
                $this->apply($key, []);

                continue;
            }
            $lines = array_map(fn ($d) => self::D($d[0], $d[1], $d[2] ?: null), array_values($debits));
            if ($isKantin) {
                $share = EventCatalog::kantinShare();
                $koperasi = (int) round($revenue * $share / 100);
                $entries = [
                    ['date' => $date, 'description' => 'Penjualan kantin (uang muka) - rekap kasir', 'division' => 'toko', 'event_code' => 'TK-15',
                        'lines' => [...$lines, self::K('21112', $revenue)]],
                    ['date' => $date, 'description' => 'Bagi hasil kantin '.rtrim(rtrim(number_format($share, 2, ',', '.'), '0'), ',').'% dan hutang ke penjaga kantin', 'division' => 'toko', 'event_code' => 'TK-15',
                        'lines' => [self::D('21112', $revenue), self::K('43101', $koperasi), self::K('21104', $revenue - $koperasi)]],
                ];
            } else {
                $entries = [['date' => $date, 'description' => 'Penjualan toko - rekap kasir', 'division' => 'toko', 'event_code' => 'TK-05',
                    'lines' => [...$lines, self::K('41100', $revenue), self::D('51100', $hpp), self::K('11501', $hpp)]]];
            }
            $this->apply($key, $entries);
        }
        foreach ($keys as $stale) {
            $this->apply($stale, []); // gudang yang tidak lagi punya penjualan hari itu
        }
    }

    /**
     * Cara bayar satu transaksi -> [akun debit, jumlah, pegawai]. Kosong bila belum dibayar
     * (QRIS/payment gateway yang masih menunggu).
     *
     * @return list<array{0:string,1:int,2:?int}>
     */
    private function paymentParts(Transaction $t): array
    {
        $map = function (string $method, int $amount) use ($t): ?array {
            return match ($method) {
                'cash' => ['11104', $amount, null],
                PayrollReceivableService::POTONG_GAJI => ['11204', $amount, $t->customer_id],
                'pay_later', 'piutang', 'tempo' => ['11206', $amount, $t->customer_id],
                default => ['11102', $amount, null], // QRIS, transfer, kartu, payment gateway
            };
        };
        $voucher = $this->liabilityVoucher($t);
        $parts = [];
        if ($t->tenders->isNotEmpty()) {
            foreach ($t->tenders as $tender) {
                if ($tender->payment_status !== 'paid') {
                    continue;
                }
                $parts[] = $map($tender->method, (int) $tender->amount);
            }
        } elseif ($t->payment_status === 'paid') {
            $parts[] = $map($t->payment_method ?: 'cash', (int) $t->grand_total);
        } elseif ($t->payment_status === 'unpaid' && $t->receivable) {
            $acc = $t->receivable->source === PayrollReceivableService::POTONG_GAJI ? '11204' : '11206';
            $parts[] = [$acc, (int) $t->grand_total, $t->customer_id];
        }
        if ($parts && $voucher > 0) {
            $parts[] = ['21130', $voucher, null];
        }

        return array_values(array_filter($parts, fn ($p) => $p && $p[1] > 0));
    }

    /** Voucher SHU/HUT = hutang koperasi; saat dipakai belanja, penjualan tetap dicatat penuh. */
    private function liabilityVoucher(Transaction $t): int
    {
        $amount = (int) $t->customer_voucher_discount;
        $label = strtoupper(($t->customer_voucher_name ?? '').' '.($t->customer_voucher_code ?? ''));

        return $amount > 0 && preg_match('/\b(SHU|HUT)\b/', $label) ? $amount : 0;
    }

    public function syncGoodsReceiving(int $id): void
    {
        $gr = GoodsReceiving::with(['items.product:id,buy_price', 'items.purchaseOrderItem:id,unit_price'])->find($id);
        $key = "gr:{$id}";
        if (! $gr || ! self::enabledFor($gr->received_at)) {
            $gr ? null : $this->apply($key, []);

            return;
        }
        $total = $gr->items->sum(fn ($i) => (int) $i->qty_received * (float) ($i->purchaseOrderItem?->unit_price ?? $i->product?->buy_price ?? 0));
        $this->apply($key, [['date' => Carbon::parse($gr->received_at)->toDateString(), 'description' => 'Penerimaan barang '.($gr->document_number ?: "#{$gr->id}"),
            'division' => 'toko', 'event_code' => 'TK-03', 'lines' => [self::D('11501', $total), self::K('21111', $total)]]], $gr);
    }

    public function syncPayablePayment(int $id): void
    {
        $p = PayablePayment::with('payable:id,document_number')->find($id);
        $key = "pp:{$id}";
        if (! $p) {
            $this->apply($key, []);

            return;
        }
        if (! self::enabledFor($p->paid_at)) {
            return;
        }
        $cash = $p->method === 'cash' ? '11101' : '11102';
        $this->apply($key, [['date' => Carbon::parse($p->paid_at)->toDateString(), 'description' => 'Bayar hutang supplier '.($p->payable?->document_number ?? ''),
            'division' => 'toko', 'event_code' => 'TK-04', 'lines' => [self::D('21111', $p->amount), self::K($cash, $p->amount)]]], $p);
    }

    public function syncReceivablePayment(int $id): void
    {
        $p = ReceivablePayment::with('receivable:id,source,customer_id,invoice')->find($id);
        $key = "rp:{$id}";
        if (! $p) {
            $this->apply($key, []);

            return;
        }
        // Potong gaji dilunasi lewat rekap potongan, bukan jurnal terpisah.
        if (! self::enabledFor($p->paid_at) || $p->method === PayrollReceivableService::POTONG_GAJI) {
            return;
        }
        $acc = $p->receivable?->source === PayrollReceivableService::POTONG_GAJI ? '11204' : '11206';
        $cash = $p->method === 'cash' ? '11104' : '11102';
        $cust = $p->receivable?->customer_id;
        $this->apply($key, [['date' => Carbon::parse($p->paid_at)->toDateString(), 'description' => 'Pelunasan piutang '.($p->receivable?->invoice ?? ''),
            'division' => 'toko', 'event_code' => 'TK-21', 'lines' => [self::D($cash, $p->amount, $cust), self::K($acc, $p->amount, $cust)]]], $p);
    }

    public function syncReturn(int $id): void
    {
        $r = SalesReturn::with(['items.product:id,buy_price', 'transaction.receivable:id,transaction_id,source'])->find($id);
        $key = "ret:{$id}";
        if (! $r || $r->status !== 'completed') {
            $this->apply($key, []);

            return;
        }
        if (! self::enabledFor($r->completed_at)) {
            return;
        }
        $cost = $r->items->where('restock_to_inventory', true)->sum(fn ($i) => (int) $i->qty_return * (int) ($i->product?->buy_price ?? 0));
        $total = (int) $r->total_return_amount;
        $refund = (int) $r->refund_amount;
        $credit = (int) $r->credited_amount;
        // Sisa nilai retur di luar uang kembali & saldo kredit = mengurangi piutang transaksi (bayar belakangan).
        $receivable = $r->transaction?->receivable;
        $restAcc = $receivable ? ($receivable->source === PayrollReceivableService::POTONG_GAJI ? '11204' : '11206') : '11104';
        $this->apply($key, [['date' => Carbon::parse($r->completed_at)->toDateString(), 'description' => 'Retur penjualan '.$r->code, 'division' => 'toko', 'event_code' => 'TK-11',
            'lines' => [self::D('41109', $total), self::K('11104', $refund), self::K('21140', $credit, $r->customer_id),
                self::K($restAcc, $total - $refund - $credit, $receivable ? $r->customer_id : null), self::D('11501', $cost), self::K('51100', $cost)]]], $r);
    }

    public function syncOpname(int $id): void
    {
        $so = StockOpname::with('items.product:id,buy_price')->find($id);
        $key = "so:{$id}";
        if (! $so || $so->status !== 'finalized' || ! $so->finalized_at) {
            $this->apply($key, []);

            return;
        }
        if (! self::enabledFor($so->finalized_at)) {
            return;
        }
        $value = $so->items->sum(fn ($i) => (int) ($i->difference ?? 0) * (int) ($i->product?->buy_price ?? 0));
        $lines = $value < 0 ? [self::D('51105', -$value), self::K('11501', -$value)] : [self::D('11501', $value), self::K('51105', $value)];
        $this->apply($key, [['date' => Carbon::parse($so->finalized_at)->toDateString(), 'description' => 'Selisih stock opname '.$so->code, 'division' => 'toko',
            'event_code' => 'TK-14', 'lines' => $lines]], $so);
    }

    // ------------------------------------------------------------------ Pembiayaan

    public function syncFinancing(Financing $f): void
    {
        $f->loadMissing('customer:id,employee_type', 'installments');
        $loan = $this->loanAccount($f);
        $cust = $f->customer_id;
        $div = $f->type === 'barang' ? 'barang' : 'dana';
        $disbursed = in_array($f->status, Financing::DISBURSED_STATUSES, true) && $f->disbursed_at;

        // Pencairan + biaya formulir (dibayar di depan, tidak memotong transfer).
        if ($disbursed && self::enabledFor($f->disbursed_at)) {
            $date = Carbon::parse($f->disbursed_at)->toDateString();
            $entries = [];
            if ((int) $f->admin_fee > 0) {
                $entries[] = ['date' => $date, 'description' => "Biaya formulir {$f->number} (dibayar di depan)", 'division' => $div,
                    'event_code' => $div === 'barang' ? 'PB-01' : 'PD-01',
                    'lines' => [self::D('11102', $f->admin_fee, $cust), self::K($div === 'barang' ? '46103' : '46101', $f->admin_fee, $cust)]];
            }
            $entries[] = $f->type === 'barang'
                ? ['date' => $date, 'description' => "Realisasi pembiayaan barang {$f->number}", 'division' => 'barang', 'event_code' => 'PB-02',
                    'lines' => [self::D($loan, $f->total(), $cust), self::K('11102', $f->principal, $cust), self::K('11304', $f->margin_amount, $cust)]]
                : ['date' => $date, 'description' => "Pencairan pembiayaan dana {$f->number}", 'division' => 'dana', 'event_code' => 'PD-02',
                    'lines' => [self::D($loan, $f->principal, $cust), self::K('11102', $f->principal, $cust)]];
            $this->apply("fin:{$f->id}:cair", $entries, $f);
        } elseif (! $disbursed) {
            $this->apply("fin:{$f->id}:cair", []);
        }

        // Angsuran: transfer/pelunasan -> kas; potong gaji -> dilunasi lewat rekap. Barang: akui margin.
        $total = max(1, $f->total());
        foreach ($f->installments as $i) {
            $key = "inst:{$i->id}";
            if (! $i->paid_at || ! $disbursed) {
                $this->apply($key, []);

                continue;
            }
            if (! self::enabledFor($i->paid_at)) {
                continue;
            }
            $date = Carbon::parse($i->paid_at)->toDateString();
            $lines = [];
            if (! in_array($i->paid_source, ['potong_gaji', 'import'], true)) {
                $lines[] = self::D('11102', $i->amount, $cust);
                $lines[] = self::K($loan, $i->amount, $cust);
            }
            if ($f->type === 'barang' && $f->margin_amount > 0) {
                $margin = (int) round($f->margin_amount * $i->amount / $total);
                $lines[] = self::D('11304', $margin, $cust);
                $lines[] = self::K('46102', $margin, $cust);
            }
            $label = $i->paid_source === 'pelunasan' ? 'Pelunasan' : 'Angsuran ke-'.$i->seq;
            $this->apply($key, $lines ? [['date' => $date, 'description' => "{$label} {$f->number}", 'division' => $div,
                'event_code' => $div === 'barang' ? 'PB-04' : 'PD-05', 'lines' => $lines]] : [], $i);
        }

        // Tanda macet: cadangan sebesar sisa saat ditandai (tidak ikut berubah setiap hari).
        $badKey = "fin:{$f->id}:macet";
        if ($f->is_bad_debt && $disbursed) {
            if (! JournalEntry::where('auto_key', $badKey)->exists() && self::enabledFor(now())) {
                $rest = (int) $f->installments->whereNull('paid_at')->sum('amount') - ($f->type === 'barang'
                    ? (int) round($f->margin_amount * $f->installments->whereNull('paid_at')->sum('amount') / $total) : 0);
                $this->apply($badKey, $rest > 0 ? [['date' => now()->toDateString(), 'description' => "Cadangan pembiayaan bermasalah {$f->number}",
                    'division' => $div, 'event_code' => 'PD-06', 'lines' => [self::D('61109', $rest, $cust), self::K('11309', $rest, $cust)]]] : [], $f);
            }
        } else {
            $this->apply($badKey, []);
        }
    }

    // ------------------------------------------------------------------ Simpanan & Potongan

    public function syncRecap(string $period): void
    {
        if (! self::enabledFor($period.'-01')) {
            return;
        }
        $date = Carbon::parse($period.'-01')->endOfMonth()->toDateString();
        $entries = array_map(fn ($e) => $e + ['date' => $date, 'division' => 'simpanan', 'event_code' => 'SP-01'], $this->catalog->payrollRecapFor($period));
        $this->apply("rekap:{$period}", $entries);
    }

    /** Simpanan/angsuran yang dibayar langsung (tunai/transfer) di menu Simpanan Anggota. */
    public function syncContribution(int $id): void
    {
        $c = MemberContribution::with('customer:id,employee_type')->find($id);
        $key = "mc:{$id}";
        if (! $c || ! in_array($c->source, ['manual', 'tunai', 'transfer'], true)) {
            $this->apply($key, []);

            return;
        }
        if (! self::enabledFor($c->created_at)) {
            return;
        }
        $acc = match ($c->type) {
            MemberContribution::TYPE_POKOK => '31101',
            MemberContribution::TYPE_WAJIB => $c->customer?->employee_type === 'PPNPN' ? '31202' : '31201',
            MemberContribution::TYPE_SUKARELA => '31301',
            MemberContribution::TYPE_PINJAMAN => '31401',
            MemberContribution::TYPE_ANGSURAN => $c->customer?->employee_type === 'PPNPN' ? '11302' : '11301',
            MemberContribution::TYPE_PG => '11204',
            default => null,
        };
        if (! $acc) {
            return;
        }
        $cash = $c->source === 'tunai' ? '11101' : '11102';
        $amount = (int) $c->amount;
        $lines = $amount >= 0 ? [self::D($cash, $amount, $c->customer_id), self::K($acc, $amount, $c->customer_id)]
            : [self::D($acc, -$amount, $c->customer_id), self::K($cash, -$amount, $c->customer_id)];
        $label = (MemberContribution::TYPE_LABELS[$c->type] ?? $c->type).sprintf(' %04d-%02d', $c->period_year, $c->period_month);
        $this->apply($key, [['date' => $c->created_at->toDateString(), 'description' => ($amount >= 0 ? 'Setoran ' : 'Penarikan ').$label,
            'division' => 'simpanan', 'event_code' => $amount >= 0 ? 'SP-05' : 'SP-07', 'lines' => $lines]], $c);
    }

    public function syncShu(int $id): void
    {
        $shu = ShuPeriod::find($id);
        $key = "shu:{$id}";
        if (! $shu || $shu->status !== 'final' || ! $shu->finalized_at) {
            $this->apply($key, []);

            return;
        }
        if (! self::enabledFor($shu->finalized_at)) {
            return;
        }
        $this->apply($key, [['date' => Carbon::parse($shu->finalized_at)->toDateString(), 'description' => "Pembagian SHU {$shu->year} (voucher)",
            'division' => 'tutup', 'event_code' => 'TB-04', 'lines' => [self::D('33201', $shu->total_shu), self::K('21130', $shu->total_shu)]]], $shu);
    }
}
