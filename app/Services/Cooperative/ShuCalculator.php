<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\MemberContribution;
use App\Models\SalesReturn;
use App\Models\ShuAllocation;
use App\Models\ShuPeriod;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Perhitungan SHU tahun buku Y (dibagikan di Y+1). Semua aturan bisa diatur per periode.
 *
 *  - Hanya pegawai yang aktif di tahun Y (masa aktif mencakup Y) yang ikut. Non-pegawai
 *    tidak dapat walau belanja. Pengecualian tidak dapat apa pun.
 *  - Base SHU: untuk pembayar simpanan di Y (atau semua pegawai aktif yang ikut bila
 *    `base_requires_savings` dimatikan). PPNPN ikut bila `base_for_ppnpn`. Mode `monthly`
 *    memberi base × (bulan membayar / 12).
 *  - SHU Pengurus Koperasi (nominal per tahun × bulan bertugas ÷ 12) dan koreksi manual per orang
 *    dipotong dari SHU dulu.
 *  - Sisa dibagi ke jasa simpanan / pembelian / pendapatan pembiayaan sesuai porsi.
 *    Pendapatan pembiayaan = biaya formulir pembiayaan dana + bagi hasil & biaya lain pembiayaan
 *    barang per pegawai (bukan cicilan/angsuran). Bagian SHU pendapatan pembiayaan (dalam rupiah)
 *    paling banyak 30% dari pendapatan pembiayaan koperasi di tahun Y, supaya tidak membagi lebih dari yang didapat.
 */
class ShuCalculator
{
    public const PURCHASE_STATUSES = ['paid', 'unpaid'];

    /** Batas bagian SHU pendapatan pembiayaan terhadap pendapatan pembiayaan koperasi. */
    public const FINANCING_SHU_MAX_SHARE = 0.3;

    public function __construct(private readonly FinancingService $financings) {}

