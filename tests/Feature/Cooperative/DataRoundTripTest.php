<?php

namespace Tests\Feature\Cooperative;

use App\Models\BoardDecree;
use App\Models\Customer;
use App\Models\Financing;
use App\Models\MemberContribution;
use App\Models\User;
use App\Services\Cooperative\EmployeeImportService;
use App\Services\Cooperative\FinancingService;
use App\Services\Cooperative\MemberSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Setiap data bisa diunduh, lalu diunggah ulang tanpa dobel (memperbarui). */
class DataRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use WritesSpreadsheets;

    private function person(string $name, ?string $nip = null): Customer
    {
        return Customer::create(['name' => $name, 'nip' => $nip, 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['employees-access', 'savings-access', 'savings-import', 'financing-access', 'financing-import', 'board-access']);

        return $user;
    }

    /** Simpan file unduhan ke path sementara. */
    private function download($response): string
    {
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);

        return $path;
    }

    private function cells(string $path): array
    {
        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false);
    }

    public function test_savings_download_reupload_updates_without_doubling(): void
    {
        $user = $this->user();
        $budi = $this->person('Budi Santoso', '198001012005011001');
        MemberContribution::create(['customer_id' => $budi->id, 'type' => 'wajib', 'period_year' => 2025, 'period_month' => 1, 'amount' => 100000, 'source' => 'import']);
        MemberContribution::create(['customer_id' => $budi->id, 'type' => 'sukarela', 'period_year' => 2025, 'period_month' => 1, 'amount' => 50000, 'source' => 'tunai']);
        MemberContribution::create(['customer_id' => $budi->id, 'type' => 'angsuran', 'period_year' => 2025, 'period_month' => 1, 'amount' => 900000, 'source' => 'import']);

        $path = $this->download($this->actingAs($user)->get(route('savings.export-data', ['year' => 2025])));
        $this->assertSame(['198001012005011001', 'Budi Santoso', 2025, 1, 0, 100000, 50000], array_slice($this->cells($path)[1], 0, 7));

        $reupload = function (string $file) use ($user, $budi) {
            $response = $this->actingAs($user)->post(route('savings.import.preview'), ['file' => $this->upload($file)]);
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->actingAs($user)->post(route('savings.import.commit'), ['token' => $query['token'], 'mapping' => ['BUDI SANTOSO' => (string) $budi->id]])
                ->assertRedirect(route('savings.index'));
        };
        $sum = fn (string $type) => (int) $budi->contributions()->where('type', $type)->where('period_month', 1)->sum('amount');

        $reupload($path);
        $reupload($path);
        $this->assertSame(100000, $sum('wajib'));
        $this->assertSame(50000, $sum('sukarela'), 'catatan tunai tidak didobel');
        $this->assertSame(900000, $sum('angsuran'), 'angsuran tidak tersentuh');

        // Ubah nilai di Excel (sukarela jadi 80rb, pokok 50rb) lalu unggah: diperbarui ke total baru.
        $edited = $this->xlsx([
            ['NIP', 'Nama Pegawai', 'Tahun', 'Bulan', 'Total Pokok', 'Total Wajib', 'Total Sukarela'],
            ['198001012005011001', 'Budi Santoso', 2025, 1, 50000, 100000, 80000],
        ]);
        $reupload($edited);
        $this->assertSame([50000, 100000, 80000], [$sum('pokok'), $sum('wajib'), $sum('sukarela')]);
    }

    public function test_financing_download_reupload_updates_without_doubling(): void
    {
        $user = $this->user();
        $budi = $this->person('Budi Santoso', '198001012005011001');
        $service = app(FinancingService::class);
        $loan = Financing::create($service->compute(['type' => 'dana', 'principal' => 10000000, 'tenor' => 10], $budi) + [
            'customer_id' => $budi->id, 'status' => 'disetujui', 'number' => '01/KOP/FPD/2025', 'applied_at' => '2025-02-01',
        ]);
        $service->disburse($loan, '2025-02-10', null);
        $loan->installments()->orderBy('seq')->limit(3)->get()->each(fn ($i) => $i->update(['paid_at' => $i->due_month]));

        $path = $this->download($this->actingAs($user)->get(route('financing.export', 'dana')));
        $row = $this->cells($path)[1];
        $this->assertSame(['Pembiayaan Dana', '01/KOP/FPD/2025', '198001012005011001', 'Budi Santoso'], array_slice($row, 0, 4));
        $this->assertSame('10/02/2025', $row[6]);

        // Ubah biaya formulir & catatan di Excel, unggah ulang dua kali.
        $cells = $this->cells($path);
        $cells[1][9] = 400000;
        $cells[1][18] = 'diperbarui dari Excel';
        $edited = $this->xlsx($cells);
        foreach ([1, 2] as $_) {
            $response = $this->actingAs($user)->post(route('financing.import.preview'), ['file' => $this->upload($edited)]);
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->actingAs($user)->post(route('financing.import.commit'), ['token' => $query['token'], 'mapping' => []])->assertRedirect();
        }

        $this->assertSame(1, Financing::count());
        $loan->refresh();
        $this->assertSame(400000, $loan->admin_fee);
        $this->assertSame('diperbarui dari Excel', $loan->notes);
        $this->assertSame(3, $loan->installments()->whereNotNull('paid_at')->count());
    }

    public function test_members_board_and_employees_can_be_downloaded(): void
    {
        $user = $this->user();
        $budi = $this->person('Budi Santoso', '198001012005011001');
        MemberContribution::create(['customer_id' => $budi->id, 'type' => 'wajib', 'period_year' => 2025, 'period_month' => 1, 'amount' => 100000]);
        app(MemberSync::class)->syncMembers();
        $decree = BoardDecree::create(['number' => 'SK-1', 'effective_from' => '2025-01-01']);
        $decree->members()->create(['customer_id' => $budi->id, 'position' => 'Ketua']);

        $members = $this->cells($this->download($this->actingAs($user)->get(route('cooperative-members.export'))));
        $this->assertSame(['198001012005011001', '198001012005011001', 'Budi Santoso'], array_slice($members[1], 0, 3));
        $this->assertSame(100000, $members[1][6]);

        $board = $this->cells($this->download($this->actingAs($user)->get(route('board.export'))));
        $this->assertSame(['SK-1', null, '01/01/2025', 'sampai SK berikutnya', 'Ketua', '198001012005011001', 'Budi Santoso'], $board[1]);

        $employeeFile = $this->download($this->actingAs($user)->get(route('employees.export')));
        $employees = $this->cells($employeeFile);
        $this->assertSame(['NIP', 'Nama'], array_slice($employees[0], 0, 2));
        $this->assertSame(['198001012005011001', 'Budi Santoso'], array_slice($employees[1], 0, 2), 'NIP tetap teks 18 digit');
        // Unggah ulang file pegawai: dikenali "sama", tidak membuat pegawai baru.
        $import = app(EmployeeImportService::class);
        $this->assertSame(['same'], array_column($import->classify($import->parse($employeeFile)), 'status'));
    }
}
