<?php

namespace Tests\Feature\Cooperative;

use App\Models\BoardDecree;
use App\Models\Customer;
use App\Models\Financing;
use App\Models\MemberContribution;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cooperative\FinancingService;
use App\Services\Cooperative\FinancingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancingTest extends TestCase
{
    use RefreshDatabase;
    use WritesSpreadsheets;

    private function person(string $name, string $type = 'PNS'): Customer
    {
        return Customer::create(['name' => $name, 'employee_type' => $type, 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    private function user(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_merge(['financing-access'], $permissions));

        return $user;
    }

    public function test_fees_limits_and_installments_follow_the_forms(): void
    {
        $service = app(FinancingService::class);
        $pns = $this->person('Budi');
        $ppnpn = $this->person('Slamet', 'PPNPN');

        $this->assertSame(750000, $service->compute(['type' => 'dana', 'principal' => 15000000, 'tenor' => 10], $pns)['admin_fee']);
        $this->assertSame(300000, $service->compute(['type' => 'dana', 'principal' => 10000000, 'tenor' => 10], $ppnpn)['admin_fee']);
        $this->assertSame(1000000, $service->compute(['type' => 'dana', 'principal' => 10000000, 'tenor' => 10], $ppnpn)['installment']);

        $barang = $service->compute(['type' => 'barang', 'principal' => 9900000, 'tenor' => 12], $pns);
        $this->assertSame(742500, $barang['margin_amount']);
        $this->assertSame(148500, $barang['admin_fee']);
        $this->assertSame(886875, $barang['installment']);

        $creator = $this->user(['financing-create']);
        $this->actingAs($creator)->post(route('financing.store', 'dana'), ['customer_id' => $ppnpn->id, 'principal' => 15000000, 'tenor' => 10])
            ->assertSessionHasErrors('principal');
        $this->actingAs($creator)->post(route('financing.store', 'dana'), ['customer_id' => $pns->id, 'principal' => 15000000, 'tenor' => 13])
            ->assertSessionHasErrors('tenor'); // bawaan & maksimal 12 bulan
    }

    public function test_settings_are_separate_per_type_and_coop_identity_comes_from_profile_and_sk(): void
    {
        $chair = $this->person('Ketua Contoh');
        $decree = BoardDecree::create(['number' => 'SK-1', 'effective_from' => now()->subYear()->toDateString()]);
        $decree->members()->create(['customer_id' => $chair->id, 'position' => 'Ketua']);
        Setting::set('store_name', 'Koperasi Contoh');
        Setting::set('store_city', 'Kota Contoh');
        $admin = $this->user(['financing-approve']);

        // Simpan aturan dana: aturan barang tidak ikut berubah, dan field barang ditolak diam-diam.
        $this->actingAs($admin)->put(route('financing.settings', 'dana'), [
            'financing_fee_pegawai' => 300000, 'financing_fee_ppnpn' => 150000, 'financing_max_pegawai' => 15000000,
            'financing_max_ppnpn' => 10000000, 'financing_tenor_dana_max' => 12, 'financing_margin_barang' => 99,
        ])->assertSessionHasNoErrors();
        $this->assertSame(300000, FinancingSettings::get('financing_fee_pegawai'));
        $this->assertSame(7.5, FinancingSettings::get('financing_margin_barang'));
        $this->assertSame(12, FinancingSettings::get('financing_tenor_barang'));

        $this->actingAs($admin)->get(route('financing.blank-form', 'dana'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('coop.name', 'Koperasi Contoh')->where('coop.city', 'Kota Contoh')->where('coop.chair', 'Ketua Contoh')
                ->missing('settings.coop_name')->missing('settings.financing_fee_unit'));
    }

    public function test_approval_flow_with_permissions_then_disbursement_schedule_and_payoff(): void
    {
        $budi = $this->person('Budi');
        $creator = $this->user(['financing-create']);
        $checker = $this->user(['financing-check']);
        $reviewer = $this->user(['financing-review']);
        $chair = $this->user(['financing-approve']);
        $treasurer = $this->user(['financing-disburse']);

        $this->actingAs($creator)->post(route('financing.store', 'dana'), [
            'customer_id' => $budi->id, 'principal' => 10000000, 'tenor' => 10, 'bank_name' => 'BSI', 'bank_account' => '123',
        ])->assertRedirect();
        $f = Financing::firstOrFail();
        $this->assertSame('pengajuan', $f->status);
        $this->assertSame(500000, $f->admin_fee);

        // Urutan wajib dan izin per tahap.
        $this->actingAs($chair)->post(route('financing.advance', [$f, 'approve']))->assertSessionHas('error');
        $this->actingAs($creator)->post(route('financing.advance', [$f, 'check']))->assertForbidden();
        $this->actingAs($checker)->post(route('financing.advance', [$f, 'check']))->assertSessionHas('success');
        $this->actingAs($reviewer)->post(route('financing.advance', [$f, 'review']))->assertSessionHas('success');
        $this->actingAs($chair)->post(route('financing.advance', [$f, 'approve']))->assertSessionHas('success');
        $f->refresh();
        $this->assertSame('disetujui', $f->status);
        $this->assertSame($chair->id, $f->approved_by);

        $this->actingAs($treasurer)->post(route('financing.disburse', $f), ['disbursed_at' => '2026-03-15'])->assertSessionHas('success');
        $f->refresh()->load('installments');
        $this->assertSame('berjalan', $f->status);
        $this->assertCount(10, $f->installments);
        $this->assertSame('2026-04-01', $f->installments->first()->due_month->toDateString());
        $this->assertSame(10000000, $f->installments->sum('amount'));

        $this->actingAs($treasurer)->post(route('financing.installments.toggle', $f->installments->first()));
        $this->actingAs($treasurer)->post(route('financing.payoff', $f))->assertSessionHas('success');
        $this->assertSame('lunas', $f->fresh()->status);

        foreach (['formulir', 'pencairan'] as $doc) {
            $this->actingAs($creator)->get(route('financing.print', [$f, $doc]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Cooperative/Financing/Print')->where('doc', $doc));
        }
        $this->actingAs($creator)->get(route('financing.blank-form', ['type' => 'dana', 'ppnpn' => 1]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ppnpn', true)->where('settings.financing_fee_ppnpn', 150000));
    }

    public function test_rejection_requires_reason_and_blocks_disbursement(): void
    {
        $f = Financing::create(app(FinancingService::class)->compute(['type' => 'dana', 'principal' => 5000000, 'tenor' => 5], $this->person('X')) + ['type' => 'dana', 'customer_id' => Customer::first()->id, 'status' => 'pengajuan']);
        $checker = $this->user(['financing-check', 'financing-disburse']);

        $this->actingAs($checker)->post(route('financing.reject', $f), [])->assertSessionHasErrors('reason');
        $this->actingAs($checker)->post(route('financing.reject', $f), ['reason' => 'Masih ada tunggakan'])->assertSessionHas('success');
        $this->assertSame('ditolak', $f->fresh()->status);
        $this->actingAs($checker)->post(route('financing.disburse', $f), ['disbursed_at' => '2026-01-01'])->assertSessionHas('error');
    }

    public function test_reconcile_schedule_against_payroll_deductions(): void
    {
        $service = app(FinancingService::class);
        $budi = $this->person('Budi');
        $citra = $this->person('Citra');
        $f = Financing::create($service->compute(['type' => 'dana', 'principal' => 5000000, 'tenor' => 5], $budi) + ['type' => 'dana', 'customer_id' => $budi->id, 'status' => 'berjalan', 'disbursed_at' => '2025-12-10', 'first_due_month' => '2026-01-01']);
        $service->buildSchedule($f, 0);
        foreach ([[1, 1000000], [2, 500000]] as [$month, $amount]) {
            MemberContribution::create(['customer_id' => $budi->id, 'type' => 'angsuran', 'period_year' => 2026, 'period_month' => $month, 'amount' => $amount]);
        }
        MemberContribution::create(['customer_id' => $citra->id, 'type' => 'angsuran', 'period_year' => 2026, 'period_month' => 1, 'amount' => 750000]);

        $rows = collect($service->reconcile(2026))->keyBy(fn ($r) => $r['name'].'-'.$r['month']);
        $this->assertSame('Sudah dipotong, belum ditandai lunas', $rows['Budi-1']['note']);
        $this->assertSame(-500000, $rows['Budi-2']['difference']);
        $this->assertSame('Belum dipotong', $rows['Budi-3']['note']);
        $this->assertStringContainsString('tidak ada jadwal', $rows['Citra-1']['note']);

        $this->assertSame(1, $service->markPaidFromPayroll(2026));
        $this->assertSame('potong_gaji', $f->installments()->where('seq', 1)->value('paid_source'));

        $user = $this->user([]);
        $this->actingAs($user)->get(route('financing.reconcile', ['year' => 2026]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Cooperative/Financing/Reconcile')->has('rows', 6));
    }

    public function test_import_contract_lists_disbursement_fees_and_goods_without_duplicates(): void
    {
        $nano = $this->person('Nano');
        $cung = $this->person('Cungcun', 'PPNPN');
        $safi = $this->person('Safianty Anwar');
        $user = $this->user(['financing-import']);

        $path = $this->xlsx([
            [null, 'DATA PINJAMAN ANGGOTA KOPERASI (PEGAWAI)'],
            [null, 'No', 'Nama Pemohon', 'STATUS', 'Tgl Pengiriman Dana ke rek pemohon', 'Nominal Pembiayaan', 'Angsuran tiap bulan (10 bulan tenor)', 'Dana yang di TF', 'Tanggal di TF', 'Angsuran 1', 'Angsuran 2', 'Angsuran 3', 'Angsuran 4', 'Angsuran 5', 'Angsuran 6', 'Angsuran 7', 'Angsuran 8', 'Angsuran 9', 'Angsuran 10', 'Jumlah Angsuran', 'Sisa Utang'],
            [null, 1, 'Nano', 'PEG', '17 Januari 2025', 15000000, 1500000, 8250000, '17 Januari 2025', 'Feb 25', 'Mar 25', null, null, null, null, null, null, null, null, 2, 12000000, 'Macet'],
            [null, 'Total'],
            [null, 'DATA PINJAMAN ANGGOTA KOPERASI (PPNPN)'],
            [null, 'No', 'Nama Pemohon', 'STATUS', 'Tgl Pengiriman Dana ke rek pemohon', 'Nominal Pembiayaan', 'Angsuran tiap bulan (10 bulan tenor)', 'Dana yang di TF', 'Tanggal di TF', 'Angsuran 1', 'Angsuran 2', 'Angsuran 3', 'Angsuran 4', 'Angsuran 5', 'Angsuran 6', 'Angsuran 7', 'Angsuran 8', 'Angsuran 9', 'Angsuran 10'],
            [null, 1, 'Cungcung', 'PPNPN', '11 Februari 2025', 10000000, 1000000, 9000000, '11 Februari 2025', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x', 'x'],
        ], 'Pembiayaan (2)', [
            'Pencairan' => [
                ['No', 'Tanggal', 'jumlah', 'terbilang', 'keperluan1', 'keperluan 2', 'Keperluan 3', 'Menyetujui', 'Penerima', 'Biaya Formulir'],
                [1, 'Kamis, 16 Januari 2025', 15000000, '#Lima Belas Juta Rupiah#', 'Pencairan', 'a.n. Nano', 'transfer ke BNI No Rek. 1457966465 a.n. Nano', 'Ketua', 'Nano', 750000],
            ],
            'PEMBIAYAAN BARANG' => [
                ['No', 'Tanggal Pengambilan Formulir', 'Nomor Formulir', 'Nama Pemohon', 'Tanggal Pengembalian Formulir dari Pemohon', 'Nama Petugas Koperasi', 'Tanggal Surat Permohonan', 'Tanggal Wawancara', 'Nominal Pengajuan', 'Tanggal Penelitian Dokumen', 'Status Permohonan (Terima / Tolak)', 'Nominal Pembiayaan Dana yang disetujui', 'Plus Pendapatan Lainnya', 'Plus Bagi Hasil', 'Total Pembiayaan', 'Angsuran', 'Tanggal Pengesahan Permohonan Pembiayaan Barang', 'Tanggal Pengiriman Dana ke Rekening Pemohon', 'Tanggal Jatuh Tempo Angsuran'],
                [null, null, null, null, null, null, null, null, null, null, null, null, '1,5%', '7,5%', null, null, null, null, 1, 'Checklist', 2, 'Checklist'],
                [1, '5 Desember 2023', '01/KOP/FPB/2023', 'Safianty Anwar', null, 'Petugas', '9 Desember 2023', null, 9900000, null, 'Terima', 9751500, 148500, 742500, 10642500, 886875, null, '11 Desember 2023', 886875, 'v (jan)', 886875, 'v'],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('financing.import.preview'), ['file' => $this->upload($path)]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->actingAs($user)->get(route('financing.import', ['token' => $query['token']]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('preview.by_type.dana', 2)->where('preview.by_type.barang', 1)->where('preview.disbursements', 1));

        $commit = fn () => $this->actingAs($user)->post(route('financing.import.commit'), ['token' => $query['token'], 'mapping' => ['CUNGCUNG' => (string) $cung->id]]);
        $commit()->assertRedirect();

        $nanoLoan = Financing::where('customer_id', $nano->id)->firstOrFail();
        $this->assertTrue($nanoLoan->is_bad_debt);
        $this->assertSame(750000, $nanoLoan->admin_fee);
        $this->assertSame('BNI', $nanoLoan->bank_name);
        $this->assertSame(8250000, $nanoLoan->transferred_amount);
        $this->assertSame(2, $nanoLoan->installments()->whereNotNull('paid_at')->count());
        $this->assertSame('lunas', Financing::where('customer_id', $cung->id)->value('status'));
        $this->assertSame(300000, Financing::where('customer_id', $cung->id)->value('admin_fee'));
        $goods = Financing::where('type', 'barang')->firstOrFail();
        $this->assertSame([742500, 148500, 12, 2], [$goods->margin_amount, $goods->admin_fee, $goods->tenor, $goods->installments()->whereNotNull('paid_at')->count()]);

        // Impor ulang file yang sama: tidak dobel.
        $response = $this->actingAs($user)->post(route('financing.import.preview'), ['file' => $this->upload($path)]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $commit();
        $this->assertSame(3, Financing::count());
    }
}
