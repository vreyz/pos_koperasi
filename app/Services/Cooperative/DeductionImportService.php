<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\MemberContribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Impor rekap potongan gaji koperasi (Simpanan Pinjaman/Wajib/Pokok/Sukarela, Angsuran
 * Koperasi, Potong Gaji Koperasi) per pegawai per bulan dengan langkah: pilih sheet &
 * baris judul → cocokkan kolom → cocokkan nama → pratinjau baru/sama/beda → simpan.
 * Data yang sama dilewati, yang beda harus dikonfirmasi, yang baru langsung disimpan.
 */
class DeductionImportService
{
    public const FIELDS = [
        'name' => 'Nama pegawai',
        'nip' => 'NIP',
        'month' => 'Bulan',
        'year' => 'Tahun',
        'pinjaman' => 'Simpanan Pinjaman',
        'wajib' => 'Simpanan Wajib',
        'pokok' => 'Simpanan Pokok',
        'sukarela' => 'Simpanan Sukarela',
        'angsuran' => 'Angsuran Koperasi',
        'pg' => 'Potong Gaji Koperasi',
    ];

    private const SYNONYMS = [
        'name' => ['nama pegawai', 'nama', 'nama anggota', 'n a m a'],
        'nip' => ['nip', 'nip pegawai'],
        'month' => ['bulan'],
        'year' => ['tahun'],
        'pinjaman' => ['pinjaman', 'simpanan pinjaman'],
        'wajib' => ['wajib', 'simpanan wajib'],
        'pokok' => ['pokok', 'simpanan pokok'],
        'sukarela' => ['sukarela', 'simpanan sukarela'],
        'angsuran' => ['angsuran koperasi', 'angsuran pinjaman', 'angsuran'],
        'pg' => ['pg koperasi', 'potong gaji koperasi', 'potong gaji', 'pg'],
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * Baris sheet; disimpan sementara sebagai JSON supaya langkah berikutnya tidak membaca ulang Excel besar.
     *
     * @return array<int, array<int, mixed>>
     */
    public function rows(string $path, string $sheet): array
    {
        $cache = 'cooperative-imports/'.basename($path).'.'.md5($sheet).'.json';
        if (Storage::disk('local')->exists($cache)) {
            return json_decode(Storage::disk('local')->get($cache), true);
        }
        $rows = array_map(fn ($row) => array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $row), $this->reader->readSheet($path, $sheet ?: null));
        Storage::disk('local')->put($cache, json_encode($rows));

        return $rows;
    }

    /**
     * Data untuk layar "pilih judul & kolom": 40 baris pertama, tebakan baris judul, dan tebakan kolom.
     */
    public function layout(string $path, ?string $sheet = null): array
    {
        $sheets = $this->reader->sheetNames($path);
        $sheet ??= $this->guessSheet($path, $sheets);
        if (! in_array($sheet, $sheets, true)) {
            throw new RuntimeException('Sheet tidak ditemukan.');
        }
        $rows = $this->rows($path, $sheet);
        [$from, $to] = $this->guessHeader($rows);
        $grid = [];
        foreach (array_slice($rows, 0, 40, true) as $number => $row) {
            $grid[] = ['row' => $number, 'cells' => array_map(fn ($v) => is_scalar($v) ? (string) $v : '', array_slice($row, 0, 40))];
        }

        return [
            'sheets' => $sheets,
            'sheet' => $sheet,
            'grid' => $grid,
            'header_from' => $from,
            'header_to' => $to,
            'columns' => $this->columns($rows, $from, $to),
            'mapping' => $this->guessMapping($rows, $from, $to),
            'total_rows' => count($rows),
        ];
    }

