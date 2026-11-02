<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\Financing;
use App\Models\FinancingInstallment;
use App\Models\MemberContribution;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pembiayaan Dana: pinjaman tanpa bagi hasil, pendapatan koperasi = biaya formulir.
 * Pembiayaan Barang: harga + bagi hasil dicicil, pendapatan = bagi hasil + biaya lain.
 */
class FinancingService
{
    /** Langkah persetujuan: status asal → [status tujuan, kolom pelaku, izin]. */
    public const STEPS = [
        'check' => ['from' => 'pengajuan', 'to' => 'diperiksa', 'column' => 'checked', 'permission' => 'financing-check', 'label' => 'Diperiksa (Divisi Pembiayaan)'],
        'review' => ['from' => 'diperiksa', 'to' => 'direview', 'column' => 'reviewed', 'permission' => 'financing-review', 'label' => 'Direview & disetujui (Kepala Unit Usaha)'],
        'approve' => ['from' => 'direview', 'to' => 'disetujui', 'column' => 'approved', 'permission' => 'financing-approve', 'label' => 'Disetujui (Ketua Koperasi)'],
    ];

    /**
     * Lengkapi angka turunan: biaya, bagi hasil, cicilan per bulan.
     */
    public function compute(array $data, Customer $customer): array
    {
        $principal = (int) $data['principal'];
        $tenor = max(1, (int) $data['tenor']);

        if ($data['type'] === 'dana') {
            $data['margin_percent'] = 0;
            $data['margin_amount'] = 0;
            $data['admin_fee'] = array_key_exists('admin_fee', $data) && $data['admin_fee'] !== null && $data['admin_fee'] !== ''
                ? (int) $data['admin_fee']
                : FinancingSettings::danaFee($principal, $customer->employee_type);
        } else {
            $margin = (float) ($data['margin_percent'] ?? FinancingSettings::get('financing_margin_barang'));
            $data['margin_percent'] = $margin;
            $data['margin_amount'] = (int) round($principal * $margin / 100);
            if (! isset($data['admin_fee']) || $data['admin_fee'] === '') {
                $data['admin_fee'] = (int) round($principal * (float) FinancingSettings::get('financing_admin_barang') / 100);
            }
        }
        $data['tenor'] = $tenor;
        $data['installment'] = (int) ceil(($principal + $data['margin_amount']) / $tenor);

        return $data;
    }

    /**
     * @return array<string, string> pesan error per kolom
     */
    public function validateRules(array $data, Customer $customer): array
    {
        $errors = [];
        if ($data['type'] === 'dana') {
            $max = FinancingSettings::maxDana($customer->employee_type);
            if ($data['principal'] > $max) {
                $errors['principal'] = 'Plafon pembiayaan dana untuk '.($customer->employee_type === 'PPNPN' ? 'PPNPN' : 'pegawai').' maksimal Rp '.number_format($max, 0, ',', '.').'.';
            }
            $maxTenor = (int) FinancingSettings::get('financing_tenor_dana_max');
            if ($data['tenor'] > $maxTenor) {
                $errors['tenor'] = "Jangka waktu pembiayaan dana maksimal {$maxTenor} bulan.";
            }
        }

        return $errors;
    }

    /**
     * Nomor formulir otomatis: nomor_urut/KOP/FPD|FPB/BULAN_ROMAWI/TAHUN (mis. 01/KOP/FPD/X/2025).
     * Nomor urut per jenis per tahun, melanjutkan nomor terbesar yang sudah ada (termasuk hasil impor).
     */
    public function nextNumber(string $type, ?string $date = null): string
    {
        $date = $date ? Carbon::parse($date) : now();
        $code = $type === 'barang' ? 'FPB' : 'FPD';
        $year = $date->year;
        $last = Financing::where('type', $type)->where('number', 'like', "%/{$code}/%{$year}")->pluck('number')
            ->map(fn ($n) => (int) strtok((string) $n, '/'))->max() ?? 0;
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$date->month - 1];

