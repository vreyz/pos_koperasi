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
 * Perhitungan SHU tahun buku Y (dibagikan di Y+1).
 *
 *  - Base SHU: nominal sama untuk setiap pegawai non-PPNPN yang membayar
 *    simpanan (pokok/wajib/sukarela) di tahun Y. Pegawai mutasi/pensiun di tahun Y
 *    tetap dapat karena masih membayar; yang berhenti sebelum Y tidak.
 *  - Sisa (total SHU - total base) dibagi ke pos jasa simpanan, jasa pembelian,
 *    dan jasa pinjaman sesuai porsi (%).
 *  - Jasa simpanan: proporsional terhadap simpanan anggota yang membayar di Y.
 *  - Jasa pembelian: proporsional terhadap belanja pegawai di Y (termasuk PPNPN
 *    yang tidak membayar simpanan — mereka dapat jasa pembelian tanpa base).
 *  - Jasa pinjaman: proporsional terhadap angsuran pinjaman yang dibayar di Y.
 *  - Nama di daftar pengecualian tidak mendapat bagian apa pun.
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

        $year = $period->year;
        $excluded = array_map('intval', $period->excluded_customer_ids ?? []);
        $savingsTypes = array_values(array_intersect($period->savings_types ?: MemberContribution::SAVINGS_TYPES, MemberContribution::SAVINGS_TYPES));

        $payers = $this->payersInYear($year, $excluded);
        $savings = $this->savingsBasis($year, $savingsTypes, $period->savings_period, $this->contributionTotals($year, MemberContribution::SAVINGS_TYPES, $excluded));
        $purchases = $this->purchases($period, $excluded);
        $loans = $this->contributionTotals($year, [MemberContribution::TYPE_ANGSURAN], $excluded);

        $baseTotal = $period->base_per_person * count($payers);
        $remainder = $period->total_shu - $baseTotal;
        if ($remainder < 0) {
            throw new RuntimeException('Total base SHU ('.number_format($baseTotal, 0, ',', '.').') melebihi SHU yang dibagi.');
        }

        $pools = [
            'savings' => $remainder * $period->portion_savings / 100,
            'purchases' => $remainder * $period->portion_purchases / 100,
            'loans' => $remainder * $period->portion_loans / 100,
        ];
        $sums = ['savings' => array_sum($savings), 'purchases' => array_sum($purchases), 'loans' => array_sum($loans)];

        $ids = array_unique(array_merge(array_keys($payers), array_keys($savings), array_keys($purchases), array_keys($loans)));
        $now = now();
        $rows = [];
        $distributed = 0.0;

        foreach ($ids as $customerId) {
            $shares = [];
            $amounts = ['savings' => $savings[$customerId] ?? 0, 'purchases' => $purchases[$customerId] ?? 0, 'loans' => $loans[$customerId] ?? 0];
            foreach ($amounts as $key => $amount) {
                $shares[$key] = $sums[$key] > 0 ? $amount / $sums[$key] : 0;
            }
            $base = isset($payers[$customerId]) ? $period->base_per_person : 0;
            $shuSavings = $shares['savings'] * $pools['savings'];
            $shuPurchases = $shares['purchases'] * $pools['purchases'];
            $shuLoans = $shares['loans'] * $pools['loans'];
            $total = $base + $shuSavings + $shuPurchases + $shuLoans;
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
                'savings_share' => $shares['savings'],
                'purchase_share' => $shares['purchases'],
                'loan_share' => $shares['loans'],
                'base_amount' => $base,
                'shu_savings' => round($shuSavings, 2),
                'shu_purchases' => round($shuPurchases, 2),
                'shu_loans' => round($shuLoans, 2),
                'total' => round($total, 2),
                'total_rounded' => $this->round($total, $period->rounding),
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

        return ['recipients' => count($rows), 'base_recipients' => count($payers), 'distributed' => $distributed];
    }

    public function round(float $value, string $mode): int
    {
        return match ($mode) {
            'ceil' => (int) (ceil($value / 1000) * 1000),
            'floor' => (int) (floor($value / 1000) * 1000),
            'none' => (int) round($value),
            default => (int) (round($value / 1000) * 1000),
        };
    }

    /**
     * Orang yang membayar simpanan (pokok/wajib/sukarela) di tahun Y → berhak base SHU.
     *
     * @return array<int, true>
     */
    public function payersInYear(int $year, array $excluded = []): array
    {
        return MemberContribution::query()
            ->where('period_year', $year)
            ->whereIn('type', MemberContribution::SAVINGS_TYPES)
            ->where('amount', '>', 0)
            ->whereHas('customer', fn ($q) => $q->where(fn ($t) => $t->whereNull('employee_type')->orWhere('employee_type', '!=', 'PPNPN')))
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('customer_id', $excluded))
            ->distinct()
            ->pluck('customer_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * @param  array<int, true>  $payers
     * @return array<int, int>
     */
    private function savingsBasis(int $year, array $types, string $periodMode, array $payers): array
    {
        if ($payers === [] || $types === []) {
            return [];
        }

        return MemberContribution::query()
            ->whereIn('type', $types)
            ->whereIn('customer_id', array_keys($payers))
            ->when($periodMode === 'all_time', fn ($q) => $q->where('period_year', '<=', $year), fn ($q) => $q->where('period_year', $year))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount) as total')
            ->pluck('total', 'customer_id')
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function contributionTotals(int $year, array $types, array $excluded): array
    {
        return MemberContribution::query()
            ->where('period_year', $year)
            ->whereIn('type', $types)
            ->when($excluded !== [], fn ($q) => $q->whereNotIn('customer_id', $excluded))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount) as total')
            ->pluck('total', 'customer_id')
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->all();
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
