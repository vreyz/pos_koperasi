<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\Financing;
use App\Models\MemberContribution;
use App\Services\Accounting\EventCatalog;
use App\Services\Cooperative\FinancingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Potongan angsuran (realisasi): tunggakan terlama dulu, lalu cicilan berikutnya, tanggal = bulan potong. */
class PayrollAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Customer $andi;

    private Financing $loan;

    private FinancingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FinancingService::class);
        $this->andi = Customer::create(['name' => 'Andi', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        // Pinjaman dana 6 jt, 6 x 1 jt, cicilan Januari s.d. Juni 2027.
        $this->loan = Financing::create(['type' => 'dana', 'customer_id' => $this->andi->id, 'status' => 'berjalan', 'number' => 'FPD-1',
            'principal' => 6000000, 'margin_amount' => 0, 'admin_fee' => 0, 'tenor' => 6, 'installment' => 1000000,
            'disbursed_at' => '2026-12-15', 'first_due_month' => '2027-01-01']);
        $this->svc->buildSchedule($this->loan);
    }

    private function deduct(int $month, int $amount): void
    {
        MemberContribution::create(['customer_id' => $this->andi->id, 'type' => 'angsuran', 'period_year' => 2027, 'period_month' => $month, 'amount' => $amount, 'source' => 'import']);
    }

    /** @return array<int, ?string> seq => tanggal lunas */
    private function paidDates(): array
    {
        return $this->loan->installments()->get()->mapWithKeys(fn ($i) => [$i->seq => $i->paid_at?->toDateString()])->all();
    }

    public function test_failed_february_is_paid_by_double_deduction_in_march(): void
    {
        $this->deduct(1, 1000000);
        // Februari gagal autodebet: tidak ada potongan.
        $this->deduct(3, 2000000);
        $this->svc->allocatePayroll();

        $this->assertSame([1 => '2027-01-31', 2 => '2027-03-31', 3 => '2027-03-31', 4 => null, 5 => null, 6 => null], $this->paidDates());
        $this->assertSame('potong_gaji', $this->loan->installments()->where('seq', 2)->value('paid_source'));

        $rows = collect($this->svc->reconcile(2027))->keyBy('month');
        $this->assertSame('Dilunasi dari potongan bulan lain (tunggakan/bayar di muka)', $rows[2]['note']);
        $this->assertStringContainsString('tunggakan', $rows[3]['note']);

        // Jurnal rekap Maret: 2 jt mengurangi pinjaman (bukan dobel untuk Februari).
        $recap = app(EventCatalog::class)->payrollRecapFor('2027-03');
        $loanCredit = collect($recap[0]['lines'])->where('account', '11301')->sum('credit');
        $this->assertSame(2000000, $loanCredit);
    }

    public function test_extra_deduction_without_arrears_prepays_next_installment(): void
    {
        $this->deduct(1, 2000000); // dipotong dobel di Januari
        $this->deduct(2, 1000000);
        $this->svc->allocatePayroll();

        // Cicilan Feb sudah lunas di Januari (bayar di muka); potongan Feb melunasi cicilan Maret.
        $this->assertSame([1 => '2027-01-31', 2 => '2027-01-31', 3 => '2027-02-28', 4 => null, 5 => null, 6 => null], $this->paidDates());
    }

    public function test_partial_deduction_waits_and_excess_after_payoff_becomes_over_deduction(): void
    {
        $this->deduct(1, 600000);   // kurang
        $this->deduct(2, 1400000);  // menutup Januari + Februari
        foreach ([3, 4, 5, 6] as $m) {
            $this->deduct($m, 1000000);
        }
        $this->deduct(7, 1000000);  // pinjaman sudah lunas: lebih potong
        $this->assertSame(6, $this->svc->allocatePayroll());

        $dates = $this->paidDates();
        $this->assertSame('2027-02-28', $dates[1]);
        $this->assertSame('2027-02-28', $dates[2]);
        $this->assertSame('lunas', $this->loan->fresh()->status);

        $plan = $this->svc->payrollPlan($this->andi->id);
        $this->assertSame(1000000, $plan['excess']);
        $recap = app(EventCatalog::class)->payrollRecapFor('2027-07');
        $this->assertSame(1000000, collect($recap[0]['lines'])->where('account', '21110')->sum('credit'));
    }

    public function test_reallocation_follows_corrected_data(): void
    {
        $this->deduct(1, 2000000);
        $this->svc->allocatePayroll();
        $this->assertSame('2027-01-31', $this->paidDates()[2]);

        // Data Januari diperbaiki jadi 1 jt: cicilan Februari kembali belum lunas.
        MemberContribution::where('customer_id', $this->andi->id)->update(['amount' => 1000000]);
        $this->svc->allocatePayroll();
        $this->assertNull($this->paidDates()[2]);
        $this->assertSame('berjalan', $this->loan->fresh()->status);
    }

    public function test_old_deduction_never_pays_a_loan_disbursed_later(): void
    {
        MemberContribution::create(['customer_id' => $this->andi->id, 'type' => 'angsuran', 'period_year' => 2026, 'period_month' => 5, 'amount' => 1000000, 'source' => 'import']);
        $this->svc->allocatePayroll();
        $this->assertSame([1 => null, 2 => null, 3 => null, 4 => null, 5 => null, 6 => null], $this->paidDates());
        $this->assertSame(1000000, $this->svc->payrollPlan($this->andi->id)['excess']);
    }

    public function test_transfer_paid_installments_are_left_alone(): void
    {
        $this->svc->setPaid($this->loan->installments()->where('seq', 1)->first(), true, 'manual', '2027-01-10');
        $this->deduct(1, 1000000);
        $this->svc->allocatePayroll();

        $dates = $this->paidDates();
        $this->assertSame('2027-01-10', $dates[1]);    // transfer tetap
        $this->assertSame('2027-01-31', $dates[2]);    // potongan Januari jadi bayar di muka cicilan Februari
        $this->assertSame('manual', $this->loan->installments()->where('seq', 1)->value('paid_source'));
    }
}
