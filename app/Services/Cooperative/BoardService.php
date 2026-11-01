<?php

namespace App\Services\Cooperative;

use App\Models\BoardDecree;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * SK Pengurus Koperasi: membaca nama pengurus dari teks SK dan menghitung
 * bulan bertugas per tahun untuk SHU Pengurus Koperasi.
 */
class BoardService
{
    public const POSITIONS = ['Pembina', 'Ketua', 'Wakil Ketua', 'Sekretaris', 'Bendahara', 'Ketua Pengawas', 'Pengawas', 'Kepala Unit Usaha', 'Anggota Pengurus'];

    /** Teks SK: Word dibaca di server, PDF dibaca di browser (dikirim sebagai teks). */
    public function text(?UploadedFile $file, ?string $clientText): string
    {
        if ($file && strtolower($file->getClientOriginalExtension()) === 'docx') {
            $zip = new ZipArchive;
            if ($zip->open($file->getRealPath()) === true) {
                $xml = (string) $zip->getFromName('word/document.xml');
                $zip->close();

                return $this->docxText($xml);
            }
        }

        return (string) $clientText;
    }

    /**
     * Baris tabel Word jadi satu baris "Jabatan<TAB>:<TAB>Nama"; paragraf lanjutan di sel
     * (nama ke-2, ke-3, ...) jadi baris berikutnya sehingga tetap ikut jabatan di atasnya.
     */
    private function docxText(string $xml): string
    {
        $paragraphs = function (string $fragment): array {
            preg_match_all('/<w:p[ >].*?<\/w:p>/s', $fragment, $m);

            return array_values(array_filter(array_map(fn ($p) => trim(html_entity_decode(strip_tags(preg_replace('/<w:(tab|br)\/>/', ' ', $p)), ENT_QUOTES | ENT_XML1, 'UTF-8')), $m[0]), fn ($t) => $t !== ''));
        };
        $xml = preg_replace_callback('/<w:tr[ >].*?<\/w:tr>/s', function ($row) use ($paragraphs) {
            preg_match_all('/<w:tc[ >].*?<\/w:tc>/s', $row[0], $cells);
            $first = [];
            $more = [];
            foreach ($cells[0] as $cell) {
                $texts = $paragraphs($cell);
                $first[] = $texts[0] ?? '';
                array_push($more, ...array_slice($texts, 1));
            }

            return '<w:p><w:r><w:t>'.htmlspecialchars(implode("\t", $first)).'</w:t></w:r></w:p>'
                .implode('', array_map(fn ($t) => '<w:p><w:r><w:t>'.htmlspecialchars($t).'</w:t></w:r></w:p>', $more));
        }, $xml);

        return implode("\n", $paragraphs($xml));
    }

