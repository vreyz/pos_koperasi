<?php

namespace App\Services\Cooperative;

use App\Models\BoardDecree;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * SK Pengurus Koperasi: membaca nama pengurus dari teks SK dan menghitung
 * bulan bertugas per tahun untuk SHU Pengurus Koperasi.
 */
class BoardService
{
    public const POSITIONS = ['Ketua', 'Wakil Ketua', 'Sekretaris', 'Bendahara', 'Ketua Pengawas', 'Pengawas', 'Kepala Unit Usaha', 'Anggota Pengurus'];

    private const MONTHS = ['JANUARI' => 1, 'FEBRUARI' => 2, 'MARET' => 3, 'APRIL' => 4, 'MEI' => 5, 'JUNI' => 6, 'JULI' => 7,
        'AGUSTUS' => 8, 'SEPTEMBER' => 9, 'OKTOBER' => 10, 'NOVEMBER' => 11, 'DESEMBER' => 12];

    /** Teks SK: Word dibaca di server, PDF dibaca di browser (dikirim sebagai teks). */
    public function text(?UploadedFile $file, ?string $clientText): string
    {
        if ($file && strtolower($file->getClientOriginalExtension()) === 'docx') {
            $zip = new ZipArchive;
            if ($zip->open($file->getRealPath()) === true) {
                $xml = (string) $zip->getFromName('word/document.xml');
                $zip->close();
                $xml = preg_replace(['/<\/w:p>/', '/<w:tab\/>/', '/<w:br\/>/', '/<\/w:tc>/'], ["\n", "\t", "\n", "\t"], $xml);

                return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return (string) $clientText;
    }

    /**
     * @return array{members: list<array{customer_id:int, name:string, nip:?string, position:?string}>, number: ?string, decree_date: ?string}
     */
    public function detect(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));
        $employees = Customer::employees()->get(['id', 'name', 'nip']);
        $byNip = $employees->filter(fn ($e) => $e->nip)->keyBy(fn ($e) => preg_replace('/\D/', '', $e->nip));
        $names = $employees->map(fn ($e) => ['id' => $e->id, 'norm' => NameMatcher::normalize($e->name)])
            ->filter(fn ($e) => str_contains($e['norm'], ' ') && strlen($e['norm']) >= 6)
            ->sortByDesc(fn ($e) => strlen($e['norm'])); // nama terpanjang dulu: "ADI SAPUTRA WIBOWO" sebelum "ADI SAPUTRA"

        $found = [];
        foreach ($lines as $i => $line) {
            $hits = [];
            preg_match_all('/\d[\d ]{16,24}\d/', $line, $m);
            foreach ($m[0] as $candidate) {
                $nip = preg_replace('/\D/', '', $candidate);
                if (isset($byNip[$nip])) {
                    $hits[] = $byNip[$nip]->id;
                }
            }
            $norm = ' '.NameMatcher::normalize($line).' ';
            foreach ($names as $e) {
                if (str_contains($norm, ' '.$e['norm'].' ')) {
                    $hits[] = $e['id'];
                    $norm = str_replace(' '.$e['norm'].' ', ' ', $norm);
                }
            }
            foreach (array_unique($hits) as $id) {
                $found[$id] ??= $this->position($line) ?? $this->position($lines[$i - 1] ?? '') ?? $this->position($lines[$i - 2] ?? '');
            }
        }

        $members = [];
        foreach ($found as $id => $position) {
            $e = $employees->firstWhere('id', $id);
            $members[] = ['customer_id' => $id, 'name' => $e->name, 'nip' => $e->nip, 'position' => $position];
        }

        preg_match('/NOMOR\s*:?\s*([A-Z0-9][A-Z0-9\/.\-]{3,})/i', $text, $number);

        return ['members' => $members, 'number' => $number[1] ?? null, 'decree_date' => $this->lastDate($text)];
    }

    private function position(string $line): ?string
    {
        $norm = ' '.NameMatcher::normalize($line).' ';
        foreach ([' WAKIL KETUA ' => 'Wakil Ketua', ' KETUA PENGAWAS ' => 'Ketua Pengawas', ' ANGGOTA PENGAWAS ' => 'Pengawas', ' PENGAWAS ' => 'Pengawas',
            ' SEKRETARIS ' => 'Sekretaris', ' BENDAHARA ' => 'Bendahara', ' KEPALA UNIT USAHA ' => 'Kepala Unit Usaha', ' KETUA ' => 'Ketua'] as $key => $label) {
            if (str_contains($norm, $key)) {
                return $label;
            }
        }

        return null;
    }

    /** Tanggal terakhir di teks (biasanya tanggal penetapan di bagian tanda tangan). */
    private function lastDate(string $text): ?string
    {
        preg_match_all('/(\d{1,2})\s+('.implode('|', array_keys(self::MONTHS)).')\s+(\d{4})/i', $text, $m, PREG_SET_ORDER);
        $last = end($m);
        if (! $last || ! checkdate(self::MONTHS[strtoupper($last[2])], (int) $last[1], (int) $last[3])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $last[3], self::MONTHS[strtoupper($last[2])], $last[1]);
    }

    /**
     * Pengurus per bulan di tahun $year menurut masa berlaku SK. Satu bulan dihitung untuk SK
     * yang berlaku pada akhir bulan itu; SK berlaku sampai tanggal "berlaku sampai" atau
     * sehari sebelum SK berikutnya mulai.
     *
     * @return list<array{customer_id:int, position:?string, months: list<int>}>
     */
    public function membersForYear(int $year): array
    {
        $decrees = BoardDecree::with('members')->orderBy('effective_from')->orderBy('id')->get()->values();
        $result = [];

        foreach (range(1, 12) as $month) {
            $endOfMonth = CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay();
            $decree = null;
            foreach ($decrees as $i => $d) {
                $next = $decrees[$i + 1] ?? null;
                $until = $d->effective_until ?? $next?->effective_from->copy()->subDay();
                if ($d->effective_from->lte($endOfMonth) && (! $until || $until->gte($endOfMonth))) {
                    $decree = $d; // SK paling akhir yang masih berlaku menang
                }
            }
            foreach ($decree?->members ?? [] as $member) {
                $result[$member->customer_id] ??= ['customer_id' => $member->customer_id, 'position' => $member->position, 'months' => []];
                $result[$member->customer_id]['months'][] = $month;
                $result[$member->customer_id]['position'] = $member->position ?: $result[$member->customer_id]['position'];
            }
        }

        return array_values($result);
    }
}
