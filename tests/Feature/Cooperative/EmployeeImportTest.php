<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\User;
use App\Services\Cooperative\EmployeeImportService;
use App\Services\Cooperative\Nip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;
    use WritesSpreadsheets;

    private function employee(array $attributes): Customer
    {
        return Customer::create($attributes + ['employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    private function headerRows(array $rows): array
    {
        return array_merge([
            ['DAFTAR NAMA PEGAWAI'],
            [],
            ['NO', 'N A M A', 'NIP', 'PANGKAT', 'GOL', 'JABATAN', 'UNIT', 'STATUS'],
        ], $rows);
    }

    public function test_preview_classifies_new_same_changed_and_suspect_rows(): void
    {
        $this->employee(['name' => 'Budi Santoso', 'nip' => '197003151991031001', 'work_unit' => 'BAGIAN UMUM', 'grade' => 'III.c']);
        $this->employee(['name' => 'Citra Lestari', 'nip' => '197204201998032001', 'work_unit' => 'PFPBC AUDITOR']);
        $this->employee(['name' => 'Dedi Kurniawan', 'nip' => '199205102013101004']);

        $path = $this->xlsx($this->headerRows([
            [1, 'Budi Santoso', '197003151991031001', '', 'III/c', '', 'BAGIAN UMUM', 'AKTIF'],   // sama
            [2, 'Citra Lestari', '197204201998032001', '', '', '', 'PFPBC AUDITOR', 'MUTASI'],           // berubah
            [3, 'Dedi Kurniawan', '199205102013101000', '', '', '', '', 'AKTIF'],                       // NIP mirip (dibulatkan Excel)
            [4, 'Eko Prasetyo', '196808081991031001', '', 'IV.c', '', 'PFPBC AUDITOR', 'AKTIF'],   // baru
            [5, 'Slamet', '', '', '', 'Pramubakti', 'BAGIAN UMUM', 'AKTIF'],                         // baru, PPNPN tanpa NIP
        ]));

        $service = app(EmployeeImportService::class);
        $rows = array_values($service->classify($service->parse($path)));
        $byName = collect($rows)->keyBy(fn ($r) => $r['data']['name']);

        $this->assertSame('same', $byName['Budi Santoso']['status']);
        $this->assertSame('changed', $byName['Citra Lestari']['status']);
        $this->assertSame(['old' => 'AKTIF', 'new' => 'MUTASI'], $byName['Citra Lestari']['diff']['employment_status']);
        $this->assertSame('suspect', $byName['Dedi Kurniawan']['status']);
        $this->assertSame('skip', $byName['Dedi Kurniawan']['default_action']);
        $this->assertStringContainsString('dibulatkan Excel', implode(' ', $byName['Dedi Kurniawan']['warnings']));
        $this->assertSame('new', $byName['Eko Prasetyo']['status']);
        $this->assertSame('new', $byName['Slamet']['status']);
        $this->assertSame('PPNPN', $byName['Slamet']['data']['employee_type']);
    }

    public function test_commit_applies_user_decisions_via_http(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['employees-access', 'employees-import']);
        $existing = $this->employee(['name' => 'Citra Lestari', 'nip' => '197204201998032001']);
        $typo = $this->employee(['name' => 'Dedi Kurniawan', 'nip' => '199205102013101004']);

        $path = $this->xlsx($this->headerRows([
            [1, 'Citra Lestari', '197204201998032001', '', '', '', '', 'MUTASI'],
            [2, 'Dedi Kurniawan', '199205102013101000', '', '', '', '', 'AKTIF'],
            [3, 'Eko Prasetyo', '196808081991031001', '', '', '', '', 'AKTIF'],
        ]));

        $response = $this->actingAs($user)->post(route('employees.import.preview'), ['file' => $this->upload($path)]);
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $token = $query['token'];

        $this->actingAs($user)->get(route('employees.import', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Cooperative/Employees/Import')
                ->where('preview.counts.changed', 1)
                ->where('preview.counts.suspect', 1)
                ->where('preview.counts.new', 1));

        $this->actingAs($user)->post(route('employees.import.commit'), [
            'token' => $token,
            'actions' => [4 => 'update', 5 => 'skip'], // baris 6 = default (create)
        ])->assertRedirect(route('employees.index'));

        $this->assertSame('MUTASI', $existing->fresh()->employment_status);
        $this->assertSame('199205102013101004', $typo->fresh()->nip, 'NIP yang mirip tidak boleh menimpa tanpa konfirmasi');
        $this->assertDatabaseHas('customers', ['nip' => '196808081991031001', 'name' => 'Eko Prasetyo', 'employee_type' => 'PNS']);
        $this->assertSame(3, Customer::employees()->count());
    }

    public function test_parses_sectioned_roster_with_repeated_headers(): void
    {
        $path = $this->xlsx([
            ['DAFTAR NAMA PEGAWAI KANWIL (Update 28 September 2026)'],
            ['Jumlah Pegawai: 3 Pegawai'],
            ['KEPALA KANTOR WILAYAH'],
            ['NO', 'N A M A', 'NIP', 'PANGKAT', 'GOL', 'JABATAN', 'Agama'],
            [1, 'Fajar Hidayat', '196911111996031001', 'Pembina Utama Madya', 'IV/d', 'Kepala Kantor', 'Islam'],
            [],
            ['BAGIAN UMUM'],
            ['NO', 'N A M A', 'NIP', 'PANGKAT', 'GOL', 'JABATAN', 'Agama'],
            [1, 'Gilang Ramadhan', '197702021997031001', 'Pembina', 'IV/a', 'Kepala Bagian Umum', 'Islam'],
            [2, 'Hana Pertiwi', '200003222018122002', 'Pengatur', 'II/c', 'Pelaksana', 'Islam'],
        ], '28 September 2026');

        $service = app(EmployeeImportService::class);
        $parsed = array_values($service->parse($path));

        $this->assertCount(3, $parsed);
        $this->assertSame('KEPALA KANTOR WILAYAH', $parsed[0]['work_unit']);
        $this->assertSame('BAGIAN UMUM', $parsed[2]['work_unit']);
        $this->assertSame('IV.a', $parsed[1]['grade']);

        // NIP berdekatan milik orang yang namanya jauh berbeda bukan salah ketik.
        $this->employee(['name' => 'Indah Maharani', 'nip' => '200003052018122002']);
        $rows = array_values($service->classify($service->parse($path)));
        $this->assertSame('new', $rows[2]['status']);
    }

    public function test_alias_column_and_invalid_nip_are_handled(): void
    {
        $existing = $this->employee(['name' => 'Budi Santoso', 'nip' => '197003151991031001']);
        $path = $this->xlsx([
            ['NIP', 'Nama', 'Jenis', 'Status', 'Alias'],
            ['197003151991031001', 'Budi Santoso', 'PNS', 'AKTIF', 'BUDI S; BUDI'],
            ['', 'Slamet Riyadi', 'PPNPN', 'AKTIF', 'SLAMET PPNPN'],
            ['1973909181994021002', 'Pegawai NIP Salah', 'PNS', 'AKTIF', ''],
        ]);

        $service = app(EmployeeImportService::class);
        $rows = collect($service->classify($service->parse($path)))->keyBy(fn ($r) => $r['data']['name']);

        $this->assertSame('changed', $rows['Budi Santoso']['status']);
        $this->assertArrayHasKey('aliases', $rows['Budi Santoso']['diff']);
        $this->assertSame('suspect', $rows['Pegawai NIP Salah']['status']);
        $this->assertSame('skip', $rows['Pegawai NIP Salah']['default_action']);

        $service->commit($service->classify($service->parse($path)), []);

        $this->assertEqualsCanonicalizing(['BUDI S', 'BUDI'], $existing->aliases()->pluck('alias')->all());
        $this->assertDatabaseHas('customer_aliases', ['alias' => 'SLAMET PPNPN']);
        $this->assertDatabaseMissing('customers', ['name' => 'Pegawai NIP Salah']);
    }

    public function test_active_period_columns_are_parsed_and_compared(): void
    {
        $existing = $this->employee(['name' => 'Budi Santoso', 'nip' => '197003151991031001']);
        $path = $this->xlsx([
            ['NIP', 'Nama', 'Status', 'Aktif Sejak', 'Aktif Sampai'],
            ['197003151991031001', 'Budi Santoso', 'MUTASI', '', '31/03/2025'],
        ]);
        $service = app(EmployeeImportService::class);
        $row = array_values($service->classify($service->parse($path)))[0];

        $this->assertSame('changed', $row['status']);
        $this->assertSame(['old' => null, 'new' => '2025-03-31'], $row['diff']['active_until']);
        $service->commit($service->classify($service->parse($path)), []);
        $this->assertSame('2025-03-31', $existing->fresh()->active_until->format('Y-m-d'));
        $this->assertSame('same', array_values($service->classify($service->parse($path)))[0]['status']);
    }

    public function test_nip_validation(): void
    {
        $this->assertNull(Nip::problem('197003151991031001'));
        $this->assertNotNull(Nip::problem('1973909181994021002'));
        $this->assertNotNull(Nip::problem('197894222003031002'));
        $this->assertNotNull(Nip::problem('199205102013101000'));
        $this->assertTrue(Nip::similar('199205102013101000', '199205102013101004'));
        $this->assertSame('197003151991031001', Nip::normalize(' 19700315 199103 1 001 '));
    }

    public function test_employee_pages_require_permission(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('employees.index'))->assertForbidden();

        $user->givePermissionTo('employees-access');
        $this->actingAs($user)->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Cooperative/Employees/Index'));
    }
}
