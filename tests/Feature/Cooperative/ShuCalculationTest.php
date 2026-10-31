<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\MemberContribution;
use App\Models\ShuPeriod;
use App\Models\User;
use App\Services\Cooperative\ShuCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShuCalculationTest extends TestCase
{
    use RefreshDatabase;
    use WritesSpreadsheets;

    private function person(string $name, string $type = 'PNS', string $status = 'AKTIF'): Customer
    {
        return Customer::create(['name' => $name, 'employee_type' => $type, 'employment_status' => $status, 'no_telp' => 0, 'address' => '']);
    }

    private function pay(Customer $c, string $type, int $year, array $months, int $amount): void
    {
        foreach ($months as $m) {
            MemberContribution::create(['customer_id' => $c->id, 'type' => $type, 'period_year' => $year, 'period_month' => $m, 'amount' => $amount]);
        }
    }

    private function period(array $overrides = []): ShuPeriod
    {
        return ShuPeriod::create($overrides + [
            'year' => 2025,
            'total_shu' => 10000000,
            'base_per_person' => 100000,
            'portion_savings' => 70,
            'portion_purchases' => 30,
            'portion_loans' => 0,
            'savings_types' => MemberContribution::SAVINGS_TYPES,
            'savings_period' => 'year',
            'sales_source' => 'excel',
            'rounding' => 'round',
            'excluded_customer_ids' => [],
        ]);
    }

    public function test_shu_follows_cooperative_rules(): void
    {
        $a = $this->person('A');
        $b = $this->person('B');
        $mutasiMaret = $this->person('C mutasi Maret 2025', 'PNS', 'MUTASI');
        $keluar2024 = $this->person('D mutasi Des 2024', 'PNS', 'MUTASI');
        $ppnpn = $this->person('E PPNPN', 'PPNPN');
        $excluded = $this->person('F dikecualikan');
        $umum = Customer::create(['name' => 'UMUM', 'no_telp' => 0, 'address' => '']);

        $this->pay($a, 'wajib', 2025, range(1, 12), 100000);                // 1.200.000
        $this->pay($b, 'wajib', 2025, range(1, 12), 100000);
        $this->pay($b, 'sukarela', 2025, [6], 400000);                      // 1.600.000
        $this->pay($mutasiMaret, 'wajib', 2025, [1, 2, 3], 100000);          // 300.000
        $this->pay($keluar2024, 'wajib', 2024, range(1, 12), 100000);        // tidak bayar di 2025
        $this->pay($excluded, 'wajib', 2025, range(1, 12), 100000);

        foreach ([[$a, 2000000], [$ppnpn, 1000000], [$excluded, 500000], [$umum, 900000]] as [$c, $amount]) {
            ExternalSale::create(['year' => 2025, 'buyer_name' => $c->name, 'customer_id' => $c->id, 'amount' => $amount]);
        }

        $period = $this->period(['excluded_customer_ids' => [$excluded->id]]);
        $result = app(ShuCalculator::class)->calculate($period);

        $this->assertSame(3, $result['base_recipients']); // A, B, C
        $alloc = $period->allocations()->get()->keyBy('customer_id');

        // base 3 × 100rb = 300rb; sisa 9,7jt → simpanan 6,79jt, pembelian 2,91jt
        $this->assertEqualsWithDelta(100000 + 6790000 * 1200000 / 3100000 + 2910000 * 2000000 / 3000000, $alloc[$a->id]->total, 0.01);
        $this->assertSame(4668000, $alloc[$a->id]->total_rounded);
        $this->assertSame(100000, $alloc[$mutasiMaret->id]->base_amount);
        $this->assertSame(0, $alloc[$ppnpn->id]->base_amount);
        $this->assertEqualsWithDelta(970000, $alloc[$ppnpn->id]->total, 0.01);
        $this->assertArrayNotHasKey($keluar2024->id, $alloc);
        $this->assertArrayNotHasKey($excluded->id, $alloc);
        $this->assertArrayNotHasKey($umum->id, $alloc);
        $this->assertEqualsWithDelta(10000000, $alloc->sum('total'), 0.05);
    }

    public function test_pos_source_uses_transactions_minus_failed_and_loans_portion(): void
    {
        $cashier = User::factory()->create();
        $a = $this->person('A');
        $b = $this->person('B');
        $this->pay($a, 'wajib', 2025, [1], 100000);
        $this->pay($b, 'wajib', 2025, [1], 100000);
        $this->pay($b, 'angsuran', 2025, [1, 2], 500000);

        $tx = fn (Customer $c, int $total, string $status, string $date) => DB::table('transactions')->insert([
            'cashier_id' => $cashier->id, 'customer_id' => $c->id, 'invoice' => uniqid('TRX'), 'cash' => $total, 'change' => 0,
            'discount' => 0, 'grand_total' => $total, 'payment_status' => $status, 'created_at' => $date, 'updated_at' => $date,
        ]);
        $tx($a, 300000, 'paid', '2025-03-01 10:00:00');
        $tx($a, 100000, 'unpaid', '2025-12-31 23:00:00');   // potong gaji / pay later tetap dihitung
        $tx($a, 999000, 'failed', '2025-05-01 10:00:00');
        $tx($b, 400000, 'paid', '2026-01-01 08:00:00');     // tahun berikutnya

        $period = $this->period(['sales_source' => 'pos', 'total_shu' => 1200000, 'portion_savings' => 50, 'portion_purchases' => 30, 'portion_loans' => 20, 'rounding' => 'ceil']);
        app(ShuCalculator::class)->calculate($period);
        $alloc = $period->allocations()->get()->keyBy('customer_id');

        $this->assertSame(400000, $alloc[$a->id]->purchase_amount);
        $this->assertSame(0, $alloc[$b->id]->purchase_amount);
        // sisa 1jt: simpanan 500rb dibagi rata, pembelian 300rb semua A, pinjaman 200rb semua B
        $this->assertEqualsWithDelta(100000 + 250000 + 300000, $alloc[$a->id]->total, 0.01);
        $this->assertEqualsWithDelta(100000 + 250000 + 200000, $alloc[$b->id]->total, 0.01);
        $this->assertSame(650000, $alloc[$a->id]->total_rounded);
    }

    public function test_shu_pages_excel_import_and_name_mapping(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['shu-access', 'shu-manage', 'shu-finalize']);
        $budi = $this->person('Budi Santoso');
        $this->pay($budi, 'wajib', 2025, [1], 100000);

        $this->actingAs($user)->post(route('shu.store'), ['year' => 2025])->assertRedirect();
        $period = ShuPeriod::firstWhere('year', 2025);
        $this->actingAs($user)->put(route('shu.update', $period), [
            'total_shu' => 1000000, 'base_per_person' => 100000, 'portion_savings' => 70, 'portion_purchases' => 30, 'portion_loans' => 0,
            'savings_types' => ['pokok', 'wajib', 'sukarela'], 'savings_period' => 'year', 'sales_source' => 'excel', 'rounding' => 'round',
        ])->assertSessionHasNoErrors();

        $path = $this->xlsx([
            ['File', 'TGL BELANJA', 'NAMA PEGAWAI', 'Nama Final', 'BARANG YANG DIBELI', 'JUMLAH BARANG', 'HARGA BARANG', 'TOTAL HARGA'],
            ['x', 45658, 'BUDI', '', 'NASI RAMES', 1, 15000, 15000],
            ['x', 45658, 'BUDI', '', null, 1, null, null], // baris subtotal
            ['x', 45659, 'UMUM', '', 'KOPI', 2, 5000, 10000],
        ], 'PENJUALAN (2)');
        $this->actingAs($user)->post(route('shu.sales-import', $period), ['file' => $this->upload($path)])->assertSessionHas('success');
        $this->assertSame(25000, (int) ExternalSale::where('year', 2025)->sum('amount'));

        $this->actingAs($user)->post(route('shu.sales-map', $period), ['buyer_name' => 'BUDI', 'customer_id' => $budi->id]);
        $this->actingAs($user)->post(route('shu.calculate', $period))->assertSessionHas('success');

        $this->actingAs($user)->get(route('shu.show', $period))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Cooperative/Shu/Show')
                ->where('stats.payers', 1)
                ->where('stats.purchase_total', 15000)
                ->has('unmatched', 1)
                ->where('allocations.0.total_rounded', 1000000)
                ->has('rules'));

        $this->actingAs($user)->post(route('shu.finalize', $period));
        $this->assertTrue($period->fresh()->isFinal());
        $this->actingAs($user)->post(route('shu.calculate', $period))->assertSessionHas('error');
        $this->actingAs($user)->get(route('shu.export', $period))->assertOk();
    }
}
