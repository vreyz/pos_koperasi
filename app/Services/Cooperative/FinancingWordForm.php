<?php

namespace App\Services\Cooperative;

use App\Models\Financing;
use App\Models\Setting;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Formulir pembiayaan dalam format Word milik koperasi: template .docx diunggah sekali (disimpan
 * lokal, tidak masuk git), lalu titik-titik isian diisi data pengajuan. Kop surat, gaya huruf, dan
 * teks syarat & ketentuan tetap persis seperti template.
 */
class FinancingWordForm
{
    public const TEMPLATES = [
        'dana_pegawai' => 'Formulir Pembiayaan Dana (pegawai)',
        'dana_ppnpn' => 'Formulir Pembiayaan Dana (PPNPN)',
        'barang' => 'Formulir Pembiayaan Barang',
    ];

    private const DIR = 'financing-templates';

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public static function keyFor(Financing $financing): string
    {
        return $financing->type === 'barang' ? 'barang' : ($financing->customer?->employee_type === 'PPNPN' ? 'dana_ppnpn' : 'dana_pegawai');
    }

    /** @return array<string, array{label: string, file: ?string, uploaded_at: ?string}> */
    public function status(): array
    {
        $result = [];
        foreach (self::TEMPLATES as $key => $label) {
            $exists = Storage::disk('local')->exists($this->path($key));
            $result[$key] = ['label' => $label, 'file' => $exists ? Setting::get("financing_template_{$key}") : null,
                'uploaded_at' => $exists ? date('Y-m-d H:i', Storage::disk('local')->lastModified($this->path($key))) : null];
        }

        return $result;
    }