    /**
     * @return array{recipients: int, base_recipients: int, distributed: float}
     */
    public function calculate(ShuPeriod $period): array
    {
        if ($period->isFinal()) {
            throw new RuntimeException('SHU tahun ini sudah final. Buka kunci dulu untuk menghitung ulang.');
        }
        $portionTotal = $period->portion_savings + $period->portion_purchases + $period->portion_financing;
        if (abs($portionTotal - 100) > 0.001) {
            throw new RuntimeException('Total porsi simpanan + belanja + pendapatan pembiayaan harus 100%.');
        }

        $data = $this->collect($period);
        $adjustments = $period->adjustments()->get()->keyBy('customer_id');
        $bases = $this->bases($period, $data, $adjustments);
        $board = $this->boardBonuses($period);
        $adjustmentTotal = (int) $adjustments->sum('amount');

        $remainder = $period->total_shu - array_sum($bases) - array_sum($board) - $adjustmentTotal;
        if ($remainder < 0) {
            throw new RuntimeException('SHU dasar ('.$this->rupiah(array_sum($bases)).') + SHU pengurus koperasi ('.$this->rupiah(array_sum($board)).') + koreksi manual ('.$this->rupiah($adjustmentTotal).') melebihi SHU yang dibagi.');
        }
        if ($capErrors = $this->financingCapErrors($period, $remainder)) {
            throw new RuntimeException(implode(' ', $capErrors));
        }

        $components = ['savings' => 'portion_savings', 'purchases' => 'portion_purchases', 'financing' => 'portion_financing'];
        $pools = [];
        $sums = [];
        foreach ($components as $key => $portion) {
            $pools[$key] = $remainder * $period->{$portion} / 100;
            $sums[$key] = array_sum($data[$key]);
        }

        $ids = array_unique(array_merge(array_keys($bases), array_keys($data['savings']), array_keys($data['purchases']), array_keys($data['financing']), array_keys($board), $adjustments->keys()->all()));
        $now = now();
        $rows = [];
        $distributed = 0.0;

        foreach ($ids as $customerId) {
            $amounts = [];
            $shares = [];
            $shu = [];
            foreach (array_keys($components) as $key) {
                $amounts[$key] = $data[$key][$customerId] ?? 0;
                $shares[$key] = $sums[$key] > 0 ? $amounts[$key] / $sums[$key] : 0;
                $shu[$key] = $shares[$key] * $pools[$key];
            }
            $base = $bases[$customerId] ?? 0;
            $adjustment = $adjustments->get($customerId);
            $extra = (int) ($adjustment->amount ?? 0);
            $boardAmount = $board[$customerId] ?? 0;
            $total = $base + $boardAmount + array_sum($shu) + $extra;
            if ($total <= 0) {
                continue;
            }
            $distributed += $total;

            $rows[] = [
                'shu_period_id' => $period->id,
                'customer_id' => $customerId,
                'savings_amount' => $amounts['savings'],
                'purchase_amount' => $amounts['purchases'],
                'financing_dana_amount' => $data['dana'][$customerId] ?? 0,
                'financing_barang_amount' => $data['barang'][$customerId] ?? 0,
                'financing_amount' => $amounts['financing'],
                'months_paid' => $data['months'][$customerId] ?? 0,
                'savings_share' => $shares['savings'],
                'purchase_share' => $shares['purchases'],
                'financing_share' => $shares['financing'],
                'base_amount' => $base,
                'board_amount' => $boardAmount,
                'shu_savings' => round($shu['savings'], 2),
                'shu_purchases' => round($shu['purchases'], 2),
                'shu_financing' => round($shu['financing'], 2),
                'adjustment_amount' => $extra,
                'adjustment_note' => $adjustment?->note,
                'total' => round($total, 2),
                'total_rounded' => $this->round($total, $period->rounding, $period->rounding_unit ?: 1000),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($period, $rows, $now) {
            $period->allocations()->delete();
            foreach (array_chunk($rows, 300) as $chunk) {
                ShuAllocation::insert($chunk);
            }
            $period->update(['calculated_at' => $now]);
        });

        return ['recipients' => count($rows), 'base_recipients' => count(array_filter($bases)), 'distributed' => $distributed];
    }

    public function round(float $value, string $mode, int $unit = 1000): int
    {
        $unit = max(1, $unit);

        return match ($mode) {
            'ceil' => (int) (ceil($value / $unit) * $unit),
            'floor' => (int) (floor($value / $unit) * $unit),
            'none' => (int) round($value),
            default => (int) (round($value / $unit) * $unit),
        };
    }

    /**
     * Ringkasan sebelum dihitung: siapa saja yang ikut dan berapa base-nya.
     *
     * @return array<string, mixed>
     */
    public function summary(ShuPeriod $period): array
    {
        $data = $this->collect($period);
        $adjustments = $period->adjustments()->get()->keyBy('customer_id');
        $bases = $this->bases($period, $data, $adjustments);
        $payers = $data['months'];

        return [
            'active' => count($data['active']),
            'payers' => count($data['months']),
            'base_recipients' => count(array_filter($bases)),
            'base_total' => array_sum($bases),
            'purchase_total' => array_sum($data['purchases']),
            'purchase_buyers' => count($data['purchases']),
            'financing_total' => array_sum($data['financing']),
            // Pendapatan koperasi dari semua pembiayaan di tahun Y (dasar batas 30% bagian SHU pendapatan pembiayaan).
            'financing_dana_income' => $danaIncome = $this->financingIncome('dana', $period->year),
            'financing_barang_income' => $barangIncome = $this->financingIncome('barang', $period->year),
            'financing_income' => $danaIncome + $barangIncome,
            'financing_max_share' => self::FINANCING_SHU_MAX_SHARE,
            'adjustment_total' => (int) $adjustments->sum('amount'),
            'board_total' => array_sum($this->boardBonuses($period)),
            // Pegawai aktif yang belanja di tahun Y tapi tidak tercatat simpanan di tahun Y (bahan pertimbangan pengecualian).
            'buyers_without_dues' => collect($data['purchases'])->diffKeys($payers)->map(fn ($amount, $id) => ['customer_id' => $id, 'amount' => $amount])->values()->all(),
        ];
    }

    /**
     * Bagian SHU pendapatan pembiayaan (rupiah) yang melebihi 30% pendapatan pembiayaan koperasi (dana + barang).
     * Tanpa $remainder, sisa SHU dihitung dari pengaturan periode (dipakai saat menyimpan pengaturan).
     *
     * @return array<string, string> kolom porsi => pesan
     */
    public function financingCapErrors(ShuPeriod $period, ?float $remainder = null): array
    {
        if ($remainder === null) {
            $summary = $this->summary($period);
            $remainder = $period->total_shu - $summary['base_total'] - $summary['board_total'] - $summary['adjustment_total'];
        }
        $remainder = max(0.0, $remainder);

        $pool = $remainder * $period->portion_financing / 100;
        if ($pool <= 0) {
            return [];
        }
        $income = $this->financingIncome('dana', $period->year) + $this->financingIncome('barang', $period->year);
        $cap = $income * self::FINANCING_SHU_MAX_SHARE;
        if ($pool <= $cap + 0.5) {
            return [];
        }
        $maxPercent = $remainder > 0 ? floor($cap / $remainder * 10000) / 100 : 0;

        return ['portion_financing' => "Bagian SHU Pendapatan Pembiayaan {$period->portion_financing}% = {$this->rupiah((int) round($pool))} melebihi ".(self::FINANCING_SHU_MAX_SHARE * 100)."% pendapatan pembiayaan {$period->year} "
            ."(biaya formulir + bagi hasil + biaya lain {$this->rupiah($income)}, maksimal {$this->rupiah((int) floor($cap))}). Isi paling banyak ".str_replace('.', ',', (string) $maxPercent).'%.'];
    }

    private function financingIncome(string $type, int $year): int
    {
        return array_sum($this->financings->incomeByCustomer($type, $year));
    }

    /**
     * @return array{active: array<int, ?string>, months: array<int, int>, savings: array<int, int>, purchases: array<int, int>, dana: array<int, int>, barang: array<int, int>, financing: array<int, int>}
     */
    private function collect(ShuPeriod $period): array
    {
        $year = $period->year;
        $excluded = array_map('intval', $period->excluded_customer_ids ?? []);
        $active = Customer::employees()
            ->activeInYear($year)
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded))
            ->pluck('employee_type', 'id')
            ->all();
        $ids = array_keys($active);
        $savingsTypes = array_values(array_intersect($period->savings_types ?: MemberContribution::SAVINGS_TYPES, MemberContribution::SAVINGS_TYPES));

