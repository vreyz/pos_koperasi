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
 *  - Koreksi manual per orang (base pengganti, tambahan nominal) dipotong dari SHU dulu.
 *  - Sisa dibagi ke jasa simpanan / pembelian / pinjaman sesuai porsi, proporsional.
 */
class ShuCalculator
{
    public const PURCHASE_STATUSES = ['paid', 'unpaid'];

    /**
     * @return array{recipients: int, base_recipients: int, distributed: float}
     */
    public function calculate(ShuPeriod $period): array
    {
        if ($period->isFinal()) {
            throw new RuntimeException('SHU tahun ini sudah final. Buka kunci dulu untuk menghitung ulang.');
        }
        $portionTotal = $period->portion_savings + $period->portion_purchases + $period->portion_loans;
        if (abs($portionTotal - 100) > 0.001) {
            throw new RuntimeException('Total porsi simpanan + pembelian + pinjaman harus 100%.');
        }

        $data = $this->collect($period);
        $adjustments = $period->adjustments()->get()->keyBy('customer_id');
        $bases = $this->bases($period, $data, $adjustments);
        $adjustmentTotal = (int) $adjustments->sum('amount');

        $remainder = $period->total_shu - array_sum($bases) - $adjustmentTotal;
        if ($remainder < 0) {
            throw new RuntimeException('Total base SHU ('.$this->rupiah(array_sum($bases)).') + koreksi manual ('.$this->rupiah($adjustmentTotal).') melebihi SHU yang dibagi.');
        }

        $pools = [
            'savings' => $remainder * $period->portion_savings / 100,
            'purchases' => $remainder * $period->portion_purchases / 100,
            'loans' => $remainder * $period->portion_loans / 100,
        ];
        $sums = ['savings' => array_sum($data['savings']), 'purchases' => array_sum($data['purchases']), 'loans' => array_sum($data['loans'])];

        $ids = array_unique(array_merge(array_keys($bases), array_keys($data['savings']), array_keys($data['purchases']), array_keys($data['loans']), $adjustments->keys()->all()));
        $now = now();
        $rows = [];
        $distributed = 0.0;

        foreach ($ids as $customerId) {
            $amounts = [
                'savings' => $data['savings'][$customerId] ?? 0,
                'purchases' => $data['purchases'][$customerId] ?? 0,
                'loans' => $data['loans'][$customerId] ?? 0,
            ];
            $shares = [];
            foreach ($amounts as $key => $amount) {
                $shares[$key] = $sums[$key] > 0 ? $amount / $sums[$key] : 0;
            }
            $base = $bases[$customerId] ?? 0;
            $adjustment = $adjustments->get($customerId);
            $extra = (int) ($adjustment->amount ?? 0);
            $shuSavings = $shares['savings'] * $pools['savings'];
            $shuPurchases = $shares['purchases'] * $pools['purchases'];
            $shuLoans = $shares['loans'] * $pools['loans'];
            $total = $base + $shuSavings + $shuPurchases + $shuLoans + $extra;
            if ($total <= 0) {
                continue;
            }
            $distributed += $total;

            $rows[] = [
                'shu_period_id' => $period->id,
                'customer_id' => $customerId,
                'savings_amount' => $amounts['savings'],
                'purchase_amount' => $amounts['purchases'],
                'loan_amount' => $amounts['loans'],
                'months_paid' => $data['months'][$customerId] ?? 0,
                'savings_share' => $shares['savings'],
                'purchase_share' => $shares['purchases'],
                'loan_share' => $shares['loans'],
                'base_amount' => $base,
                'shu_savings' => round($shuSavings, 2),
                'shu_purchases' => round($shuPurchases, 2),
                'shu_loans' => round($shuLoans, 2),
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
     * @return array{active: int, payers: int, base_recipients: int, base_total: int, purchase_total: int, purchase_buyers: int, adjustment_total: int}
     */
    public function summary(ShuPeriod $period): array
    {
        $data = $this->collect($period);
        $adjustments = $period->adjustments()->get()->keyBy('customer_id');
        $bases = $this->bases($period, $data, $adjustments);

        return [
            'active' => count($data['active']),
            'payers' => count($data['months']),
            'base_recipients' => count(array_filter($bases)),
            'base_total' => array_sum($bases),
            'purchase_total' => array_sum($data['purchases']),
            'purchase_buyers' => count($data['purchases']),
            'adjustment_total' => (int) $adjustments->sum('amount'),
        ];
    }

    /**
     * @return array{active: array<int, ?string>, months: array<int, int>, savings: array<int, int>, purchases: array<int, int>, loans: array<int, int>}
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

        $loans = $ids === [] ? [] : MemberContribution::query()
            ->where('period_year', $year)
            ->where('type', MemberContribution::TYPE_ANGSURAN)
            ->whereIn('customer_id', $ids)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount) as total')
            ->pluck('total', 'customer_id')
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->all();

        $purchases = array_intersect_key($this->purchases($period, $excluded), $active);

        return compact('active', 'months', 'savings', 'purchases', 'loans');
    }

    /**
     * @return array<int, int> customer_id => base SHU
     */
    private function bases(ShuPeriod $period, array $data, $adjustments): array
    {
        $bases = [];
        $participants = $period->base_requires_savings
            ? array_keys($data['months'])
            : array_unique(array_merge(array_keys($data['months']), array_keys($data['purchases']), array_keys($data['loans'])));

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