        return sprintf('%02d/KOP/%s/%s/%d', $last + 1, $code, $roman, $year);
    }

    public function advance(Financing $financing, string $step, int $userId): void
    {
        $rule = self::STEPS[$step] ?? throw new RuntimeException('Langkah tidak dikenal.');
        if ($financing->status !== $rule['from']) {
            throw new RuntimeException('Status saat ini "'.Financing::STATUS_LABELS[$financing->status].'", tidak bisa '.$rule['label'].'.');
        }
        $financing->update([
            'status' => $rule['to'],
            "{$rule['column']}_by" => $userId,
            "{$rule['column']}_at" => now(),
        ]);
    }

    public function reject(Financing $financing, string $reason, int $userId): void
    {
        if (! in_array($financing->status, ['pengajuan', 'diperiksa', 'direview', 'disetujui'], true)) {
            throw new RuntimeException('Pembiayaan yang sudah dicairkan tidak bisa ditolak.');
        }
        $financing->update(['status' => 'ditolak', 'rejection_reason' => $reason, 'rejected_by' => $userId, 'rejected_at' => now()]);
    }

    /**
     * Cairkan dana: buat jadwal cicilan mulai bulan berikutnya (atau bulan yang dipilih).
     */
    public function disburse(Financing $financing, string $date, ?int $transferred, ?string $firstDueMonth = null): void
    {
        if ($financing->status !== 'disetujui') {
            throw new RuntimeException('Hanya pembiayaan yang sudah disetujui ketua yang bisa dicairkan.');
        }
        DB::transaction(function () use ($financing, $date, $transferred, $firstDueMonth) {
            $disbursed = Carbon::parse($date);
            $first = $firstDueMonth ? Carbon::parse($firstDueMonth)->startOfMonth() : $disbursed->copy()->startOfMonth()->addMonth();
            $financing->update([
                'status' => 'berjalan',
                'disbursed_at' => $disbursed->toDateString(),
                'first_due_month' => $first->toDateString(),
                'transferred_amount' => $transferred ?? ($financing->type === 'barang' ? $financing->principal - $financing->admin_fee : $financing->principal),
            ]);
            $this->buildSchedule($financing->fresh());
        });
    }

    public function buildSchedule(Financing $financing, int $paidCount = 0, string $paidSource = 'import'): void
    {
        $financing->installments()->delete();
        $total = $financing->total();
        $first = Carbon::parse($financing->first_due_month ?? $financing->disbursed_at)->startOfMonth();
        $remaining = $total;
        $now = now();
        $rows = [];
        for ($seq = 1; $seq <= $financing->tenor; $seq++) {
            $amount = $seq === $financing->tenor ? $remaining : min($financing->installment, $remaining);
            $remaining -= $amount;
            $due = $first->copy()->addMonthsNoOverflow($seq - 1);
            $rows[] = [
                'financing_id' => $financing->id,
                'seq' => $seq,
                'due_month' => $due->toDateString(),
                'amount' => $amount,
                'paid_at' => $seq <= $paidCount ? $due->toDateString() : null,
                'paid_source' => $seq <= $paidCount ? $paidSource : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        FinancingInstallment::insert($rows);
        $this->refreshStatus($financing);
    }

    public function setPaid(FinancingInstallment $installment, bool $paid, string $source = 'manual', ?string $date = null): void
    {
        $installment->update([
            'paid_at' => $paid ? ($date ?? now()->toDateString()) : null,
            'paid_source' => $paid ? $source : null,
        ]);
        $this->refreshStatus($installment->financing);
    }

    /** Pelunasan dipercepat: semua sisa cicilan ditandai lunas hari ini. */
    public function payOff(Financing $financing, ?string $note = null): int
    {
        $count = $financing->installments()->whereNull('paid_at')->update([
            'paid_at' => now()->toDateString(),
            'paid_source' => 'pelunasan',
            'note' => $note,
        ]);
        $this->refreshStatus($financing);

        return $count;
    }

    public function refreshStatus(Financing $financing): void
    {
        if (! $financing->isDisbursed()) {
            return;
        }
        $open = $financing->installments()->whereNull('paid_at')->exists();
        $financing->update(['status' => $open ? 'berjalan' : 'lunas']);
    }

    public function outstanding(Financing $financing): int
    {
        return (int) $financing->installments()->whereNull('paid_at')->sum('amount');
    }

    /**
     * Pendapatan koperasi per pegawai di tahun Y — dasar jasa pembiayaan di SHU.
     * Dana: biaya formulir saat dicairkan. Barang: biaya lain saat dicairkan + bagi hasil
     * dari cicilan yang dibayar di tahun Y (cicilan macet tidak menghasilkan bagi hasil).
     *
     * @return array<int, int> customer_id => pendapatan
     */
    public function incomeByCustomer(string $type, int $year, array $customerIds = []): array
    {
        $income = [];
        $disbursed = Financing::query()
            ->where('type', $type)
            ->whereIn('status', Financing::DISBURSED_STATUSES)
            ->when($customerIds !== [], fn ($q) => $q->whereIn('customer_id', $customerIds))
            ->get(['id', 'customer_id', 'admin_fee', 'margin_amount', 'tenor', 'disbursed_at']);

        foreach ($disbursed as $financing) {
            if ($financing->disbursed_at?->year === $year) {
                $income[$financing->customer_id] = ($income[$financing->customer_id] ?? 0) + $financing->admin_fee;
            }
        }

        if ($type === 'barang') {
            $byId = $disbursed->keyBy('id');
            FinancingInstallment::query()
                ->whereIn('financing_id', $byId->keys())
                ->whereNotNull('paid_at')
                ->whereYear('paid_at', $year)
                ->get(['financing_id'])
                ->each(function ($installment) use ($byId, &$income) {
                    $financing = $byId[$installment->financing_id];
                    $share = (int) round($financing->margin_amount / max(1, $financing->tenor));
                    $income[$financing->customer_id] = ($income[$financing->customer_id] ?? 0) + $share;
                });
        }

        return array_filter($income, fn ($v) => $v > 0);
    }

    /**
     * Bandingkan jadwal cicilan vs potongan gaji "Angsuran Koperasi" per pegawai per bulan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reconcile(int $year): array
    {
        $rows = [];
        $installments = FinancingInstallment::query()
            ->join('financings', 'financings.id', '=', 'financing_installments.financing_id')
            ->whereIn('financings.status', Financing::DISBURSED_STATUSES)
            ->whereYear('financing_installments.due_month', $year)
            // Cicilan yang dilunasi sekaligus (pelunasan dipercepat / dipotong dari pencairan baru) tidak diharapkan muncul di potongan gaji.
            ->where(fn ($q) => $q->whereNull('financing_installments.paid_source')->orWhere('financing_installments.paid_source', '!=', 'pelunasan'))
            ->get(['financings.customer_id', 'financings.type', 'financing_installments.due_month', 'financing_installments.amount', 'financing_installments.paid_at', 'financing_installments.paid_source']);
        foreach ($installments as $i) {
            $key = $i->customer_id.'|'.(int) Carbon::parse($i->due_month)->month;
            $rows[$key] ??= ['customer_id' => $i->customer_id, 'month' => (int) Carbon::parse($i->due_month)->month, 'scheduled' => 0, 'marked_paid' => 0, 'paid_elsewhere' => 0, 'deducted' => 0, 'types' => []];
            $rows[$key]['scheduled'] += $i->amount;
            $rows[$key]['marked_paid'] += $i->paid_at ? $i->amount : 0;
            if ($i->paid_at && $i->paid_source === 'potong_gaji' && Carbon::parse($i->paid_at)->format('Y-m') !== Carbon::parse($i->due_month)->format('Y-m')) {
                $rows[$key]['paid_elsewhere'] += $i->amount;
            }
            $rows[$key]['types'][$i->type] = true;
        }

        MemberContribution::query()
            ->where('type', MemberContribution::TYPE_ANGSURAN)
            ->where('period_year', $year)
            ->groupBy('customer_id', 'period_month')
            ->selectRaw('customer_id, period_month, SUM(amount) as total')
            ->get()
            ->each(function ($c) use (&$rows) {
                $key = $c->customer_id.'|'.(int) $c->period_month;
                $rows[$key] ??= ['customer_id' => (int) $c->customer_id, 'month' => (int) $c->period_month, 'scheduled' => 0, 'marked_paid' => 0, 'paid_elsewhere' => 0, 'deducted' => 0, 'types' => []];
                $rows[$key]['deducted'] += (int) $c->total;
            });

        $people = Customer::whereIn('id', array_unique(array_column($rows, 'customer_id')))->get(['id', 'name', 'nip', 'employee_type'])->keyBy('id');

        return collect($rows)->map(function (array $row) use ($people) {
            $person = $people->get($row['customer_id']);
            $diff = $row['deducted'] - $row['scheduled'];
            $row['name'] = $person?->name;
            $row['nip'] = $person?->nip;
            $row['employee_type'] = $person?->employee_type;
            $row['types'] = array_keys($row['types']);
            $row['difference'] = $row['deducted'] === 0 && $person?->employee_type === 'PPNPN' ? 0 : $diff;
            $row['note'] = match (true) {
                $row['deducted'] === 0 && $row['employee_type'] === 'PPNPN' => 'PPNPN: dibayar di luar potongan gaji (cek bukti transfer)',
                $row['scheduled'] > 0 && $row['paid_elsewhere'] >= $row['scheduled'] => 'Dilunasi dari potongan bulan lain (tunggakan/bayar di muka)',
                $row['scheduled'] === 0 && $row['deducted'] > 0 => 'Ada potongan gaji, tidak ada jadwal cicilan bulan ini: dipakai untuk tunggakan/cicilan berikutnya; bila tidak punya pembiayaan, cek pencatatan',
                $row['deducted'] === 0 && $row['marked_paid'] > 0 => 'Tercatat lunas di monitoring, tapi tidak ada potongan gaji',
                $row['deducted'] === 0 => 'Belum dipotong',
                $diff < 0 => 'Potongan kurang dari jadwal',
                $diff > 0 => 'Potongan lebih dari jadwal: dipakai untuk tunggakan, lalu cicilan berikutnya',
                $row['marked_paid'] < $row['scheduled'] => 'Sudah dipotong, belum ditandai lunas',
                default => 'Cocok',
            };

            return $row;
        })->sortBy([['name', 'asc'], ['month', 'asc']])->values()->all();
    }

    /** Sumber lunas yang ditentukan dari potongan gaji (boleh dihitung ulang). */
    public const PAYROLL_SOURCES = ['potong_gaji'];

    /** Cicilan lunas lewat impor data pembiayaan: dianggap dibayar dari potongan gaji bulan jatuh temponya. */
    private const FIXED_PAYROLL_SOURCES = ['import'];

    private array $planCache = [];

    /**
     * Rencana alokasi potongan angsuran (realisasi) satu pegawai. Uang potongan setiap bulan
     * mengisi cicilan berurutan menurut jatuh tempo: tunggakan terlama dulu, lalu cicilan bulan itu,
     * lalu cicilan berikutnya (bayar di muka). Cicilan yang terisi penuh dianggap lunas pada akhir
     * bulan potongan (tanggal riil), bukan tanggal jatuh tempo. Sisa setelah semua cicilan lunas =
     * kelebihan potong. Cicilan yang dilunasi lewat transfer/pelunasan tidak menyerap potongan.
     *
     * @return array{paid: array<int, string>, split: array<string, array<string, int>>, excess: int}
     *   paid: installment_id => tanggal lunas; split: 'Y-m' => [jenis (dana|barang|lebih) => jumlah]
     */
    public function payrollPlan(int $customerId): array
    {
        if (isset($this->planCache[$customerId])) {
            return $this->planCache[$customerId];
        }
        $deductions = MemberContribution::query()
            ->where('customer_id', $customerId)->where('type', MemberContribution::TYPE_ANGSURAN)
            ->groupBy('period_year', 'period_month')
            ->selectRaw('period_year, period_month, SUM(amount) as total')
            ->orderBy('period_year')->orderBy('period_month')->get();

        $queue = FinancingInstallment::query()
            ->join('financings', 'financings.id', '=', 'financing_installments.financing_id')
            ->where('financings.customer_id', $customerId)
            ->whereIn('financings.status', Financing::DISBURSED_STATUSES)
            ->where(fn ($q) => $q->whereNull('financing_installments.paid_at')
                ->orWhereIn('financing_installments.paid_source', [...self::PAYROLL_SOURCES, ...self::FIXED_PAYROLL_SOURCES]))
            ->orderBy('financing_installments.due_month')->orderBy('financings.id')->orderBy('financing_installments.seq')
            ->get(['financing_installments.id', 'financing_installments.due_month', 'financing_installments.amount',
                'financing_installments.paid_source', 'financings.type', 'financings.disbursed_at'])
            ->map(fn ($i) => ['id' => $i->id, 'due' => Carbon::parse($i->due_month)->format('Y-m'), 'amount' => (int) $i->amount, 'type' => $i->type,
                'since' => $i->disbursed_at ? Carbon::parse($i->disbursed_at)->format('Y-m') : '0000-00',
                'fixed' => in_array($i->paid_source, self::FIXED_PAYROLL_SOURCES, true), 'filled' => 0])
            ->all();

        $paid = [];
        $split = [];
        $excess = 0;
        foreach ($deductions as $d) {
            $period = sprintf('%04d-%02d', $d->period_year, $d->period_month);
            $money = (int) $d->total;
            if ($money <= 0) {
                continue;
            }
            $date = Carbon::parse($period.'-01')->endOfMonth()->toDateString();
            foreach ($queue as &$item) {
                if ($money <= 0) {
                    break;
                }
                $open = $item['amount'] - $item['filled'];
                // Cicilan yang sudah lunas dari impor data pembiayaan hanya menyerap potongan sejak bulan jatuh temponya.
                // Uang potongan hanya untuk pinjaman yang sudah cair saat bulan potong.
                if ($open <= 0 || $item['since'] > $period || ($item['fixed'] && $item['due'] > $period)) {
                    continue;
                }
                $take = min($open, $money);
                $item['filled'] += $take;
                $money -= $take;
                $split[$period][$item['type']] = ($split[$period][$item['type']] ?? 0) + $take;
                if (! $item['fixed'] && $item['filled'] >= $item['amount']) {
                    $paid[$item['id']] = $date;
                }
            }
            unset($item);
            if ($money > 0) {
                $split[$period]['lebih'] = ($split[$period]['lebih'] ?? 0) + $money;
                $excess += $money;
            }
        }

        return $this->planCache[$customerId] = ['paid' => $paid, 'split' => $split, 'excess' => $excess];
    }

    /**
     * Tandai lunas/belum cicilan sesuai alokasi potongan gaji (lihat payrollPlan). Aman diulang:
     * hasilnya selalu sama dengan data potongan terbaru.
     *
     * @param  list<int>|null  $customerIds
     * @return int jumlah cicilan yang baru ditandai lunas
     */
    public function allocatePayroll(?array $customerIds = null): int
    {
        $this->planCache = [];
        $customers = $customerIds ?? MemberContribution::where('type', MemberContribution::TYPE_ANGSURAN)->distinct()->pluck('customer_id')
            ->merge(FinancingInstallment::whereIn('paid_source', self::PAYROLL_SOURCES)->join('financings', 'financings.id', '=', 'financing_installments.financing_id')->pluck('financings.customer_id'))
            ->unique()->values()->all();
        $count = 0;
        foreach ($customers as $customerId) {
            $plan = $this->payrollPlan((int) $customerId);
            DB::transaction(function () use ($customerId, $plan, &$count) {
                $touched = [];
                $current = FinancingInstallment::query()
                    ->join('financings', 'financings.id', '=', 'financing_installments.financing_id')
                    ->where('financings.customer_id', $customerId)
                    ->whereIn('financings.status', Financing::DISBURSED_STATUSES)
                    ->where(fn ($q) => $q->whereNull('financing_installments.paid_at')->orWhereIn('financing_installments.paid_source', self::PAYROLL_SOURCES))
                    ->get(['financing_installments.*']);
                foreach ($current as $i) {
                    $want = $plan['paid'][$i->id] ?? null;
                    $have = $i->paid_at ? $i->paid_at->toDateString() : null;
                    if ($want === $have) {
                        continue;
                    }
                    $i->update(['paid_at' => $want, 'paid_source' => $want ? 'potong_gaji' : null]);
                    $touched[$i->financing_id] = true;
                    $count += ($want && ! $have) ? 1 : 0;
                }
                foreach (array_keys($touched) as $fid) {
                    $this->refreshStatus(Financing::find($fid));
                }
            });
        }
        $this->planCache = [];

        return $count;
    }

    /**
     * Dipanggil setelah impor potongan. Tidak lagi per bulan: kelebihan potongan melunasi tunggakan
     * atau cicilan berikutnya, kekurangan menunggu potongan bulan berikutnya.
     */
    public function markPaidFromPayroll(int $year): int
    {
        return $this->allocatePayroll();
    }
}
