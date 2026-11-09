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

    public function test_history_tab_is_one_row_per_name_and_type_with_month_columns(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('savings-access');
        $a = Customer::create(['name' => 'A', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        foreach ([[1, 100000], [2, 100000], [2, 50000]] as [$month, $amount]) {
            MemberContribution::create(['customer_id' => $a->id, 'type' => 'wajib', 'period_year' => 2025, 'period_month' => $month, 'amount' => $amount]);
        }
        MemberContribution::create(['customer_id' => $a->id, 'type' => 'sukarela', 'period_year' => 2025, 'period_month' => 3, 'amount' => 20000]);
        // Angsuran pinjaman bukan simpanan: tidak tampil di menu Simpanan.
        MemberContribution::create(['customer_id' => $a->id, 'type' => 'angsuran', 'period_year' => 2025, 'period_month' => 3, 'amount' => 900000]);
        $onlyLoan = Customer::create(['name' => 'Z', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        MemberContribution::create(['customer_id' => $onlyLoan->id, 'type' => 'angsuran', 'period_year' => 2025, 'period_month' => 3, 'amount' => 900000]);

        $this->actingAs($user)->get(route('savings.index', ['year' => 2025, 'tab' => 'history']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('history', 2)
                ->where('history.0.key', $a->id.'|wajib|2025')
                ->where('history.0.months.1', 100000)
                ->where('history.0.months.2', 150000)
                ->where('history.0.total', 250000)
                ->has('history.0.entries.2', 2)
                ->where('history.1.months.3', 20000));

        $this->actingAs($user)->get(route('savings.index', ['year' => 2025]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('recap', 1)->where('recap.0.wajib_total', 250000)
                ->missing('totals.angsuran')->missing('typeLabels.angsuran'));
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
        $this->actingAs($user)->post(route('savings.store'), [
            'customer_id' => $a->id, 'type' => 'angsuran', 'period_year' => 2026, 'period_month' => 3,
            'direction' => 'in', 'amount' => 100000, 'source' => 'tunai',
        ])->assertSessionHasErrors('type');

        $this->actingAs($user)->post(route('savings.generate'), [
            'type' => 'wajib', 'period_year' => 2026, 'period_month' => 2, 'amount' => 100000, 'employee_types' => ['PNS', 'PPPK'],
        ])->assertSessionHasNoErrors();

        // Hanya B: A sudah punya wajib Feb, C bukan AKTIF, D PPNPN tidak dipilih.
        $this->assertSame(1, MemberContribution::where('source', 'potong_gaji')->count());
        $this->assertSame($b->id, MemberContribution::where('source', 'potong_gaji')->value('customer_id'));
    }

    private function previewToken(User $user, string $path): string
    {
        $response = $this->actingAs($user)->post(route('savings.import.preview'), ['file' => $this->upload($path)]);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query['token'];
    }

    /** Rekap bulanan per nama (kolom JAN..DES), tahun dari baris "Periode". */
    private function monthlySheet(string $period, array $people, ?array $jumlah = null): array
    {
        $rows = [[null, 'KEMENTERIAN KEUANGAN'], [null, 'Periode : '.$period], ['No', 'Nama', 'JAN', 'FEB', 'MAR', 'APR', 'MEI', 'JUN', 'JUL', 'AGS', 'SEP', 'OKT', 'NOV', 'DES']];
        foreach ($people as $i => [$name, $months]) {
            $rows[] = [$i + 1, $name, ...$months];
        }
        $rows[] = ['Jumlah', null, ...($jumlah ?? array_map(fn ($m) => array_sum(array_column(array_column($people, 1), $m)), range(0, 11)))];
        $rows[] = [null, 'Kepala Bagian Umum'];

        return $rows;
    }

    public function test_monthly_rekap_sheets_are_imported_after_validation(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['savings-access', 'savings-import']);
        $dewi = Customer::create(['name' => 'Dewi Lestari', 'employee_type' => 'PPNPN', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        $eka = Customer::create(['name' => 'Eka Putra', 'employee_type' => 'PPNPN', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);

        $full = array_fill(0, 12, 50000);
        $half = [...array_fill(0, 6, 0), ...array_fill(0, 6, 50000)];
        $path = $this->xlsx(
            $this->monthlySheet('2025', [['Dewi Lestari', $full], ['Eka Putra', $full]]),
            'rekap simpanan ppnpn 25',
            ['rekap simpanan ppnpn' => $this->monthlySheet('2024', [['Dewi Lestari', $half]]), 'gaji' => [['No', 'Nama', 'Bank', 'No Rekening']]],
        );
        $token = $this->previewToken($user, $path);

        $this->actingAs($user)->get(route('savings.import', ['token' => $token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.years', [2024, 2025])
                ->has('preview.sheets', 2)
                ->where('preview.checks', fn ($checks) => collect($checks)->where('level', 'error')->isEmpty()
                    && collect($checks)->contains(fn ($c) => str_contains($c['title'], 'dianggap Simpanan Wajib'))
                    && collect($checks)->contains(fn ($c) => str_contains($c['title'], 'sama dengan baris Jumlah'))));

        $mapping = ['DEWI LESTARI' => (string) $dewi->id, 'EKA PUTRA' => (string) $eka->id];
        $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $token, 'mapping' => $mapping])
            ->assertRedirect(route('savings.index'));
        $this->assertSame(600000 + 300000, (int) $dewi->contributions()->where('type', 'wajib')->sum('amount'));
        $this->assertSame(600000, (int) $eka->contributions()->where('type', 'wajib')->where('period_year', 2025)->sum('amount'));

        // Baris Jumlah di file tidak sama dengan isi → validasi error, tidak disimpan.
        $bad = $this->xlsx($this->monthlySheet('2023', [['Dewi Lestari', $full]], array_fill(0, 12, 60000)), 'rekap 2023');
        $token = $this->previewToken($user, $bad);
        $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $token, 'mapping' => $mapping])
            ->assertRedirect(route('savings.import', ['token' => $token]))
            ->assertSessionHas('error');
        $this->assertSame(0, $dewi->contributions()->where('period_year', 2023)->count());
    }

    public function test_withdrawal_file_is_recorded_as_negative_and_cannot_exceed_balance(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['savings-access', 'savings-import']);
        $a = Customer::create(['name' => 'Agus Mutasi', 'employee_type' => 'PNS', 'employment_status' => 'MUTASI', 'no_telp' => 0, 'address' => '']);
        $b = Customer::create(['name' => 'Budi Baru', 'employee_type' => 'PNS', 'employment_status' => 'MUTASI', 'no_telp' => 0, 'address' => '']);
        foreach (range(1, 12) as $month) {
            MemberContribution::create(['customer_id' => $a->id, 'type' => 'wajib', 'period_year' => 2024, 'period_month' => $month, 'amount' => 100000, 'source' => 'potong_gaji']);
        }

        $sheet = fn (array $people) => [
            [null, 'DATA PENGEMBALIAN SIMPANAN ANGGOTA KOPERASI'],
            ['mutasi', 'Nama', 'Tanggal', 'No Rek', 'Pengembalian wajib', 'Pengembalian Sukarela', 'Total Pengembalian'],
            ...array_map(fn ($p) => [null, $p[0], 45726, 'x', $p[1], 0, $p[1]], $people), // 45726 = 10 Maret 2025
            [null, null, null, null, array_sum(array_column($people, 1)), 0],
            [null, 'hutang pengembalian simpanan', null, null, null, -250000],
        ];

        $token = $this->previewToken($user, $this->xlsx($sheet([['Agus Mutasi', 1000000]]), 'Simpanan'));
        $this->actingAs($user)->post(route('savings.import.validate'), ['token' => $token, 'mapping' => ['AGUS MUTASI' => (string) $a->id]])
            ->assertOk()
            ->assertJson(fn ($json) => $json->where('checks', fn ($checks) => collect($checks)->where('level', 'error')->isEmpty()
                && collect($checks)->contains(fn ($c) => str_contains($c['title'], 'tidak menghabiskan saldo')))->etc());
        $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $token, 'mapping' => ['AGUS MUTASI' => (string) $a->id]])
            ->assertRedirect(route('savings.index'));

        $withdrawal = $a->contributions()->where('amount', '<', 0)->sole();
        $this->assertSame(-1000000, $withdrawal->amount);
        $this->assertSame([2025, 3], [$withdrawal->period_year, $withdrawal->period_month]);
        $this->assertStringContainsString('Pengembalian simpanan 10/03/2025', $withdrawal->notes);
        $this->assertSame(200000, (int) $a->contributions()->sum('amount'));

        // Rekap: kolom setoran tidak berkurang, penarikan di kolom sendiri.
        $this->actingAs($user)->get(route('savings.index', ['year' => 2025]))
            ->assertInertia(fn (Assert $page) => $page->where('recap.0.withdrawn_total', -1000000)->where('recap.0.wajib_total', null)->where('totals.withdrawn', 1000000));

        // Impor ulang tidak dobel; Budi tanpa saldo → pengembalian ditolak, tidak ada yang tersimpan.
        $token = $this->previewToken($user, $this->xlsx($sheet([['Agus Mutasi', 1000000], ['Budi Baru', 500000]]), 'Simpanan'));
        $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $token, 'mapping' => ['AGUS MUTASI' => (string) $a->id, 'BUDI BARU' => (string) $b->id]])
            ->assertRedirect(route('savings.import', ['token' => $token]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'melebihi saldo'));
        $this->assertSame(0, $b->contributions()->count());
        $this->assertSame(1, $a->contributions()->where('amount', '<', 0)->count());
    }
}