        $months = $ids === [] ? [] : MemberContribution::query()
            ->where('period_year', $year)
            ->whereIn('type', MemberContribution::SAVINGS_TYPES)
            ->where('amount', '>', 0)
            ->whereIn('customer_id', $ids)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(DISTINCT period_month) as months')
            ->pluck('months', 'customer_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $savings = ($months === [] || $savingsTypes === []) ? [] : MemberContribution::query()
            ->whereIn('type', $savingsTypes)
            ->whereIn('customer_id', array_keys($months))
            ->when($period->savings_period === 'all_time', fn ($q) => $q->where('period_year', '<=', $year), fn ($q) => $q->where('period_year', $year))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount) as total')
            ->pluck('total', 'customer_id')
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->all();

        $dana = $ids === [] ? [] : $this->financings->incomeByCustomer('dana', $year, $ids);
        $barang = $ids === [] ? [] : $this->financings->incomeByCustomer('barang', $year, $ids);

        $purchases = array_intersect_key($this->purchases($period, $excluded), $active);

        // Pendapatan pembiayaan per pegawai = dana (biaya formulir) + barang (bagi hasil + biaya lain).
        $financing = $dana;
        foreach ($barang as $id => $amount) {
            $financing[$id] = ($financing[$id] ?? 0) + $amount;
        }

