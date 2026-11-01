<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\Financing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FinancingWordFormTest extends TestCase
{
    use RefreshDatabase;

    /** Template mirip formulir koperasi: label, ":", titik-titik; kotak centang gambar; blok tanda tangan; tabel persetujuan. */
    private function template(): UploadedFile
    {
        $p = fn (string $text) => '<w:p><w:pPr><w:jc w:val="both"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">'.$text.'</w:t></w:r></w:p>';
        $box = '<w:r><w:drawing><wp:anchor xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"/></w:drawing></w:r>';
        $row = fn (array $cells) => '<w:tr>'.implode('', array_map(fn ($c) => '<w:tc>'.$p($c).'</w:tc>', $cells)).'</w:tr>';
        $body = $p('PERMOHONAN FASILITAS PEMBIAYAAN DANA').$p('Nomor :               /KOP/FPD/2026')
            .$p('Nama / NIP').$p(':').$p('…………………………………..')
            .$p('Bidang / Seksi').$p(':').$p('…………………………………..')
            .$p('Nomor Telepon').$p(':').$p('…………………………………..')
            .$p('Jumlah Pembiayaan').'<w:p>'.$box.'<w:r><w:t>:</w:t></w:r></w:p>'.'<w:p>'.$box.'<w:r><w:t>  Rp 5.000.000       Rp 10.000.000  </w:t></w:r></w:p>'
            .$p('Jangka Waktu Pembiayaan').$p(':').$p('……….. bulan')
            .$p('Keluarga/Suami/Istri').$p('Tangerang Selatan,…………..').$p('Pemohon,').$p('………………………………..').$p('………………………………..').$p('NIP')
            .$p('Atasan Langsung,').$p('………………………………..').$p('NIP')
            .$p('menyampaikan konfirmasi melalui Sdri. Contoh (0800)')
            .'<w:tbl>'.$row(['Jabatan', 'Pengurus', 'Nama Pengurus', 'Tanda Tangan', 'Tanggal']).$row(['Diperiksa oleh', 'Divisi Pembiayaan Dana', '', '', '']).$row(['Disetujui oleh', 'Ketua Koperasi', '', '', '']).'</w:tbl>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>';
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->addFromString('word/header1.xml', '<kop/>');
        $zip->close();

        return new UploadedFile($path, 'Formulir PPNPN.docx', null, null, true);
    }

    private function texts(string $docx): array
    {
        $zip = new ZipArchive;
        $zip->open($docx);
        $xml = $zip->getFromName('word/document.xml');
        $this->assertSame('<kop/>', $zip->getFromName('word/header1.xml'), 'kop surat tidak diubah');
        preg_match_all('/<w:p>.*?<\/w:p>|<w:p [^>]*>.*?<\/w:p>/s', $xml, $m);

        return array_values(array_filter(array_map(fn ($p) => preg_match_all('/<w:t[^>]*>([^<]*)<\/w:t>/', $p, $t) ? html_entity_decode(implode('', $t[1])) : '', $m[0])));
    }

    public function test_filled_word_form_downloads_with_application_data(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->givePermissionTo(['financing-access', 'financing-create', 'financing-approve']);
        $budi = Customer::create(['name' => 'Budi Contoh', 'nip' => '199001012015011001', 'employee_type' => 'PPNPN', 'work_unit' => 'Bagian Umum', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);

        $this->actingAs($admin)->post(route('financing.store', 'dana'), [
            'customer_id' => $budi->id, 'principal' => 10000000, 'tenor' => 10, 'applied_at' => '2026-11-03', 'phone' => '0812-1111', 'family_name' => 'Siti Contoh',
        ])->assertSessionHasNoErrors();
        $financing = Financing::sole();

        // Tanpa template: diarahkan kembali dengan pesan.
        $this->actingAs($admin)->get(route('financing.word', $financing))->assertSessionHas('error');

        $this->actingAs($admin)->post(route('financing.templates.upload', 'dana_ppnpn'), ['file' => $this->template()])->assertSessionHasNoErrors();
        $response = $this->actingAs($admin)->get(route('financing.word', $financing))->assertOk();
        $this->assertStringContainsString('Formulir 01-KOP-FPD-XI-2026 Budi Contoh.docx', $response->headers->get('content-disposition'));

        $texts = $this->texts($response->baseResponse->getFile()->getPathname());
        $this->assertContains('Nomor : 01/KOP/FPD/XI/2026', $texts);
        $this->assertContains('Budi Contoh / 199001012015011001', $texts);
        $this->assertContains('Bagian Umum', $texts);
        $this->assertContains('0812-1111', $texts);
        $this->assertContains('☐ Rp 5.000.000       ☑ Rp 10.000.000', $texts);
        $this->assertContains('10 bulan', $texts);
        $this->assertContains("Tangerang Selatan, 3\u{00A0}November\u{00A0}2026", $texts);
        $this->assertContains('Siti Contoh', $texts);  // nama keluarga di tanda tangan
        $this->assertContains('NIP 199001012015011001', $texts);
        $this->assertSame(1, count(array_keys($texts, 'NIP')), 'NIP atasan langsung tetap kosong');
        $this->assertContains('menyampaikan konfirmasi melalui Sdri. Contoh (0800)', $texts, 'teks S&K tidak diubah');
    }
}