    /**
     * Membaca susunan pengurus dari teks SK. Pola SK: "Jabatan : Nama" lalu nama berikutnya
     * di baris bawah (1., 2., ...) ikut jabatan itu sampai ada "Jabatan :" baru. Label yang
     * terpotong ke baris berikutnya ("Divisi Pembiayaan" / "Barang") disambung. Nama dicocokkan
     * lewat NIP, nama persis/alias, atau nama mirip; yang tidak ketemu tetap ditampilkan
     * (customer_id null) supaya dipilih manual.
     *
     * @return array{members: list<array{customer_id: ?int, name: string, nip: ?string, position: ?string, sk_name: string, check: bool}>, number: ?string, decree_date: ?string}
     */
    public function detect(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));
        $matcher = new NameMatcher;
        $employees = $matcher->employees()->keyBy('id');
        $byNip = $employees->filter(fn ($e) => $e['nip'])->mapWithKeys(fn ($e) => [preg_replace('/\D/', '', $e['nip']) => $e['id']]);

        $entries = [];
        $label = null;
        $labelLine = -1;
        $isSub = false; // label diawali "-" (divisi di bawah suatu bagian)
        foreach ($lines as $i => $line) {
            $clean = trim(preg_replace('/^[\-\x{2013}\x{2022}\x{00B7}*]+\s*/u', '', $line));
            $value = $clean;
            // Titik dua di baris sendiri (label di baris atas, mis. kolom label rata tengah): baris atas = jabatan.
            if (str_starts_with($clean, ':') && $i > 0 && ! str_contains($lines[$i - 1], ':')) {
                // Hingga 2 baris di atas tanpa ":" (mis. "- Divisi Pembiayaan" / "Barang") = potongan label.
                $from = $i - 1;
                $resolvedLines = array_column(array_filter($entries, fn ($e) => $e['customer_id'] !== null), 'line');
                while ($from > 0 && $i - $from < 2 && ! str_contains($lines[$from - 1], ':') && ! preg_match('/^\d/', $lines[$from - 1])
                    && ! preg_match('/^[\-\x{2013}\x{2022}\x{00B7}*]/u', $lines[$from]) && ! in_array($from - 1, $resolvedLines, true)) {
                    $from--;
                }
                $entries = array_values(array_filter($entries, fn ($e) => $e['line'] < $from));
                $parts = array_map(fn ($l) => trim(preg_replace('/^[\-\x{2013}\x{2022}\x{00B7}*]+\s*/u', '', $l)), array_slice($lines, $from, $i - $from));
                $label = $this->cleanLabel(implode(' ', $parts));
                $isSub = (bool) preg_match('/^[\-\x{2013}\x{2022}\x{00B7}*]/u', $lines[$from]);
                $labelLine = $i;
                $value = trim(substr($clean, 1));
            } elseif (preg_match('/^([^:]{2,80}?)\s*:\s*(.*)$/u', $clean, $m) && ! preg_match('/^\d+[.)]/', $m[1])) {
                $label = $this->cleanLabel($m[1]);
                $value = $m[2];
                $labelLine = $i;
                $isSub = (bool) preg_match('/^[\-\x{2013}\x{2022}\x{00B7}*]/u', $line);
            } elseif (preg_match('/^(pembina|wakil ketua|ketua pengawas|anggota pengawas|ketua|sekretaris|bendahara|pengawas|kepala unit usaha|penasehat|manajer)\s*:?$/i', $clean)) {
                $label = $this->cleanLabel($clean); // jabatan di baris sendiri, nama di baris bawahnya
                $labelLine = $i;
                $isSub = false;

                continue;
            } elseif ($label !== null && $i === $labelLine + 1 && $clean === $line && ! str_starts_with(trim($lines[$i + 1] ?? ''), ':')
                && $this->isLabelContinuation($clean, $matcher)) {
                $label = $this->cleanLabel($label.' '.$clean);
                foreach ($entries as $k => $entry) {
                    if ($entry['line'] === $labelLine) {
                        $entries[$k]['position'] = $label;
                    }
                }

                continue;
            }
            if ($label === null) {
                continue;
            }
            foreach ($this->segments($value) as $segment) {
                [$id, $check] = $this->resolve($segment, $matcher, $byNip);
                if ($id === null && ! $this->looksLikeName($segment)) {
                    continue;
                }
                $entries[] = ['customer_id' => $id, 'check' => $check, 'sk_name' => $segment, 'position' => $label, 'sub' => $isSub, 'line' => $i, 'block' => $labelLine];
            }
        }

        // Blok jabatan dipakai bila memuat pegawai yang dikenali, atau terapit blok yang memuatnya
        // (mis. "Kepala Unit Usaha" yang namanya belum ada di Data Pegawai). "Menimbang", "Ditetapkan di", dst. terbuang.
        $blocks = array_values(array_unique(array_column($entries, 'block')));
        $known = array_unique(array_column(array_filter($entries, fn ($e) => $e['customer_id'] !== null), 'block'));
        $validBlocks = [];
        foreach ($blocks as $k => $block) {
            if (in_array($block, $known, true) || (in_array($blocks[$k - 1] ?? null, $known, true) && in_array($blocks[$k + 1] ?? null, $known, true))) {
                $validBlocks[] = $block;
            }
        }
        $members = [];
        $seen = [];
        $blockHeadTaken = [];
        foreach ($entries as $entry) {
            if (! in_array($entry['block'], $validBlocks, true)) {
                continue;
            }
            $skPosition = $entry['position'];
            $entry['position'] = $this->standardPosition($skPosition, $entry['sub'], ! isset($blockHeadTaken[$entry['block']]));
            $blockHeadTaken[$entry['block']] = true;
            $key = $entry['customer_id'] ? 'id:'.$entry['customer_id'] : 'raw:'.NameMatcher::normalize($entry['sk_name']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $employee = $entry['customer_id'] ? $employees[$entry['customer_id']] : null;
            $members[] = [
                'customer_id' => $entry['customer_id'],
                'name' => $employee['name'] ?? $entry['sk_name'],
                'nip' => $employee['nip'] ?? null,
                'position' => $entry['position'],
                'sk_position' => $skPosition,
                'sk_name' => $entry['sk_name'],
                'check' => $entry['check'],
            ];
        }

        preg_match('/NOMOR\s*:?\s*([A-Z0-9][A-Z0-9\/.\-]{3,})/i', $text, $number);

        return ['members' => $members, 'number' => $number[1] ?? null, 'decree_date' => $this->decreeDate($text)];
    }

    /**
     * Jabatan baku: Pembina, Ketua, Wakil Ketua, Sekretaris, Bendahara, Ketua Pengawas, Pengawas, Kepala Unit Usaha,
     * Anggota Pengurus. Divisi bertanda "-" (Divisi Toko, Divisi Pembiayaan, ...) = Anggota Pengurus; bagian lain
     * (Akuntansi dan Pelaporan, IT Support, ...) = orang pertama Kepala Unit Usaha, sisanya Anggota Pengurus.
     */
    private function standardPosition(string $label, bool $isSub, bool $firstInBlock): string
    {
        $l = mb_strtolower($label);
        $standard = match (true) {
            str_starts_with($l, 'pembina') => 'Pembina',
            str_contains($l, 'pengawas') => str_starts_with($l, 'ketua') ? 'Ketua Pengawas' : 'Pengawas',
            str_starts_with($l, 'wakil ketua') => 'Wakil Ketua',
            $l === 'ketua' || str_starts_with($l, 'ketua ') => 'Ketua',
            str_starts_with($l, 'sekretaris') => 'Sekretaris',
            str_starts_with($l, 'bendahara') => 'Bendahara',
            str_starts_with($l, 'kepala unit usaha') || str_starts_with($l, 'manajer') => 'Kepala Unit Usaha',
            default => null,
        };
        if ($standard !== null && ! $isSub) {
            return $standard;
        }

        return ! $isSub && $firstInBlock ? 'Kepala Unit Usaha' : 'Anggota Pengurus';
    }

    /** "1. Andi, S.E. 2. Budi NIP 1980..." → ["Andi NIP 1980...", "Budi NIP 1980..."] tanpa gelar. */
    private function segments(string $value): array
    {
        $parts = preg_split('/(?:^|\s+)\d{1,2}[.)]\s+|\t+|\s{3,}|;/u', $value) ?: [];
        $result = [];
        foreach ($parts as $part) {
            $part = preg_replace('/^(sdr|sdri|sdra|bapak|ibu|bpk)\.?\s+/i', '', trim($part, " \t.,:"));
            // Buang gelar di belakang koma: "Benny Wisuda, S.E., M.Ak."
            $pieces = array_map('trim', explode(',', $part));
            $name = array_shift($pieces);
            foreach ($pieces as $piece) {
                if (strlen(str_replace(['.', ' '], '', $piece)) > 6) {
                    $name .= ', '.$piece; // bukan gelar
                }
            }
            if (trim($name) !== '') {
                $result[] = trim($name);
            }
        }

        return $result;
    }

    /** @return array{0: ?int, 1: bool} [customer_id, perlu diperiksa] */
    private function resolve(string $segment, NameMatcher $matcher, $byNip): array
    {
        if (preg_match('/\d[\d ]{16,24}\d/', $segment, $m) && isset($byNip[preg_replace('/\D/', '', $m[0])])) {
            return [$byNip[preg_replace('/\D/', '', $m[0])], false];
        }
        $name = trim(preg_replace('/\bNIP\b.*$/i', '', $segment));
        if ($name === '' || strlen(NameMatcher::normalize($name)) < 3) {
            return [null, false];
        }
        if ($id = $matcher->match($name)) {
            return [$id, false];
        }
        $suggestions = $matcher->suggestions($name, 2, 80);
        $top = $suggestions[0] ?? null;
        if ($top && ($top['score'] >= 90 || count($suggestions) === 1)) {
            return [$top['id'], true];
        }

        return [null, false];
    }

    private function looksLikeName(string $segment): bool
    {
        $name = trim(preg_replace('/\bNIP\b.*$/i', '', $segment));
        $words = preg_split('/\s+/', $name) ?: [];
        if ($name === '' || count($words) > 5 || mb_strlen($name) > 50 || preg_match('/\d/', $name)) {
            return false;
        }
        foreach ($words as $word) {
            if (! preg_match("/^[\p{Lu}][\p{L}.'\-]*$/u", $word)) {
                return false; // nama: setiap kata diawali huruf besar
            }
        }

        return ! preg_match('/\b(koperasi|nomor|tahun|tentang|keputusan|rapat|anggota|pengurus|kantor|wilayah|direktorat|jenderal|divisi|bagian|seksi|bidang|unit|support)\b/i', $name);
    }

    /** Baris pendek tanpa ":" dan bukan nama, tepat setelah baris jabatan: sambungan label (mis. "Barang"). */
    private function isLabelContinuation(string $line, NameMatcher $matcher): bool
    {
        $words = preg_split('/\s+/', $line) ?: [];

        return count($words) <= 3 && ! preg_match('/[\d:]/', $line) && preg_match('/^\p{Lu}/u', $line)
            && $matcher->match($line) === null && $matcher->suggestions($line, 1, 70) === [];
    }

    private function cleanLabel(string $label): string
    {
        $label = trim(preg_replace(['/^[a-z0-9]{1,2}[.)]\s+/i', '/\s+/'], ['', ' '], trim($label, " \t-:")));

        if (mb_strtoupper($label) !== $label) {
            return $label;
        }
        // HURUF BESAR → Judul: "AKUNTANSI DAN PELAPORAN" → "Akuntansi dan Pelaporan", singkatan 2 huruf tetap ("IT").
        $words = array_map(function ($word) {
            $lower = mb_strtolower($word);
            if (in_array($lower, ['dan', 'di', 'ke', 'dari', 'untuk', 'atau'], true)) {
                return $lower;
            }

            return mb_strlen($word) <= 2 ? $word : mb_convert_case($lower, MB_CASE_TITLE);
        }, explode(' ', $label));

        return ucfirst(implode(' ', $words));
    }

    /**
     * Tanggal SK = tanggal di bagian "Ditetapkan di ... pada tanggal ...". Tanggal lain di SK (mis. Anggaran
     * Dasar 2021 di "Mengingat") diabaikan. Bila tanggal penetapan tidak ditemukan, pakai tanggal terakhir di teks.
     */
    private function decreeDate(string $text): ?string
    {
        if (preg_match_all('/ditetapkan\s+di|pada\s+tanggal/i', $text, $m, PREG_OFFSET_CAPTURE) && $m[0] !== []) {
            $anchor = $m[0][0][1];
            foreach ($m[0] as $hit) { // "Ditetapkan di" terakhir: blok tanda tangan
                if (stripos($hit[0], 'ditetapkan') !== false) {
                    $anchor = $hit[1];
                }
            }
            $dates = $this->dates(substr($text, $anchor, 400), true);
            if ($dates !== []) {
                return $dates[0];
            }
        }
        $dates = $this->dates($text, false);

        return $dates === [] ? null : end($dates);
    }

    /**
     * Semua tanggal di teks, urut kemunculan: "5 Januari 2026", "05-01-2026", "5 Agt 2026", dan bila $monthOnly
     * juga "Januari 2026" (tanggal dikosongkan untuk ditulis tangan) → tanggal 1.
     *
     * @return list<string>
     */
    private function dates(string $text, bool $monthOnly): array
    {
        $months = '(januari|februari|pebruari|maret|april|mei|juni|juli|agustus|september|oktober|november|nopember|desember|jan|feb|peb|mar|apr|jun|jul|agu|agt|ags|agust|sep|sept|okt|nov|nop|des)\.?';
        $pattern = '/(?<![\d])(\d{1,2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(\d{4})|(?:(\d{1,2})\s+)?\b'.$months.'\s+(\d{4})/iu';
        preg_match_all($pattern, $text, $m, PREG_SET_ORDER);
        $result = [];
        foreach ($m as $hit) {
            if (($hit[1] ?? '') !== '') {
                [$day, $month, $year] = [(int) $hit[1], (int) $hit[2], (int) $hit[3]];
            } else {
                if (($hit[4] ?? '') === '' && ! $monthOnly) {
                    continue;
                }
                $day = ($hit[4] ?? '') !== '' ? (int) $hit[4] : 1;
                $month = $this->monthNumber($hit[5]);
                $year = (int) $hit[6];
            }
            if ($month && checkdate($month, $day, $year)) {
                $result[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return $result;
    }

    private function monthNumber(string $name): ?int
    {
        $prefix = substr(strtolower(rtrim($name, '.')), 0, 3);

        return ['jan' => 1, 'feb' => 2, 'peb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8,
            'ags' => 8, 'sep' => 9, 'okt' => 10, 'nov' => 11, 'nop' => 11, 'des' => 12][$prefix] ?? null;
    }

    /** Nama pemegang jabatan (mis. "Ketua") menurut SK yang berlaku hari ini. */
    public function currentHolder(string $position): ?string
    {
        $today = now()->toDateString();
        $decree = BoardDecree::with('members.customer:id,name')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->orderByDesc('effective_from')->orderByDesc('id')->first();

        $members = $decree?->members ?? collect();
        $exact = $members->first(fn ($m) => strcasecmp(trim((string) $m->position), $position) === 0);
        // "Ketua Koperasi" juga dihitung sebagai Ketua (tapi bukan "Ketua Pengawas").
        $prefix = $members->first(fn ($m) => str_starts_with(mb_strtolower(trim((string) $m->position)), mb_strtolower($position).' ')
            && ! str_contains(mb_strtolower((string) $m->position), 'pengawas'));

        return ($exact ?? $prefix)?->customer?->name;
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
