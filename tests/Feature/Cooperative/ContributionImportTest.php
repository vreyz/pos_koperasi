<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\MemberContribution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContributionImportTest extends TestCase
{
    use RefreshDatabase;
    use WritesSpreadsheets;

    /** Meniru sheet "Master": header bertingkat, baris nomor kolom, rumus. */
    private function masterSheet(array $rows): string
    {
        $header = [
            [null, null, null, null, null, null, null, '=J1+L1'],
            [null, 'No.', 'Nama Pegawai', 'TKTT', 'POTONGAN', null, null, null, null, null, null, null, null, null, null, null, null, null, 'Yg Diterima', 'Bulan', 'Tahun'],
            [null, null, null, null, 'DKP', 'Rumah Dinas', 'Uang Makan', 'Dharma Wanita', 'Simpanan Koperasi', null, null, null, 'Angsuran Koperasi', 'Masjid', 'PG Koperasi', 'Iuran Kopesat', 'Angsuran Kopesat', 'Jumlah'],
            [null, null, null, null, null, null, null, null, 'Pinjaman', 'Wajib', 'Pokok', 'Sukarela'],
            ['bank', 1, 2, 3, 4, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22],
        ];
        $data = array_map(fn ($r) => [
            'BNI', 1, $r[0], 25000000, 500000, 0, 600000, 10000, 0, $r[1], $r[2], $r[3], $r[4], 0, 0, 0, 0, '=SUM(E6:N6)', '=D6-R6', $r[5], $r[6],
        ], $rows);

        return $this->xlsx(array_merge($header, $data), 'Master', ['Master (backup)' => [['Nama Pegawai', 'Wajib', 'Bulan', 'Tahun'], ['X', 1, 1, 2020]]]);
    }

    public function test_import_matches_names_creates_aliases_and_reimport_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['savings-access', 'savings-import']);
        $agus = Customer::create(['name' => 'Agus Yoga Pratama', 'nip' => '199001012015031001', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        $budi = Customer::create(['name' => 'Budi Santoso', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);

        $path = $this->masterSheet([
            ['Budi Santoso', 100000, 0, 0, 0, 1, 2025],
            ['Budi Santoso', 100000, 0, 250000, 1500000, 2, 2025],
            ['AGUS Y', 100000, 50000, 0, 0, 1, 2025],
            ['Pegawai Lama', 500000, 0, 0, 0, 12, 2020],
        ]);

        $response = $this->actingAs($user)->post(route('savings.import.preview'), ['file' => $this->upload($path)]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->actingAs($user)->get(route('savings.import', ['token' => $query['token']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Cooperative/Savings/Import')
                ->where('preview.rows', 4)
                ->where('preview.years', [2020, 2025])
                ->where('preview.names.0.name', 'AGUS Y')
                ->where('preview.names.0.customer_id', null)
                ->where('preview.names.0.suggestions.0.id', $agus->id));

        $mapping = ['AGUS Y' => (string) $agus->id, 'PEGAWAI LAMA' => 'new', 'BUDI SANTOSO' => (string) $budi->id];
        $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $query['token'], 'mapping' => $mapping])
            ->assertRedirect(route('savings.index'));

        $this->assertSame(450000, (int) $budi->contributions()->whereIn('type', MemberContribution::SAVINGS_TYPES)->sum('amount'));
        $this->assertSame(1500000, (int) $budi->contributions()->where('type', 'angsuran')->sum('amount'));
        $this->assertSame(150000, (int) $agus->contributions()->sum('amount'));
        $this->assertTrue(CustomerAlias::where('alias', 'AGUS Y')->where('customer_id', $agus->id)->exists());
        $this->assertDatabaseHas('customers', ['name' => 'Pegawai Lama', 'employee_type' => 'LAINNYA']);

        // Import ulang file yang sama: alias membuat AGUS Y cocok otomatis dan data tidak dobel.
        $response = $this->actingAs($user)->post(route('savings.import.preview'), ['file' => $this->upload($path)]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $query['token'], 'mapping' => [
            'AGUS Y' => (string) $agus->id, 'BUDI SANTOSO' => (string) $budi->id,
        ]]);
        $this->assertSame(150000, (int) $agus->contributions()->sum('amount'));
        $this->assertSame(1950000, (int) $budi->contributions()->sum('amount'));
    }

    public function test_manual_withdrawal_cannot_exceed_balance_and_generate_skips_existing(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['savings-access', 'savings-create']);
        $a = Customer::create(['name' => 'A', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        $b = Customer::create(['name' => 'B', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        Customer::create(['name' => 'C', 'employee_type' => 'PNS', 'employment_status' => 'MUTASI', 'no_telp' => 0, 'address' => '']);
        Customer::create(['name' => 'D', 'employee_type' => 'PPNPN', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        MemberContribution::create(['customer_id' => $a->id, 'type' => 'sukarela', 'period_year' => 2026, 'period_month' => 1, 'amount' => 200000]);
        MemberContribution::create(['customer_id' => $a->id, 'type' => 'wajib', 'period_year' => 2026, 'period_month' => 2, 'amount' => 100000]);

        $this->actingAs($user)->post(route('savings.store'), [
            'customer_id' => $a->id, 'type' => 'sukarela', 'period_year' => 2026, 'period_month' => 3,
            'direction' => 'out', 'amount' => 300000, 'source' => 'tunai',
        ])->assertSessionHasErrors('amount');

        $this->actingAs($user)->post(route('savings.generate'), [
            'type' => 'wajib', 'period_year' => 2026, 'period_month' => 2, 'amount' => 100000, 'employee_types' => ['PNS', 'PPPK'],
        ])->assertSessionHasNoErrors();

        // Hanya B: A sudah punya wajib Feb, C bukan AKTIF, D PPNPN tidak dipilih.
        $this->assertSame(1, MemberContribution::where('source', 'potong_gaji')->count());
        $this->assertSame($b->id, MemberContribution::where('source', 'potong_gaji')->value('customer_id'));
    }
}
