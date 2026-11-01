<?php

namespace Tests\Feature\Cooperative;

use App\Models\BoardDecree;
use App\Models\Customer;
use App\Models\ShuPeriod;
use App\Models\User;
use App\Services\Cooperative\BoardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name, ?string $nip = null): Customer
    {
        return Customer::create(['name' => $name, 'nip' => $nip, 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['board-access', 'board-manage', 'shu-access']);

        return $user;
    }

    /** SK contoh dalam format Word (nama fiktif). */
    private function docx(array $paragraphs): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sk').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $body = implode('', array_map(fn ($p) => '<w:p><w:r><w:t>'.htmlspecialchars($p).'</w:t></w:r></w:p>', $paragraphs));
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>');
        $zip->close();

        return new UploadedFile($path, 'SK Pengurus.docx', null, null, true);
    }

    public function test_upload_sk_reads_board_members_and_stores_the_file(): void
    {
        Storage::fake('local');
        $ketua = $this->person('Andi Pratama Putra', '198001012005011001');
        $bendahara = $this->person('Siti Rahmawati');
        $this->person('Joko'); // satu kata: tidak dicocokkan dari teks
        $manager = $this->manager();

        $file = $this->docx([
            'KEPUTUSAN RAPAT ANGGOTA', 'NOMOR: KEP-01/KOP/2026', 'Menetapkan susunan pengurus:',
            'Ketua', 'Andi Pratama Putra NIP 19800101 200501 1 001',
            'Bendahara : SITI RAHMAWATI', 'Ditetapkan di Tangerang pada tanggal 5 Januari 2026',
        ]);

        $preview = $this->actingAs($manager)->post(route('board.preview'), ['file' => $file])->assertOk()->json();
        $this->assertSame('KEP-01/KOP/2026', $preview['number']);
        $this->assertSame('2026-01-05', $preview['decree_date']);
        $this->assertEqualsCanonicalizing(
            [[$ketua->id, 'Ketua'], [$bendahara->id, 'Bendahara']],
            array_map(fn ($m) => [$m['customer_id'], $m['position']], $preview['members']),
        );

        $this->actingAs($manager)->post(route('board.store'), [
            'number' => $preview['number'], 'decree_date' => '2026-01-05', 'effective_from' => '2026-01-05',
            'file' => $this->docx(['SK']),
            'members' => [['customer_id' => $ketua->id, 'position' => 'Ketua'], ['customer_id' => $bendahara->id, 'position' => 'Bendahara']],
        ])->assertSessionHasNoErrors();

        $decree = BoardDecree::with('members')->sole();
        $this->assertCount(2, $decree->members);
        Storage::disk('local')->assertExists($decree->file_path);
        $this->actingAs($manager)->get(route('board.file', $decree))->assertOk();
        $this->actingAs($manager)->get(route('board.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Cooperative/Board/Index')->where('currentId', $decree->id)->has('decrees', 1));

        // Tanpa izin kelola tidak bisa upload.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('board-access');
        $this->actingAs($viewer)->post(route('board.store'), [])->assertForbidden();

        $this->actingAs($manager)->delete(route('board.destroy', $decree));
        Storage::disk('local')->assertMissing($decree->file_path);
    }

    public function test_reads_sk_layout_with_divisions_numbered_lists_and_wrapped_labels(): void
    {
        foreach (['Lukman Hakim Contoh', 'Andi Pratama Putra', 'Citra Lestari', 'Dodi Saputra', 'Eka Wulandari', 'Fajar Nugroho', 'Gita Permata',
            'Hadi Santoso', 'Rizki Ananda Putra', 'Indah Sari Dewi', 'Joko Susilo', 'Kurnia Wati'] as $name) {
            $this->person($name);
        }

        // Teks seperti hasil baca PDF: kolom "Jabatan : Nama", nama lanjutan bernomor, label terpotong ke baris bawah.
        $text = implode("\n", [
            'KEPUTUSAN RAPAT ANGGOTA', 'NOMOR: KEP-02/KOP/2026',
            'Menimbang : a. bahwa susunan pengurus perlu diperbarui;',
            'PEMBINA : Lukman Hakim Contoh',
            'Ketua : Andi Pratama Putra, S.E.',
            'Kepala Unit Usaha : Benny Contoh Kurniawan',
            '- Divisi Toko : 1. Citra Lestari',
            '2. Dodi Saputra',
            '- Divisi Pembiayaan : Eka Wulandari',
            'Barang',
            '- Divisi Pembiayaan Dana : Fajar Nugroho',
            '- Divisi Pembiayaan : Gita Permata',
            'Kantor',
            'AKUNTANSI DAN PELAPORAN : 1. Hadi Santoso',
            '2. Rizki',
            '3. Indah Sari Dewi',
            'IT SUPPORT : 1. Joko Susilo',
            'Pengawas : 1. Kurnia Wati',
            'Ditetapkan di : Tangerang Selatan',
            'pada tanggal : 5 Januari 2026',
        ]);

        $result = app(BoardService::class)->detect($text);
        $byName = collect($result['members'])->keyBy('sk_name');
        $positions = $byName->map(fn ($m) => $m['position'])->all();

        // Jabatan baku: divisi "-" = Anggota Pengurus; bagian lain: orang pertama Kepala Unit Usaha, sisanya Anggota Pengurus.
        $this->assertSame([
            'Lukman Hakim Contoh' => 'Pembina',
            'Andi Pratama Putra' => 'Ketua',
            'Benny Contoh Kurniawan' => 'Kepala Unit Usaha',
            'Citra Lestari' => 'Anggota Pengurus',
            'Dodi Saputra' => 'Anggota Pengurus',
            'Eka Wulandari' => 'Anggota Pengurus',
            'Fajar Nugroho' => 'Anggota Pengurus',
            'Gita Permata' => 'Anggota Pengurus',
            'Hadi Santoso' => 'Kepala Unit Usaha',
            'Rizki' => 'Anggota Pengurus',
            'Indah Sari Dewi' => 'Anggota Pengurus',
            'Joko Susilo' => 'Kepala Unit Usaha',
            'Kurnia Wati' => 'Pengawas',
        ], $positions);
        // Label asli SK tetap tersedia sebagai keterangan.
        $this->assertSame('Divisi Pembiayaan Barang', $byName['Eka Wulandari']['sk_position']);
        $this->assertSame('Akuntansi dan Pelaporan', $byName['Indah Sari Dewi']['sk_position']);
        $this->assertNull($byName['Benny Contoh Kurniawan']['customer_id'], 'belum ada di Data Pegawai: dipilih manual');
        $this->assertTrue($byName['Rizki']['check'], 'nama satu kata dicocokkan ke nama mirip, perlu diperiksa');
        $this->assertSame('Rizki Ananda Putra', $byName['Rizki']['name']);
        $this->assertFalse($byName['Citra Lestari']['check']);
        $this->assertSame('KEP-02/KOP/2026', $result['number']);
        $this->assertSame('2026-01-05', $result['decree_date']);

        // Tanggal SK diambil dari "Ditetapkan di", bukan tanggal lain (Anggaran Dasar 2021, salinan, dst.).
        $date = fn (array $lines) => app(BoardService::class)->detect(implode("\n", $lines))['decree_date'];
        $this->assertSame('2026-01-01', $date(['Mengingat : Anggaran Dasar tanggal 12 Maret 2021', 'Ditetapkan di : Tangerang Selatan', 'pada tanggal : Januari 2026', 'Salinan sesuai aslinya 3 April 2021']));
        $this->assertSame('2026-08-15', $date(['Mengingat : Akta 1 Mei 2021', 'Ditetapkan di Tangerang', 'pada tanggal 15 Agt 2026']));
        $this->assertSame('2026-01-15', $date(['Mengingat : Akta 1 Mei 2021', 'Ditetapkan di : Tangerang pada tanggal 15-01-2026']));
        $this->assertSame('2021-05-01', $date(['Akta 1 Mei 2021'])); // tanpa blok penetapan: tanggal terakhir

        // Kolom label rata tengah di PDF: label di satu baris, ": nama" di baris berikutnya, sambungan label setelahnya.
        $centered = app(BoardService::class)->detect(implode("\n", [
            'Ketua : Andi Pratama Putra',
            '- Divisi Pembiayaan', ': Eka Wulandari', 'Barang',
            '- Divisi Pembiayaan', 'Kantor', ': Gita Permata',
            '- Divisi Toko : 1. Citra Lestari',
        ]));
        $this->assertSame(['Andi Pratama Putra' => 'Ketua', 'Eka Wulandari' => 'Divisi Pembiayaan Barang', 'Gita Permata' => 'Divisi Pembiayaan Kantor', 'Citra Lestari' => 'Divisi Toko'],
            collect($centered['members'])->pluck('sk_position', 'sk_name')->all());

        // Format Word bertabel: nama ke-2 di paragraf berikutnya dalam sel yang sama tetap ikut jabatannya.
        $cell = fn (array $paras) => '<w:tc>'.implode('', array_map(fn ($p) => '<w:p><w:r><w:t>'.$p.'</w:t></w:r></w:p>', $paras)).'</w:tc>';
        $row = fn (string $label, array $names) => '<w:tr>'.$cell([$label]).$cell([':']).$cell($names).'</w:tr>';
        $xml = '<w:document><w:body><w:tbl>'.$row('Divisi Toko', ['Citra Lestari', 'Dodi Saputra']).$row('IT Support', ['Joko Susilo']).'</w:tbl></w:body></w:document>';
        $path = tempnam(sys_get_temp_dir(), 'sk').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        $board = app(BoardService::class);
        $fromWord = $board->detect($board->text(new UploadedFile($path, 'sk.docx', null, null, true), null));
        $this->assertSame(['Citra Lestari' => 'Divisi Toko', 'Dodi Saputra' => 'Divisi Toko', 'Joko Susilo' => 'IT Support'],
            collect($fromWord['members'])->pluck('sk_position', 'name')->all());
    }

    public function test_board_months_follow_sk_periods_and_feed_shu(): void
    {
        $old = $this->person('Ketua Lama');
        $new = $this->person('Ketua Baru');
        $treasurer = $this->person('Bendahara Tetap');

        $first = BoardDecree::create(['number' => 'SK-1', 'effective_from' => '2024-01-01']);
        $first->members()->createMany([['customer_id' => $old->id, 'position' => 'Ketua'], ['customer_id' => $treasurer->id, 'position' => 'Bendahara', 'sort' => 1]]);
        // Pergantian 10 Juli: Juli dihitung untuk SK baru (berlaku di akhir bulan).
        $second = BoardDecree::create(['number' => 'SK-2', 'effective_from' => '2025-07-10']);
        $second->members()->createMany([['customer_id' => $new->id, 'position' => 'Ketua'], ['customer_id' => $treasurer->id, 'position' => 'Bendahara', 'sort' => 1]]);

        $members = collect(app(BoardService::class)->membersForYear(2025))->keyBy('customer_id');
        $this->assertSame([1, 2, 3, 4, 5, 6], $members[$old->id]['months']);
        $this->assertSame([7, 8, 9, 10, 11, 12], $members[$new->id]['months']);
        $this->assertSame(range(1, 12), $members[$treasurer->id]['months']);
        $this->assertSame([], app(BoardService::class)->membersForYear(2023));

        // Periode SHU baru langsung terisi dari SK; halaman SHU menyediakan tombol "Ambil dari SK".
        $admin = User::factory()->create();
        $admin->givePermissionTo(['shu-access', 'shu-manage']);
        $this->actingAs($admin)->post(route('shu.store'), ['year' => 2025]);
        $period = ShuPeriod::where('year', 2025)->sole();
        $this->assertCount(3, $period->board_members);
        $this->actingAs($admin)->get(route('shu.show', $period))->assertInertia(fn (Assert $page) => $page->has('boardFromDecrees', 3));
    }
}
