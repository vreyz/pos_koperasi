<?php

namespace Tests\Feature\Accounting;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\MemberContribution;
use App\Models\User;
use App\Services\Accounting\EventCatalog;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['accounting-access', 'accounting-create', 'accounting-manage']);

        return $user;
    }

    private function employee(string $name, string $type = 'PNS'): Customer
    {
        return Customer::create(['name' => $name, 'employee_type' => $type, 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    private function balanceOf(string $code, string $asOf = '2026-12-31'): int
    {
        return app(ReportService::class)->accountBalance($code, $asOf);
    }

    private function opening(): void
    {
        app(JournalService::class)->post(['date' => '2025-12-31', 'description' => 'Saldo awal', 'is_opening' => true], [
            ['account' => '11102', 'debit' => 1000000],
            ['account' => '11501', 'debit' => 500000],
            ['account' => '31201', 'credit' => 900000],
            ['account' => '33102', 'credit' => 600000],
        ]);
    }

    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(JournalService::class)->post(['date' => '2026-01-05', 'description' => 'Salah'], [
            ['account' => '11102', 'debit' => 1000], ['account' => '41100', 'credit' => 900],
        ]);
    }

    public function test_journal_numbers_are_sequential_per_month(): void
    {
        $svc = app(JournalService::class);
        $a = $svc->post(['date' => '2026-01-05', 'description' => 'A'], [['account' => '11102', 'debit' => 1], ['account' => '47102', 'credit' => 1]]);
        $b = $svc->post(['date' => '2026-01-06', 'description' => 'B'], [['account' => '11102', 'debit' => 1], ['account' => '47102', 'credit' => 1]]);
        $c = $svc->post(['date' => '2026-02-01', 'description' => 'C'], [['account' => '11102', 'debit' => 1], ['account' => '47102', 'credit' => 1]]);
        $this->assertSame(['JU-2601-0001', 'JU-2601-0002', 'JU-2602-0001'], [$a->number, $b->number, $c->number]);
    }

    public function test_kantin_sale_goes_to_advance_then_split_by_setting_percent(): void
    {
        $user = $this->user();
        $this->actingAs($user)->put(route('accounting.settings'), ['kantin_share_percent' => 12.5])->assertSessionHasNoErrors();
        $this->assertEquals(12.5, EventCatalog::kantinShare());

        $this->actingAs($user)->post(route('accounting.record.store'), [
            'event' => 'TK-15', 'date' => '2026-01-10', 'values' => ['qris' => 600000, 'tunai' => 200000, 'pg' => 200000, 'cash' => '11102'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, JournalEntry::where('event_code', 'TK-15')->count());
        $this->assertSame(0, $this->balanceOf('21112'));          // uang muka langsung habis dibagi
        $this->assertSame(-125000, $this->balanceOf('43101'));    // 12,5% x 1.000.000
        $this->assertSame(-875000, $this->balanceOf('21104'));    // hutang ke penjaga kantin

        $this->actingAs($user)->post(route('accounting.record.store'), [
            'event' => 'TK-16', 'date' => '2026-01-11', 'values' => ['amount' => 875000, 'cash' => '11102'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->balanceOf('21104'));
    }

    public function test_payroll_recap_is_one_journal_traceable_per_employee(): void
    {
        $user = $this->user();
        $budi = $this->employee('Budi');
        $sari = $this->employee('Sari', 'PPNPN');
        foreach ([[$budi, 'wajib', 100000], [$budi, 'pg', 50000], [$sari, 'wajib', 50000], [$sari, 'sukarela', 25000]] as [$c, $type, $amount]) {
            MemberContribution::create(['customer_id' => $c->id, 'type' => $type, 'period_year' => 2026, 'period_month' => 1, 'amount' => $amount, 'source' => 'import']);
        }

        $this->actingAs($user)->post(route('accounting.record.store'), [
            'event' => 'SP-01', 'date' => '2026-01-31', 'values' => ['period' => '2026-01', 'dkm' => 30000],
        ])->assertSessionHasNoErrors();

        $entry = JournalEntry::where('event_code', 'SP-01')->sole();
        $this->assertSame(255000, (int) $entry->lines()->sum('debit'));
        $this->assertSame(-100000, $this->balanceOf('31201'));
        $this->assertSame(-50000, $this->balanceOf('31202'));     // PPNPN ke akun wajib PPNPN
        $this->assertSame(-30000, $this->balanceOf('21121'));

        $ledger = app(ReportService::class)->ledger('11402', '2026-01-01', '2026-01-31');
        $people = collect($ledger['per_customer'])->pluck('balance', 'name')->all();
        $this->assertSame(['Budi' => 150000, 'Sari' => 75000], $people);

        // Sari gagal autodebet: piutangnya tetap terbuka.
        $this->actingAs($user)->post(route('accounting.record.store'), [
            'event' => 'SP-02', 'date' => '2026-02-05', 'values' => ['period' => '2026-01', 'failed' => [$sari->id], 'biaya' => 2500, 'cash' => '11102'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(75000, $this->balanceOf('11402'));
        $this->assertSame(180000 - 2500, $this->balanceOf('11102'));
        $sariLedger = app(ReportService::class)->ledger('11402', '2026-01-01', '2026-02-28', $sari->id);
        $this->assertSame(75000, $sariLedger['closing']);
    }

    public function test_reports_balance_and_reserve_flow(): void
    {
        $this->opening();
        $svc = app(JournalService::class);
        $cat = app(EventCatalog::class);
        foreach ([
            ['TB-03', '2026-01-01', []],
            ['TK-05', '2026-01-15', ['tunai' => 300000, 'qris' => 200000, 'hpp' => 400000, 'cash' => '11102']],
            ['TK-10', '2026-01-16', ['amount' => 300000, 'cash' => '11102']],
            ['UM-01', '2026-01-25', ['amount' => 50000, 'cash' => '11102']],
            ['TB-04', '2026-01-26', ['amount' => 100000, 'account' => '21130']],
        ] as [$code, $date, $values]) {
            foreach ($cat->build($code, $values, $date) as $e) {
                $svc->post(['date' => $date, 'description' => $e['description'], 'event_code' => $code], $e['lines']);
            }
        }
        $reports = app(ReportService::class);

        $tb = $reports->trialBalance('2026-01-01', '2026-01-31');
        $this->assertSame(0, $tb['totals']['closing']);

        $income = $reports->incomeStatement('2026-01-01', '2026-01-31');
        $this->assertSame(100000, $income['gross']['toko']);
        $this->assertSame(50000, $income['shu']['total']);

        $bs = $reports->balanceSheet('2026-01-31');
        $this->assertSame(0, $bs['totals']['selisih']);
        $lines = collect($bs['sections']['ekuitas'])->pluck('amount', 'line');
        $this->assertSame(500000, $lines['Dana Cadangan Tahun Lalu']);       // 600.000 pindah - 100.000 dibagi
        $this->assertSame(50000, $lines['Dana Cadangan Tahun Berjalan']);    // SHU Januari

        $this->assertSame(0, $reports->equityChanges('2026-01-01', '2026-01-31')['selisih']);
        $cash = $reports->cashFlow('2026-01-01', '2026-01-31');
        $this->assertSame(0, $cash['selisih']);
        $this->assertSame(450000, $cash['change']); // 300.000 tunai + 200.000 QRIS - 50.000 gaji

        $result = $reports->closeYear(2026);
        $this->assertSame(50000, $result['shu']);
        $this->assertSame(-50000, $this->balanceOf('33102'));
        $this->assertSame(0, $this->balanceOf('33201'));
        $this->assertSame(-500000, $this->balanceOf('33101'));
        $this->assertSame(0, $reports->balanceSheet('2026-12-31')['totals']['selisih']);
    }

    public function test_reverse_creates_opposite_entry_once(): void
    {
        $svc = app(JournalService::class);
        $e = $svc->post(['date' => '2026-01-05', 'description' => 'Gaji'], [['account' => '61101', 'debit' => 10], ['account' => '11102', 'credit' => 10]]);
        $svc->reverse($e);
        $this->assertSame(0, $this->balanceOf('61101'));
        $this->expectException(ValidationException::class);
        $svc->reverse($e->fresh());
    }

    public function test_pages_render(): void
    {
        $this->opening();
        $user = $this->user();
        foreach (['journals' => 'Journals', 'record' => 'Record', 'manual' => 'Manual', 'ledger' => 'Ledger',
            'trial-balance' => 'TrialBalance', 'reports' => 'Reports', 'accounts' => 'Accounts', 'opening' => 'Opening'] as $route => $page) {
            $this->actingAs($user)->get(route('accounting.'.$route, ['from' => '2026-01-01', 'to' => '2026-01-31']))
                ->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard/Accounting/'.$page, false));
        }
    }

    public function test_access_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())->get(route('accounting.journals'))->assertForbidden();
    }
}
