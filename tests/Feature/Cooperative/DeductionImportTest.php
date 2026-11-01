<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\Financing;
use App\Models\MemberContribution;
use App\Models\User;
use App\Services\Cooperative\FinancingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeductionImportTest extends TestCase
{
    use RefreshDatabase;
    use WritesSpreadsheets;

    private function person(string $name, ?string $nip = null): Customer
    {
        return Customer::create(['name' => $name, 'nip' => $nip, 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    /** Sheet seperti "Master" potongan gaji: judul bertingkat baris 2–4, baris nomor kolom, data. Baris: [nama, pinjaman, wajib, pokok, sukarela, angsuran, pg, bulan, tahun]. */
    private function sheet(array $rows): string
    {
        $header = [
            [null, null, 'REKAP POTONGAN GAJI'],
            [null, 'No.', 'Nama Pegawai', 'POTONGAN', null, null, null, null, null, null, null, null, 'Bulan', 'Tahun'],
            [null, null, null, 'Simpanan Koperasi', null, null, null, 'Angsuran Koperasi', 'Masjid', 'PG Koperasi', 'Angsuran Kopesat'],
            [null, null, null, 'Pinjaman', 'Wajib', 'Pokok', 'Sukarela'],
            [null, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, null, 11, 12],
        ];
        $data = array_map(fn ($r) => [null, 1, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], 15000, $r[6], 99999, null, $r[7], $r[8]], $rows);

        return $this->xlsx(array_merge($header, $data), 'Master');
    }

    private function importFile(User $user, string $path, array $names, ?callable $confirm = null): array
    {
        $layout = $this->actingAs($user)->post(route('deductions.import.upload'), ['file' => $this->upload($path)])->assertOk()->json();
        $base = ['token' => $layout['token'], 'sheet' => $layout['sheet'], 'header_to' => $layout['header_to'], 'mapping' => $layout['mapping']];
        $analysis = $this->actingAs($user)->postJson(route('deductions.import.analyze'), $base)->assertOk()->json();
        $preview = $this->actingAs($user)->postJson(route('deductions.import.preview'), $base + ['names' => $names])->assertOk()->json();
        $confirmed = $confirm ? $confirm($preview) : [];
        $result = $this->actingAs($user)->postJson(route('deductions.import.commit'), $base + ['names' => $names, 'confirmed' => $confirmed])->assertOk()->json();

        return compact('layout', 'analysis', 'preview', 'result');
    }

    public function test_wizard_detects_layout_and_imports_without_duplicates(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['deductions-access', 'deductions-import']);
        $budi = $this->person('Budi Santoso', '198001012005011001');
        $citra = $this->person('Citra Lestari');
        // Catatan sukarela tunai (bukan impor) di Januari: tidak boleh didobel.
        MemberContribution::create(['customer_id' => $budi->id, 'type' => 'sukarela', 'period_year' => 2026, 'period_month' => 1, 'amount' => 20000, 'source' => 'tunai']);

        $rows = [
            ['Budi Santoso', 50000, 100000, 0, 20000, 500000, 75000, 1, 2026],
            ['Budi Santoso', 50000, 100000, 0, 0, 500000, 0, 2, 2026],
            ['CITRA L', 0, 100000, 50000, 0, 0, 30000, 1, 2026],
        ];
        $first = $this->importFile($user, $this->sheet($rows), ['BUDI SANTOSO' => $budi->id, 'CITRA L' => $citra->id]);

        // Tebakan otomatis: sheet Master, judul baris 2–4, semua kolom koperasi (bukan Masjid/Kopesat).
        $this->assertSame('Master', $first['layout']['sheet']);
        $this->assertSame([2, 4], [$first['layout']['header_from'], $first['layout']['header_to']]);
        $this->assertSame(['name' => 2, 'nip' => null, 'month' => 12, 'year' => 13, 'pinjaman' => 3, 'wajib' => 4, 'pokok' => 5, 'sukarela' => 6, 'angsuran' => 7, 'pg' => 9], $first['layout']['mapping']);
        $this->assertSame(3, $first['analysis']['rows']);
        $this->assertNull(collect($first['analysis']['names'])->firstWhere('key', 'CITRA L')['customer_id']);
        // Sukarela Jan Budi = 20.000 sudah ada (tunai) → sama; sisanya baru.
        $this->assertSame(0, $first['preview']['changed']);
        $this->assertSame(10, $first['result']['inserted']);

        $sum = fn (Customer $c, string $type, int $month) => (int) MemberContribution::where('customer_id', $c->id)->where('type', $type)->where('period_month', $month)->sum('amount');
        $this->assertSame([50000, 100000, 20000, 500000, 75000], [$sum($budi, 'pinjaman', 1), $sum($budi, 'wajib', 1), $sum($budi, 'sukarela', 1), $sum($budi, 'angsuran', 1), $sum($budi, 'pg', 1)]);
        $this->assertSame(30000, $sum($citra, 'pg', 1));

        // Unggah ulang file yang sama: semua sama, tidak ada yang bertambah.
        $count = MemberContribution::count();
        $again = $this->importFile($user, $this->sheet($rows), ['BUDI SANTOSO' => $budi->id, 'CITRA L' => $citra->id]);
        $this->assertSame([0, 0], [$again['preview']['new'], $again['preview']['changed']]);
        $this->assertSame($count, MemberContribution::count());

        // Nilai berubah: tanpa konfirmasi tidak berubah; dengan konfirmasi diperbarui.
        $rows[0][2] = 150000; // wajib Jan Budi
        $rows[0][4] = 30000;  // sukarela Jan Budi (20.000 tunai + 10.000 selisih)
        $unconfirmed = $this->importFile($user, $this->sheet($rows), ['BUDI SANTOSO' => $budi->id, 'CITRA L' => $citra->id]);
        $this->assertSame(2, $unconfirmed['preview']['changed']);
        $this->assertSame(100000, $sum($budi, 'wajib', 1));
        $confirmed = $this->importFile($user, $this->sheet($rows), ['BUDI SANTOSO' => $budi->id, 'CITRA L' => $citra->id], fn ($p) => array_column($p['changed_rows'], 'key'));
        $this->assertSame(2, $confirmed['result']['updated']);
        $this->assertSame(150000, $sum($budi, 'wajib', 1));
        $this->assertSame(30000, $sum($budi, 'sukarela', 1));
        $this->assertSame(20000, (int) MemberContribution::where('customer_id', $budi->id)->where('source', 'tunai')->sum('amount'), 'catatan tunai tetap');

        // Menu rekap.
        $this->actingAs($user)->get(route('deductions.index', ['year' => 2026]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Cooperative/Deductions/Index')
                ->where('rows.0.name', 'Budi Santoso')->where('rows.0.pg', 75000)->where('rows.0.angsuran', 1000000));
        // Simpanan Pinjaman & PG tidak masuk menu Simpanan.
        $this->assertTrue(Customer::find($budi->id)->is_loyalty_member);
    }

    public function test_angsuran_koperasi_pays_financing_installments(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['deductions-access', 'deductions-import']);
        $budi = $this->person('Budi Santoso');
        $service = app(FinancingService::class);
        $loan = Financing::create($service->compute(['type' => 'dana', 'principal' => 6000000, 'tenor' => 12], $budi) + ['customer_id' => $budi->id, 'status' => 'disetujui']);
        $service->disburse($loan, '2025-12-10', null); // cicilan mulai Januari 2026 @ 500.000

        $result = $this->importFile($user, $this->sheet([['Budi Santoso', 0, 100000, 0, 0, 500000, 0, 1, 2026]]), ['BUDI SANTOSO' => $budi->id])['result'];

        $this->assertSame(1, $result['installments_paid']);
        $this->assertNotNull($loan->installments()->where('seq', 1)->value('paid_at'));
        $this->assertNull($loan->installments()->where('seq', 2)->value('paid_at'));
    }

    public function test_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('deductions-access');
        $this->actingAs($user)->get(route('deductions.import'))->assertForbidden();
        $this->actingAs($user)->get(route('deductions.index'))->assertOk();
    }
}