    /** Label tiap kolom = teks judul di baris judul (digabung bila bertingkat). */
    public function columns(array $rows, int $from, int $to): array
    {
        $count = 0;
        foreach ($rows as $number => $row) {
            if ($number >= $from && $number <= $to) {
                $count = max($count, count($row));
            }
        }
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $parts = [];
            for ($r = $from; $r <= $to; $r++) {
                $text = trim((string) (is_scalar($rows[$r][$i] ?? null) ? $rows[$r][$i] : ''));
                if ($text !== '' && ! in_array($text, $parts, true)) {
                    $parts[] = $text;
                }
            }
            $columns[] = ['index' => $i, 'letter' => $this->letter($i), 'label' => implode(' / ', $parts)];
        }

        return $columns;
    }

    public function guessMapping(array $rows, int $from, int $to): array
    {
        $mapping = array_fill_keys(array_keys(self::FIELDS), null);
        for ($r = $from; $r <= $to; $r++) {
            foreach ($rows[$r] ?? [] as $i => $cell) {
                $label = SpreadsheetReader::label($cell);
                foreach (self::SYNONYMS as $field => $labels) {
                    if ($mapping[$field] === null && in_array($label, $labels, true) && ! in_array($i, $mapping, true)) {
                        $mapping[$field] = $i;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Baca data sesuai pilihan user.
     *
     * @return list<array{nip: ?string, name: string, year: int, month: int, amounts: array<string, int>}>
     */
    public function parse(array $rows, int $headerTo, array $mapping): array
    {
        foreach (['name', 'month', 'year'] as $required) {
            if (! isset($mapping[$required]) || $mapping[$required] === null || $mapping[$required] === '') {
                throw new RuntimeException('Kolom '.self::FIELDS[$required].' wajib dipilih.');
            }
        }
        $types = array_values(array_filter(MemberContribution::DEDUCTION_TYPES, fn ($t) => isset($mapping[$t]) && $mapping[$t] !== null && $mapping[$t] !== ''));
        if ($types === []) {
            throw new RuntimeException('Pilih minimal satu kolom nominal (simpanan, angsuran, atau potong gaji).');
        }

        $parsed = [];
        foreach ($rows as $number => $row) {
            if ($number <= $headerTo) {
                continue;
            }
            $name = trim((string) ($row[(int) $mapping['name']] ?? ''));
            $year = (int) ($row[(int) $mapping['year']] ?? 0);
            $month = $this->month($row[(int) $mapping['month']] ?? null);
            if ($name === '' || is_numeric($name) || $year < 2000 || $year > 2100 || ! $month) {
                continue; // baris nomor kolom, total, atau kosong
            }
            $amounts = [];
            foreach ($types as $type) {
                $amounts[$type] = SpreadsheetReader::money($row[(int) $mapping[$type]] ?? 0);
            }
            $nip = isset($mapping['nip']) && $mapping['nip'] !== null && $mapping['nip'] !== '' ? Nip::normalize((string) ($row[(int) $mapping['nip']] ?? '')) : '';
            $parsed[] = ['nip' => $nip !== '' ? $nip : null, 'name' => $name, 'year' => $year, 'month' => $month, 'amounts' => $amounts];
        }
        if ($parsed === []) {
            throw new RuntimeException('Tidak ada baris data yang terbaca. Periksa baris judul dan kolom Nama/Bulan/Tahun.');
        }

        return $parsed;
    }

    /** Nama di file → pegawai (NIP, alias, nama persis), dengan saran untuk yang belum cocok. */
    public function names(array $parsed): array
    {
        $matcher = new NameMatcher;
        $byNip = Customer::whereNotNull('nip')->pluck('id', 'nip');
        $names = [];
        foreach ($parsed as $row) {
            $key = NameMatcher::normalize($row['name']);
            if (isset($names[$key])) {
                $names[$key]['rows']++;

                continue;
            }
            $id = ($row['nip'] ? $byNip[$row['nip']] ?? null : null) ?? $matcher->match($row['name']);
            $names[$key] = ['key' => $key, 'name' => $row['name'], 'nip' => $row['nip'], 'rows' => 1, 'customer_id' => $id,
                'suggestions' => $id ? [] : $matcher->suggestions($row['name'])];
        }
        $names = array_values($names);
        usort($names, fn ($a, $b) => [(bool) $a['customer_id'], $a['name']] <=> [(bool) $b['customer_id'], $b['name']]);

        return $names;
    }

    /**
     * Bandingkan file dengan data tersimpan per pegawai × jenis × bulan.
     * Nilai 0/kosong di file tidak menghapus data yang ada.
     *
     * @param  array<string, int|null>  $nameMap  nama ternormalisasi => customer_id (null = lewati)
     * @return array{cells: array<string, array<string, mixed>>, skipped_names: int}
     */
    public function diff(array $parsed, array $nameMap): array
    {
        $cells = [];
        $skipped = [];
        foreach ($parsed as $row) {
            $key = NameMatcher::normalize($row['name']);
            $customerId = isset($nameMap[$key]) ? (int) $nameMap[$key] : null;
            if (! $customerId) {
                $skipped[$key] = true;

                continue;
            }
            foreach ($row['amounts'] as $type => $amount) {
                $cellKey = "{$customerId}|{$type}|{$row['year']}|{$row['month']}";
                // Baris dobel di file untuk orang & bulan yang sama: dijumlahkan.
                $cells[$cellKey] ??= ['key' => $cellKey, 'customer_id' => $customerId, 'name' => $row['name'], 'type' => $type, 'year' => $row['year'], 'month' => $row['month'], 'new' => 0];
                $cells[$cellKey]['new'] += $amount;
            }
        }

        $existing = [];
        foreach (array_chunk(array_values(array_unique(array_column($cells, 'customer_id'))), 500) as $ids) {
            MemberContribution::whereIn('customer_id', $ids)->whereIn('type', MemberContribution::DEDUCTION_TYPES)
                ->selectRaw('customer_id, type, period_year, period_month, SUM(amount) as total, COUNT(*) as n')
                ->groupBy('customer_id', 'type', 'period_year', 'period_month')->get()
                ->each(function ($r) use (&$existing) {
                    $existing["{$r->customer_id}|{$r->type}|{$r->period_year}|{$r->period_month}"] = (int) $r->total;
                });
        }

        foreach ($cells as $k => $cell) {
            $old = $existing[$k] ?? null;
            $cells[$k]['old'] = $old;
            $cells[$k]['status'] = match (true) {
                $cell['new'] === 0 => 'same',            // kosong di file: tidak mengubah apa pun
                $old === null => 'new',
                $old === $cell['new'] => 'same',
                default => 'changed',
            };
        }

        return ['cells' => $cells, 'skipped_names' => count($skipped)];
    }

    public function summary(array $diff): array
    {
        $cells = $diff['cells'];
        $count = fn (string $status) => count(array_filter($cells, fn ($c) => $c['status'] === $status));
        $byType = [];
        foreach ($cells as $c) {
            if ($c['status'] === 'new') {
                $byType[$c['type']] = ($byType[$c['type']] ?? 0) + 1;
            }
        }
        $changed = array_values(array_filter($cells, fn ($c) => $c['status'] === 'changed'));
        usort($changed, fn ($a, $b) => [$a['name'], $a['year'], $a['month'], $a['type']] <=> [$b['name'], $b['year'], $b['month'], $b['type']]);
        $new = array_values(array_filter($cells, fn ($c) => $c['status'] === 'new'));

        return [
            'new' => $count('new'),
            'same' => $count('same'),
            'changed' => count($changed),
            'skipped_names' => $diff['skipped_names'],
            'new_by_type' => $byType,
            'changed_rows' => $changed,
            'new_sample' => array_slice($new, 0, 200),
            'years' => array_values(array_unique(array_column($cells, 'year'))),
        ];
    }

    /**
     * Simpan: baru → disimpan, sama → dilewati, beda → hanya yang dicentang (total bulan itu = nilai file).
     *
     * @param  list<string>  $confirmed  kunci sel "customer|jenis|tahun|bulan" yang disetujui diubah
     */
    public function commit(array $diff, array $confirmed, array $nameMap, array $parsed, ?int $userId): array
    {
        $confirmed = array_flip($confirmed);
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        $now = now();

        DB::transaction(function () use ($diff, $confirmed, $nameMap, $parsed, $userId, $now, &$result) {
            foreach ($parsed as $row) {
                $id = $nameMap[NameMatcher::normalize($row['name'])] ?? null;
                if ($id) {
                    NameMatcher::remember($row['name'], (int) $id);
                }
            }
            $insert = [];
            foreach ($diff['cells'] as $cell) {
                if ($cell['status'] === 'same' || ($cell['status'] === 'changed' && ! isset($confirmed[$cell['key']]))) {
                    $result['skipped']++;

                    continue;
                }
                $amount = $cell['new'];
                if ($cell['status'] === 'changed') {
                    $query = MemberContribution::where('customer_id', $cell['customer_id'])->where('type', $cell['type'])
                        ->where('period_year', $cell['year'])->where('period_month', $cell['month']);
                    (clone $query)->where('source', 'import')->delete();
                    $amount -= (int) (clone $query)->sum('amount'); // catatan manual/tunai tetap, selisihnya dicatat sebagai impor
                    $result['updated']++;
                } else {
                    $result['inserted']++;
                }
                if ($amount !== 0) {
                    $insert[] = ['customer_id' => $cell['customer_id'], 'type' => $cell['type'], 'period_year' => $cell['year'], 'period_month' => $cell['month'],
                        'amount' => $amount, 'source' => 'import', 'source_name' => $cell['name'], 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                MemberContribution::insert($chunk);
            }
        });

        app(MemberSync::class)->syncMembers();
        // Angsuran Koperasi = pembayaran cicilan pembiayaan pegawai yang bersangkutan.
        $financing = app(FinancingService::class);
        $result['installments_paid'] = 0;
        foreach (array_unique(array_column($diff['cells'], 'year')) as $year) {
            $result['installments_paid'] += $financing->markPaidFromPayroll((int) $year);
        }

        return $result;
    }

    private function guessSheet(string $path, array $sheets): string
    {
        // Sheet pertama yang punya kolom Nama, Bulan, Tahun (mis. "Master"); kalau tidak ada, sheet pertama.
        foreach ($sheets as $sheet) {
            $rows = $this->rows($path, $sheet);
            [$from, $to] = $this->guessHeader($rows);
            $map = $this->guessMapping($rows, $from, $to);
            if ($map['name'] !== null && $map['month'] !== null && $map['year'] !== null) {
                return $sheet;
            }
        }

        return $sheets[0] ?? '';
    }

    /** @return array{0: int, 1: int} baris judul pertama & terakhir */
    private function guessHeader(array $rows): array
    {
        $amountLabels = array_merge(...array_map(fn ($f) => self::SYNONYMS[$f], ['pinjaman', 'wajib', 'pokok', 'sukarela', 'angsuran', 'pg', 'month', 'year']));
        $from = null;
        foreach (array_slice($rows, 0, 30, true) as $number => $row) {
            $labels = array_map(fn ($c) => SpreadsheetReader::label($c), $row);
            if ($from === null && array_intersect($labels, self::SYNONYMS['name']) !== []) {
                $from = $number;
            }
        }
        $from ??= array_key_first($rows) ?? 1;
        $to = $from;
        foreach (array_slice($rows, 0, 30, true) as $number => $row) {
            if ($number > $from && $number <= $from + 3 && array_intersect(array_map(fn ($c) => SpreadsheetReader::label($c), $row), $amountLabels) !== []) {
                $to = $number;
            }
        }

        return [$from, $to];
    }

    private function month(mixed $value): ?int
    {
        if (is_numeric($value) && (int) $value >= 1 && (int) $value <= 12) {
            return (int) $value;
        }
        $names = ['jan' => 1, 'feb' => 2, 'peb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7, 'agu' => 8, 'agt' => 8,
            'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'nop' => 11, 'des' => 12, 'dec' => 12];

        return $names[substr(strtolower(trim((string) $value)), 0, 3)] ?? null;
    }

    private function letter(int $index): string
    {
        $letter = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }
}
