<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\Financing;
use App\Models\MemberContribution;
use App\Models\ShuPeriod;
use App\Models\User;
use App\Services\Cooperative\FinancingService;
use App\Services\Cooperative\ShuCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
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
            'portion_financing_dana' => 0,
            'portion_financing_barang' => 0,
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

    public function test_ppnpn_base_is_an_option_and_on_by_default(): void
    {
        $pns = $this->person('PNS');
        $ppnpn = $this->person('Kontrak', 'PPNPN');
        $this->pay($pns, 'wajib', 2025, [1], 100000);
        $this->pay($ppnpn, 'wajib', 2025, [1], 100000);

        $period = $this->period(['total_shu' => 1200000, 'portion_savings' => 100, 'portion_purchases' => 0]);
        $this->assertTrue($period->fresh()->base_for_ppnpn);
        app(ShuCalculator::class)->calculate($period->fresh());
        $alloc = $period->allocations()->get()->keyBy('customer_id');
        $this->assertSame(100000, $alloc[$ppnpn->id]->base_amount);
        $this->assertEqualsWithDelta(600000, $alloc[$ppnpn->id]->total, 0.01);

        $period->update(['base_for_ppnpn' => false]);
        $result = app(ShuCalculator::class)->calculate($period->fresh());
        $alloc = $period->allocations()->get()->keyBy('customer_id');
        $this->assertSame(1, $result['base_recipients']);
        $this->assertSame(0, $alloc[$ppnpn->id]->base_amount);
        $this->assertEqualsWithDelta(550000, $alloc[$ppnpn->id]->shu_savings, 0.01);
    }

    public function test_only_employees_active_in_the_year_participate(): void
    {
        $active = $this->person('Aktif');
        $mutasiMaret = $this->person('Mutasi Maret 2025', 'PNS', 'MUTASI');
        $mutasiMaret->update(['active_until' => '2025-03-31']);
        $keluar2024 = $this->person('Keluar Des 2024', 'PNS', 'MUTASI');
        $keluar2024->update(['active_until' => '2024-12-31']);
        foreach ([$active, $mutasiMaret, $keluar2024] as $c) {
            ExternalSale::create(['year' => 2025, 'buyer_name' => $c->name, 'customer_id' => $c->id, 'amount' => 100000]);
        }
        $this->pay($active, 'wajib', 2025, [1], 100000);

        $period = $this->period(['total_shu' => 400000, 'portion_savings' => 0, 'portion_purchases' => 100]);
        app(ShuCalculator::class)->calculate($period);
        $alloc = $period->allocations()->get()->keyBy('customer_id');

        $this->assertArrayNotHasKey($keluar2024->id, $alloc, 'belanja tercatat tapi sudah tidak aktif di 2025');
        $this->assertSame(0, $alloc[$mutasiMaret->id]->base_amount, 'belanja tanpa iuran: tanpa base');
        $this->assertEqualsWithDelta(150000, $alloc[$mutasiMaret->id]->total, 0.01);
        $this->assertEqualsWithDelta(250000, $alloc[$active->id]->total, 0.01);
    }

    public function test_flexible_base_modes_and_manual_adjustments(): void
    {
        $a = $this->person('A');
        $b = $this->person('B');
        $pengurus = $this->person('Pengurus');
        $this->pay($a, 'wajib', 2025, range(1, 12), 50000);
        $this->pay($b, 'wajib', 2025, [10, 11, 12], 50000);
        ExternalSale::create(['year' => 2025, 'buyer_name' => 'Pengurus', 'customer_id' => $pengurus->id, 'amount' => 10000]);

        $period = $this->period([
            'total_shu' => 1000000, 'base_per_person' => 120000, 'base_mode' => 'monthly',
            'portion_savings' => 100, 'portion_purchases' => 0, 'rounding' => 'ceil', 'rounding_unit' => 500,
        ]);
        $period->adjustments()->create(['customer_id' => $pengurus->id, 'amount' => 300000, 'note' => 'SHU pengurus koperasi']);
        $period->adjustments()->create(['customer_id' => $b->id, 'base_override' => 100000]);

        app(ShuCalculator::class)->calculate($period->fresh());
        $alloc = $period->allocations()->get()->keyBy('customer_id');

        $this->assertSame(120000, $alloc[$a->id]->base_amount);           // 12/12 bulan
        $this->assertSame(100000, $alloc[$b->id]->base_amount);           // pengganti manual (aturan: 3/12 = 30.000)
        $this->assertSame(300000, $alloc[$pengurus->id]->adjustment_amount);
        // sisa = 1.000.000 - 220.000 base - 300.000 koreksi = 480.000 → simpanan 600rb : 150rb
        $this->assertEqualsWithDelta(120000 + 384000, $alloc[$a->id]->total, 0.01);
        $this->assertSame(196000, $alloc[$b->id]->total_rounded);          // 196.000 tepat
        $this->assertEqualsWithDelta(1000000, $period->allocations()->sum('total'), 0.05);

        // Base untuk semua pegawai aktif yang ikut (tidak wajib iuran)
        $period->update(['base_requires_savings' => false, 'base_mode' => 'full', 'base_per_person' => 10000]);
        $period->adjustments()->delete();
        app(ShuCalculator::class)->calculate($period->fresh());
        $this->assertSame(10000, $period->allocations()->where('customer_id', $pengurus->id)->value('base_amount'));
    }

    public function test_board_bonus_is_prorated_and_deducted_first(): void
    {
        $a = $this->person('A');
        $ketua = $this->person('Ketua');
        $this->pay($a, 'wajib', 2025, [1], 100000);
        $this->pay($ketua, 'wajib', 2025, [1], 100000);

        $period = $this->period(['total_shu' => 1300000, 'base_per_person' => 0, 'portion_savings' => 100, 'portion_purchases' => 0,
            'board_amount' => 50000, 'board_members' => [['customer_id' => $ketua->id, 'months' => [7, 8, 9, 10, 11, 12]]]]);
        app(ShuCalculator::class)->calculate($period);
        $alloc = $period->allocations()->get()->keyBy('customer_id');

        $this->assertSame(300000, $alloc[$ketua->id]->board_amount);              // 50rb × 6 bulan (Jul–Des)
        $this->assertEqualsWithDelta(300000 + 500000, $alloc[$ketua->id]->total, 0.01); // sisa 1jt dibagi rata
        $this->assertEqualsWithDelta(500000, $alloc[$a->id]->total, 0.01);
        $this->assertSame(1, count(app(ShuCalculator::class)->summary($period)['buyers_without_dues']) + 1);

        // Data lama (jumlah bulan berupa angka) tetap terbaca sebagai Jan s.d. bulan ke-n.
        $this->assertSame([1, 2, 3], ShuCalculator::boardMonths(['months' => 3]));
    }

    public function test_non_employee_buyers_are_listed_separately(): void
    {
        $a = $this->person('A');
        $umum = Customer::create(['name' => 'Umum', 'no_telp' => 0, 'address' => '']); // pembeli umum (bukan pegawai)
        ExternalSale::create(['year' => 2025, 'buyer_name' => 'A', 'customer_id' => $a->id, 'amount' => 50000]);
        ExternalSale::create(['year' => 2025, 'buyer_name' => 'Umum', 'customer_id' => $umum->id, 'amount' => 70000]);
        ExternalSale::create(['year' => 2025, 'buyer_name' => 'Tukang Pijat', 'amount' => 20000]);
        $period = $this->period();

        $admin = User::factory()->create();
        $admin->givePermissionTo('shu-access');
        $this->actingAs($admin)->get(route('shu.show', $period))->assertInertia(fn (Assert $page) => $page
            ->where('nonEmployeeBuyers', [['name' => 'Umum', 'amount' => 70000], ['name' => 'Tukang Pijat', 'amount' => 20000]])
            ->where('buyersWithoutDues.0.customer_id', $a->id));
    }

    public function test_pos_source_and_financing_income_portions_ignore_installments(): void
    {
        $cashier = User::factory()->create();
        $a = $this->person('A');
        $b = $this->person('B');
        $this->pay($a, 'wajib', 2025, [1], 100000);
        $this->pay($b, 'wajib', 2025, [1], 100000);
        $this->pay($b, 'angsuran', 2025, [1, 2], 500000); // angsuran tidak dihitung di SHU

        $tx = fn (Customer $c, int $total, string $status, string $date) => DB::table('transactions')->insert([
            'cashier_id' => $cashier->id, 'customer_id' => $c->id, 'invoice' => uniqid('TRX'), 'cash' => $total, 'change' => 0,
            'discount' => 0, 'grand_total' => $total, 'payment_status' => $status, 'created_at' => $date, 'updated_at' => $date,
        ]);
        $tx($a, 300000, 'paid', '2025-03-01 10:00:00');
        $tx($a, 100000, 'unpaid', '2025-12-31 23:00:00');   // potong gaji / pay later tetap dihitung
        $tx($a, 999000, 'failed', '2025-05-01 10:00:00');
        $tx($b, 400000, 'paid', '2026-01-01 08:00:00');     // tahun berikutnya

        // Pembiayaan dana B: biaya formulir 750rb (cair 2025). Barang A: biaya lain + bagi hasil dari cicilan yang dibayar 2025.
        $service = app(FinancingService::class);
        $dana = Financing::create($service->compute(['type' => 'dana', 'principal' => 15000000, 'tenor' => 10], $b) + ['type' => 'dana', 'customer_id' => $b->id, 'status' => 'berjalan', 'disbursed_at' => '2025-02-10', 'first_due_month' => '2025-03-01']);
        $service->buildSchedule($dana, 3);
        $barang = Financing::create($service->compute(['type' => 'barang', 'principal' => 12000000, 'tenor' => 12, 'margin_percent' => 7.5, 'admin_fee' => 0], $a) + ['type' => 'barang', 'customer_id' => $a->id, 'status' => 'berjalan', 'disbursed_at' => '2025-06-10', 'first_due_month' => '2025-07-01']);
        $service->buildSchedule($barang, 3);  // 3 cicilan × bagi hasil 75.000 → batas 50% = 112.500 ≥ bagian barang 100rb

        $period = $this->period(['sales_source' => 'pos', 'total_shu' => 1200000, 'portion_savings' => 50, 'portion_purchases' => 30, 'portion_financing_dana' => 10, 'portion_financing_barang' => 10, 'rounding' => 'ceil']);
        app(ShuCalculator::class)->calculate($period);
        $alloc = $period->allocations()->get()->keyBy('customer_id');

        $this->assertSame(400000, $alloc[$a->id]->purchase_amount);
        $this->assertSame(750000, $alloc[$b->id]->financing_dana_amount);
        $this->assertSame(225000, $alloc[$a->id]->financing_barang_amount);
        // sisa 1jt: simpanan 500rb rata, belanja 300rb A, dana 100rb B, barang 100rb A
        $this->assertEqualsWithDelta(100000 + 250000 + 300000 + 100000, $alloc[$a->id]->total, 0.01);
        $this->assertEqualsWithDelta(100000 + 250000 + 100000, $alloc[$b->id]->total, 0.01);
        $this->assertSame(750000, $alloc[$a->id]->total_rounded);
    }

    public function test_financing_portion_cannot_exceed_half_of_financing_income(): void
    {
        // 3 pegawai pinjam dana 10 jt → biaya formulir 3 × 500rb = 1,5 jt → bagian SHU dana maks 750rb.
        $service = app(FinancingService::class);
        foreach (['A', 'B', 'C'] as $name) {
            $person = $this->person($name);
            $this->pay($person, 'wajib', 2025, [1], 100000);
            Financing::create($service->compute(['type' => 'dana', 'principal' => 10000000, 'tenor' => 10], $person)
                + ['type' => 'dana', 'customer_id' => $person->id, 'status' => 'berjalan', 'disbursed_at' => '2025-03-10', 'first_due_month' => '2025-04-01']);
        }
        // Sisa SHU = 10,3 jt − base 300rb = 10 jt.
        $period = $this->period(['total_shu' => 10300000, 'portion_savings' => 60, 'portion_purchases' => 30, 'portion_financing_dana' => 10]);
        $calculator = app(ShuCalculator::class);

        $this->assertSame(1500000, $calculator->summary($period)['financing_dana_income']);
        $errors = $calculator->financingCapErrors($period);
        $this->assertArrayHasKey('portion_financing_dana', $errors);
        $this->assertStringContainsString('Isi paling banyak 7,5%', $errors['portion_financing_dana']);
        $this->assertArrayNotHasKey('portion_financing_barang', $errors);
        try {
            $calculator->calculate($period);
            $this->fail('Bagian SHU dana 1 jt melebihi batas 750rb, seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('melebihi 50% pendapatan Pembiayaan Dana 2025', $e->getMessage());
        }
        $this->assertSame(0, $period->allocations()->count());

        // Tepat 50% (7,5% × 10 jt = 750rb) boleh.
        $period->update(['portion_savings' => 62.5, 'portion_financing_dana' => 7.5]);
        $this->assertSame([], $calculator->financingCapErrors($period->fresh()));
        $calculator->calculate($period->fresh());
        $this->assertEqualsWithDelta(750000, $period->allocations()->sum('shu_financing_dana'), 0.05);
    }

    public function test_saving_shu_settings_rejects_financing_portion_above_cap(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['shu-access', 'shu-manage']);
        $person = $this->person('A');
        $this->pay($person, 'wajib', 2025, [1], 100000);
        Financing::create(app(FinancingService::class)->compute(['type' => 'dana', 'principal' => 10000000, 'tenor' => 10], $person)
            + ['type' => 'dana', 'customer_id' => $person->id, 'status' => 'berjalan', 'disbursed_at' => '2025-03-10', 'first_due_month' => '2025-04-01']);
        $period = $this->period();
        $settings = [
            'total_shu' => 1100000, 'base_per_person' => 100000, 'base_requires_savings' => true, 'base_for_ppnpn' => true, 'base_mode' => 'full',
            'portion_savings' => 50, 'portion_purchases' => 30, 'portion_financing_dana' => 20, 'portion_financing_barang' => 0,
            'savings_types' => ['pokok', 'wajib', 'sukarela'], 'savings_period' => 'year', 'sales_source' => 'excel', 'rounding' => 'round', 'rounding_unit' => 1000,
        ];

        // Biaya formulir 500rb → batas 250rb. Sisa SHU 1 jt × 20% = 200rb → boleh.
        $this->actingAs($user)->put(route('shu.update', $period), $settings)->assertSessionHasNoErrors();
        // 30% = 300rb > 250rb → ditolak, pengaturan lama tetap.
        $this->actingAs($user)->put(route('shu.update', $period), ['portion_savings' => 40, 'portion_financing_dana' => 30] + $settings)
            ->assertSessionHasErrors('portion_financing_dana');
        $this->assertEquals(20, $period->fresh()->portion_financing_dana);
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
            'total_shu' => 1000000, 'base_per_person' => 100000, 'base_requires_savings' => true, 'base_for_ppnpn' => true, 'base_mode' => 'full',
            'portion_savings' => 70, 'portion_purchases' => 30, 'portion_financing_dana' => 0, 'portion_financing_barang' => 0,
            'savings_types' => ['pokok', 'wajib', 'sukarela'], 'savings_period' => 'year', 'sales_source' => 'excel', 'rounding' => 'round', 'rounding_unit' => 1000,
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
                ->where('stats.base_recipients', 1)
                ->where('stats.purchase_total', 15000)
                ->has('unmatched', 1)
                ->where('allocations.0.total_rounded', 1000000)
                ->has('rules'));

        $this->actingAs($user)->post(route('shu.adjustments.save', $period), ['customer_id' => $budi->id, 'amount' => 50000, 'note' => 'uji'])->assertSessionHasNoErrors();
        $this->assertSame(50000, $period->adjustments()->value('amount'));
        $this->actingAs($user)->post(route('shu.adjustments.save', $period), ['customer_id' => $budi->id, 'base_override' => null, 'amount' => 0]);
        $this->assertSame(0, $period->adjustments()->count());

        $this->actingAs($user)->post(route('shu.finalize', $period));
        $this->assertTrue($period->fresh()->isFinal());
        $this->actingAs($user)->post(route('shu.calculate', $period))->assertSessionHas('error');
        $this->actingAs($user)->get(route('shu.export', $period))->assertOk();
    }
}