        return compact('active', 'months', 'savings', 'purchases', 'dana', 'barang', 'financing');
    }

    /**
     * @return array<int, int> customer_id => base SHU
     */
    private function bases(ShuPeriod $period, array $data, $adjustments): array
    {
        $bases = [];
        $participants = $period->base_requires_savings
            ? array_keys($data['months'])
            : array_unique(array_merge(array_keys($data['months']), array_keys($data['purchases']), array_keys($data['financing'])));

        foreach ($participants as $customerId) {
            if (! $period->base_for_ppnpn && ($data['active'][$customerId] ?? null) === 'PPNPN') {
                continue;
            }
            $bases[$customerId] = $period->base_mode === 'monthly'
                ? (int) round($period->base_per_person * min($data['months'][$customerId] ?? 0, 12) / 12)
                : $period->base_per_person;
        }
        foreach ($adjustments as $customerId => $adjustment) {
            if ($adjustment->base_override !== null) {
                $bases[$customerId] = (int) $adjustment->base_override;
            }
        }

        return array_filter($bases, fn ($v) => $v > 0);
    }

    /**
     * @return array<int, int> customer_id => SHU pengurus koperasi
     */
    public function boardBonuses(ShuPeriod $period): array
    {
        $bonuses = [];
        foreach ($period->board_members ?? [] as $member) {
            $id = (int) ($member['customer_id'] ?? 0);
            $months = count(self::boardMonths($member));
            if ($id && $period->board_amount > 0 && $months > 0) {
                $bonuses[$id] = ($bonuses[$id] ?? 0) + $period->board_amount * $months;
            }
        }

        return $bonuses;
    }

    /**
     * Bulan bertugas pengurus (1-12). Data lama berupa jumlah bulan dianggap Januari s.d. bulan ke-n.
     *
     * @return list<int>
     */
    public static function boardMonths(array $member): array
    {
        $months = $member['months'] ?? [];
        if (! is_array($months)) {
            $months = range(1, max(1, min(12, (int) $months)));
        }

        $months = array_values(array_unique(array_filter(array_map('intval', $months), fn ($m) => $m >= 1 && $m <= 12)));
        sort($months);

        return $months;
    }

    private function rupiah(int $value): string
    {
        return 'Rp '.number_format($value, 0, ',', '.');
    }

    /**
     * Belanja pegawai di tahun Y dari POS (dikurangi retur) atau dari Excel.
     *
     * @return array<int, int>
     */
    public function purchases(ShuPeriod $period, array $excluded = []): array
    {
        $employeeIds = Customer::employees()
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('id', $excluded))
            ->pluck('id');
        if ($employeeIds->isEmpty()) {
            return [];
        }

        if ($period->sales_source === 'excel') {
            $totals = ExternalSale::query()
                ->where('year', $period->year)
                ->whereIn('customer_id', $employeeIds)
                ->groupBy('customer_id')
                ->selectRaw('customer_id, SUM(amount) as total')
                ->pluck('total', 'customer_id');
        } else {
            $start = sprintf('%04d-01-01 00:00:00', $period->year);
            $end = sprintf('%04d-01-01 00:00:00', $period->year + 1);
            $totals = Transaction::query()
                ->whereIn('customer_id', $employeeIds)
                ->whereIn('payment_status', self::PURCHASE_STATUSES)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->groupBy('customer_id')
                ->selectRaw('customer_id, SUM(grand_total) as total')
                ->pluck('total', 'customer_id');
            $returns = SalesReturn::query()
                ->whereIn('customer_id', $employeeIds)
                ->where('status', 'completed')
                ->where('completed_at', '>=', $start)
                ->where('completed_at', '<', $end)
                ->groupBy('customer_id')
                ->selectRaw('customer_id, SUM(total_return_amount) as total')
                ->pluck('total', 'customer_id');
            $totals = $totals->map(fn ($v, $id) => $v - ($returns[$id] ?? 0));
        }

        return $totals->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->all();
    }
}