    public function store(string $key, UploadedFile $file): void
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true || $zip->locateName('word/document.xml') === false) {
            throw new RuntimeException('File bukan dokumen Word (.docx) yang valid.');
        }
        $zip->close();
        Storage::disk('local')->putFileAs(self::DIR, $file, "{$key}.docx");
        Setting::set("financing_template_{$key}", $file->getClientOriginalName());
    }

    public function delete(string $key): void
    {
        Storage::disk('local')->delete($this->path($key));
    }

    public function templatePath(string $key): ?string
    {
        return Storage::disk('local')->exists($this->path($key)) ? Storage::disk('local')->path($this->path($key)) : null;
    }

    /** Isi template untuk satu pengajuan; kembalikan path file sementara. */
    public function fill(Financing $financing): string
    {
        $financing->loadMissing(['customer', 'checker:id,name', 'reviewer:id,name', 'approver:id,name']);
        $template = $this->templatePath(self::keyFor($financing));
        if (! $template) {
            throw new RuntimeException('Template '.self::TEMPLATES[self::keyFor($financing)].' belum diunggah. Unggah di tombol Aturan pada menu Pembiayaan.');
        }

        $output = tempnam(sys_get_temp_dir(), 'fp').'.docx';
        copy($template, $output);
        $zip = new ZipArchive;
        $zip->open($output);
        $zip->addFromString('word/document.xml', $this->fillXml((string) $zip->getFromName('word/document.xml'), $financing));
        $zip->close();

        return $output;
    }

    public function fillXml(string $xml, Financing $f): string
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;
        $dom->loadXML($xml);
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::W);

        $c = $f->customer;
        $rupiah = fn ($v) => 'Rp '.number_format((int) $v, 0, ',', '.');
        $values = [
            'nama / nip' => trim(($c?->name ?? '').($c?->nip ? ' / '.$c->nip : '')),
            'nama' => $c?->name,
            'nip' => $c?->nip,
            'jabatan / peringkat jabatan' => implode(' / ', array_filter([$c?->position, $c?->grade])),
            'jabatan' => $c?->position,
            'bidang / seksi' => $c?->work_unit,
            'unit kerja' => $c?->work_unit,
            'nama bank' => $f->bank_name,
            'nomor rekening' => $f->bank_account,
            'nomor telepon' => $f->phone,
            'nama keluarga' => $f->family_name,
            'status keluarga' => $f->family_relation,
            'nomor telepon keluarga' => $f->family_phone,
            'jangka waktu pembiayaan' => $f->tenor ? "{$f->tenor} bulan" : null,
            'jangka waktu' => $f->tenor ? "{$f->tenor} bulan" : null,
            'nama barang' => $f->item_name,
            'harga barang' => $f->principal ? $rupiah($f->principal) : null,
            'angsuran per bulan' => $f->installment ? $rupiah($f->installment) : null,
        ];

        $paragraphs = iterator_to_array($xp->query('//w:body//w:p[not(ancestor::w:txbxContent)]'));
        $texts = array_map(fn ($p) => $this->text($xp, $p), $paragraphs);
        $signature = null; // urutan isian di blok tanda tangan: nama keluarga → nama pemohon → NIP pemohon

        foreach ($paragraphs as $i => $p) {
            $text = trim($texts[$i]);
            $lower = mb_strtolower($text);

            if (preg_match('/^nomor\s*:/i', $text) && str_contains($text, '/KOP/') && $f->number) {
                $this->setText($xp, $p, 'Nomor : '.$f->number);
            } elseif (preg_match('/^(.+?),\s*[….]{3,}$/u', $text, $m) && $f->applied_at) {
                $this->setText($xp, $p, $m[1].', '.str_replace(' ', "\u{00A0}", Carbon::parse($f->applied_at)->locale('id')->translatedFormat('j F Y')));
                foreach (iterator_to_array($xp->query('./w:pPr/w:jc', $p)) as $jc) {
                    $jc->setAttributeNS(self::W, 'w:val', 'left'); // rata kiri: tanggal tidak meregang
                }
            } elseif ($lower === 'jumlah pembiayaan') {
                $value = $this->valueParagraph($paragraphs, $texts, $i, true);
                if ($value !== null) {
                    // Kotak centang gambar di template (mis. menempel di paragraf ":") diganti ☑/☐ teks.
                    for ($j = $i + 1; $j < $value; $j++) {
                        foreach (iterator_to_array($xp->query('./w:r[.//w:drawing or .//w:pict]', $paragraphs[$j])) as $run) {
                            $run->parentNode->removeChild($run);
                        }
                    }
                    $this->setText($xp, $paragraphs[$value], $this->amountChoices($texts[$value], (int) $f->principal));
                }
            } elseif (array_key_exists($lower, $values) && $signature === null) {
                $value = $this->valueParagraph($paragraphs, $texts, $i);
                if ($value !== null && filled($values[$lower])) {
                    $this->setText($xp, $paragraphs[$value], (string) $values[$lower]);
                }
            } elseif (str_starts_with($lower, 'keluarga/suami/istri')) {
                $signature = 0;
            } elseif ($signature !== null && $signature < 2 && $this->isDots($text)) {
                $name = $signature === 0 ? $f->family_name : $c?->name;
                if (filled($name)) {
                    $this->setText($xp, $p, $name);
                }
                $signature++;
            } elseif ($signature === 2 && $lower === 'nip') {
                if ($c?->nip) {
                    $this->setText($xp, $p, 'NIP '.$c->nip);
                }
                $signature = 3; // NIP atasan langsung dibiarkan kosong
            }
        }

        // Kolom persetujuan: nama pengurus & tanggal per tahap.
        foreach ($xp->query('//w:tbl//w:tr') as $row) {
            $cells = iterator_to_array($xp->query('./w:tc', $row));
            $first = mb_strtolower(trim($this->text($xp, $cells[0] ?? $row)));
            [$name, $date] = match (true) {
                str_starts_with($first, 'diperiksa') => [$f->checker?->name, $f->checked_at],
                str_starts_with($first, 'direview') => [$f->reviewer?->name, $f->reviewed_at],
                $first === 'disetujui oleh' => [$f->approver?->name, $f->approved_at],
                default => [null, null],
            };
            if ($name && isset($cells[2])) {
                $this->setCell($xp, $cells[2], $name);
            }
            if ($date && isset($cells[4])) {
                $this->setCell($xp, $cells[4], Carbon::parse($date)->format('d/m/Y'));
            }
        }

        return $dom->saveXML();
    }

    /** Paragraf isian setelah label: lewati ":" lalu ambil paragraf titik-titik (atau berisi pilihan nominal). */
    private function valueParagraph(array $paragraphs, array $texts, int $label, bool $amount = false): ?int
    {
        for ($j = $label + 1; $j <= min($label + 3, count($paragraphs) - 1); $j++) {
            $t = trim($texts[$j]);
            if ($t === ':' || $t === '') {
                continue;
            }

            return $this->isDots($t) || ($amount && str_contains($t, 'Rp')) || str_contains($t, '…') ? $j : null;
        }

        return null;
    }

    private function amountChoices(string $text, int $principal): string
    {
        preg_match_all('/Rp\s*([\d.]+)/', $text, $m);
        $options = array_map(fn ($v) => (int) str_replace('.', '', $v), $m[1]);
        if (! in_array($principal, $options, true)) {
            $options[] = $principal;
        }

        return implode('       ', array_map(fn ($v) => ($v === $principal ? '☑' : '☐').' Rp '.number_format($v, 0, ',', '.'), $options));
    }

    private function isDots(string $text): bool
    {
        return $text !== '' && preg_match('/^[….\s_]+$/u', $text) && mb_strlen($text) >= 3;
    }

    private function text(DOMXPath $xp, DOMElement $node): string
    {
        $text = '';
        foreach ($xp->query('.//w:t[not(ancestor::w:txbxContent)]', $node) as $t) {
            $text .= $t->textContent;
        }

        return $text;
    }

    /** Ganti isi paragraf dengan satu teks, mempertahankan format paragraf & huruf run pertama. */
    private function setText(DOMXPath $xp, DOMElement $p, string $text): void
    {
        $runProps = $xp->query('./w:r/w:rPr', $p)->item(0);
        foreach (iterator_to_array($p->childNodes) as $child) {
            if (! ($child instanceof DOMElement && $child->localName === 'pPr')) {
                $p->removeChild($child);
            }
        }
        $run = $p->ownerDocument->createElementNS(self::W, 'w:r');
        if ($runProps) {
            $props = $runProps->cloneNode(true);
            foreach (iterator_to_array($xp->query('./w:noProof', $props)) as $noProof) {
                $props->removeChild($noProof);
            }
            $run->appendChild($props);
        }
        $t = $p->ownerDocument->createElementNS(self::W, 'w:t');
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($p->ownerDocument->createTextNode($text));
        $run->appendChild($t);
        $p->appendChild($run);
    }

    private function setCell(DOMXPath $xp, DOMElement $cell, string $text): void
    {
        $p = $xp->query('./w:p', $cell)->item(0);
        if ($p instanceof DOMElement) {
            $this->setText($xp, $p, $text);
        }
    }

    private function path(string $key): string
    {
        return self::DIR."/{$key}.docx";
    }
}
