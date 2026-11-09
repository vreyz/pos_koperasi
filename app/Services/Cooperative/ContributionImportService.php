<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\MemberContribution;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Import simpanan dari Excel. Tiga format dikenali otomatis (boleh campur dalam satu file):
 *
 * 1. Sheet "Master" potongan gaji: Nama Pegawai, Wajib, Pokok, Sukarela, Angsuran Koperasi, Bulan, Tahun (NIP opsional).
 *    Format unduhan aplikasi memakai kolom "Total Pokok/Wajib/Sukarela" = total bulan itu (termasuk catatan manual).
 * 2. Rekap bulanan: satu baris per nama dengan kolom JAN s.d. DES (mis. "rekap simpanan ppnpn"). Tahun dari baris
 *    judul ("Periode : 2025") atau nama sheet; jenis simpanan dari judul/nama sheet/nama file (bawaan: wajib).
 * 3. Pengembalian/penarikan simpanan: kolom Nama, Tanggal, "Pengembalian Wajib/Pokok/Sukarela" → dicatat negatif
 *    (pengurang saldo) di bulan tanggal itu.
 *
 * Sebelum disimpan, {@see validate()} memeriksa isi file (total vs baris Jumlah, periode, saldo cukup untuk
 * pengembalian, status pegawai, kemungkinan dobel). Kesalahan (level error) menahan penyimpanan.
 * Import ulang file yang sama tidak membuat dobel: catatan import lama di periode & jenis yang sama diganti
 * (setoran mengganti setoran, pengembalian mengganti pengembalian).
 */
class ContributionImportService
{
    private const COLUMNS = [
        'nip' => ['nip'],
        'name' => ['nama pegawai', 'nama', 'n a m a', 'nama anggota'],
        'pokok' => ['pokok', 'simpanan pokok'],
        'wajib' => ['wajib', 'simpanan wajib'],
        'sukarela' => ['sukarela', 'simpanan sukarela'],
        'angsuran' => ['angsuran koperasi', 'angsuran pinjaman', 'angsuran'],
        'total_pokok' => ['total pokok'],
        'total_wajib' => ['total wajib'],
        'total_sukarela' => ['total sukarela'],
        'month' => ['bulan'],
        'year' => ['tahun'],
    ];

    private const MONTHS = [
        'jan' => 1, 'januari' => 1, 'january' => 1,
        'feb' => 2, 'februari' => 2, 'pebruari' => 2, 'february' => 2,
        'mar' => 3, 'maret' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'mei' => 5, 'may' => 5,
        'jun' => 6, 'juni' => 6, 'june' => 6,
        'jul' => 7, 'juli' => 7, 'july' => 7,
        'ags' => 8, 'agu' => 8, 'agt' => 8, 'agust' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'okt' => 10, 'oktober' => 10, 'oct' => 10, 'october' => 10,
        'nov' => 11, 'nopember' => 11, 'november' => 11,
        'des' => 12, 'desember' => 12, 'dec' => 12, 'december' => 12,
    ];

    private const TOTAL_LABELS = ['jumlah', 'total', 'jumlah total', 'grand total'];

    private const MAX_DETAILS = 60;

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $path, string $fileName = ''): array
    {
        return $this->inspect($path, $fileName)['rows'];
    }

    /**
     * Baca semua sheet yang dikenali.
     *
     * @return array{rows: array<int, array<string, mixed>>, sheets: array<int, array<string, mixed>>}
     */
    public function inspect(string $path, string $fileName = ''): array
    {
        $sheets = $this->reader->readAll($path, fn (array $rows) => $this->headerMap($rows) !== null
            || $this->wideHeader($rows) !== null
            || $this->withdrawalHeader($rows) !== null);
        if ($sheets === []) {
            throw new RuntimeException('Format tidak dikenali. Butuh salah satu: (1) kolom Nama, Tahun, Bulan + Pokok/Wajib/Sukarela; (2) kolom Nama + JAN s.d. DES; (3) kolom Nama, Tanggal + Pengembalian Wajib/Sukarela.');
        }

        $rows = [];
        $info = [];
        // Beberapa sheet Master berformat sama (mis. "Master" s.d. 2025 dan "Master (backup)" s.d. 2026): pakai yang datanya paling baru.
        $bestMaster = null;
        foreach ($sheets as $title => $sheetRows) {
            if ($map = $this->headerMap($sheetRows)) {
                $parsed = $this->parseMasterRows($sheetRows, $map, $title);
                $latest = $parsed === [] ? 0 : max(array_map(fn ($r) => $r['year'] * 100 + $r['month'], $parsed));
                if ($bestMaster === null || [$latest, count($parsed)] > [$bestMaster[0], count($bestMaster[1])]) {
                    $bestMaster = [$latest, $parsed, $title];
                }

                continue;
            }
            [$sheetParsed, $sheetInfo] = $this->wideHeader($sheetRows) !== null
                ? $this->parseWide($sheetRows, $title, $fileName)
                : $this->parseWithdrawals($sheetRows, $title);
            $rows = array_merge($rows, $sheetParsed);
            $info[] = $sheetInfo;
        }
        if ($bestMaster) {
            $rows = array_merge($bestMaster[1], $rows);
            array_unshift($info, ['sheet' => $bestMaster[2], 'format' => 'master', 'label' => 'Rekap potongan (Nama, Bulan, Tahun)', 'rows' => count($bestMaster[1]), 'checks' => []]);
        }
        if ($rows === []) {
            throw new RuntimeException('Sheet dikenali tetapi tidak ada baris data (nama + nilai simpanan) yang bisa dibaca.');
        }

        // Rekap bulanan dengan jenis & tahun sama di dua sheet: kemungkinan dobel.
        $seen = [];
        foreach ($info as $i => $s) {
            if (($s['format'] ?? '') === 'wide' && $s['year']) {
                $key = $s['type'].'|'.$s['year'];
                if (isset($seen[$key])) {
                    $info[$i]['checks'][] = $this->check('error', "Sheet \"{$s['sheet']}\" dan \"{$seen[$key]}\" sama-sama berisi ".MemberContribution::TYPE_LABELS[$s['type']]." tahun {$s['year']}. Hapus salah satu sheet agar tidak dobel.");
                }
                $seen[$key] = $s['sheet'];
            }
        }

        return ['rows' => $rows, 'sheets' => $info];
    }

    /**
     * Ringkas per nama untuk layar pencocokan.
     *
     * @return array{names: array<int, array<string, mixed>>, years: array<int, int>, rows: int}
     */
    public function preview(array $parsed): array
    {
        $matcher = new NameMatcher;
        $byNip = Customer::whereNotNull('nip')->pluck('id', 'nip');
        $names = [];
        $years = [];

        foreach ($parsed as $row) {
            $key = NameMatcher::normalize($row['name']);
            $years[$row['year']] = true;
            if (! isset($names[$key])) {
                // Nama yang ditulis dengan keterangan jenis pegawai (mis. "Budi PPNPN") dicocokkan tanpa keterangan itu.
                $bare = trim(preg_replace('/\b(PPNPN|PNS|PPPK|HONORER)\b/i', ' ', $row['name']));
                $matchedId = ($row['nip'] ? $byNip[$row['nip']] ?? null : null) ?? $matcher->match($row['name']) ?? $matcher->match($bare);
                $names[$key] = [
                    'key' => $key,
                    'name' => $row['name'],
                    'rows' => 0,
                    'total' => 0,
                    'withdrawn' => 0,
                    'first' => null,
                    'last' => null,
                    'customer_id' => $matchedId,
                    'suggestions' => $matchedId ? [] : ($matcher->suggestions($row['name']) ?: $matcher->suggestions($bare)),
                ];
            }
            $period = sprintf('%04d-%02d', $row['year'], $row['month']);
            $amount = array_sum($row['amounts']) + array_sum($row['totals'] ?? []);
            $names[$key]['rows']++;
            if (($row['kind'] ?? 'deposit') === 'withdrawal') {
                $names[$key]['withdrawn'] += -$amount;
            } else {
                $names[$key]['total'] += $amount;
            }
            $names[$key]['first'] = min($names[$key]['first'] ?? $period, $period);
            $names[$key]['last'] = max($names[$key]['last'] ?? $period, $period);
        }

        $names = array_values($names);
        usort($names, fn ($a, $b) => [(bool) $a['customer_id'], $a['name']] <=> [(bool) $b['customer_id'], $b['name']]);
        $years = array_keys($years);
        sort($years);

        return ['names' => $names, 'years' => $years, 'rows' => count($parsed)];
    }

    /** Pemetaan otomatis dari pratinjau (nama yang cocok → id pegawai). */
    public function autoMapping(array $preview): array
    {
        return collect($preview['names'])->mapWithKeys(fn ($n) => [$n['key'] => $n['customer_id']])->all();
    }

    /**
     * Validasi isi file dengan pemetaan nama yang dipilih. Urutan: error, peringatan, info, ok.
     *
     * @param  array{rows: array, sheets: array}  $data  hasil {@see inspect()}
     * @param  array<string, int|string|null>  $mapping  nama ternormalisasi => customer_id | 'new' | null
     * @return array<int, array{level: string, title: string, details: array<int, string>}>
     */
    public function validate(array $data, array $mapping): array
    {
        $checks = [];
        foreach ($data['sheets'] as $sheet) {
            foreach ($sheet['checks'] as $check) {
                $checks[] = $check;
            }
        }
        $rows = $data['rows'];
        $rupiah = fn ($v) => 'Rp '.number_format((int) $v, 0, ',', '.');
        $period = fn ($r) => sprintf('%04d-%02d', $r['year'], $r['month']);

        $ids = array_values(array_unique(array_filter($mapping, 'is_int')));
        $people = Customer::whereIn('id', $ids)->get(['id', 'name', 'employee_type', 'employment_status', 'active_until'])->keyBy('id');
        $resolve = fn ($row) => $mapping[NameMatcher::normalize($row['name'])] ?? null;

        // Nama belum dipilih / pegawai baru.
        $skipped = [];
        $created = [];
        foreach ($rows as $row) {
            $choice = $resolve($row);
            if ($choice === 'new') {
                $created[$row['name']] = true;
            } elseif (! $choice) {
                $skipped[$row['name']] = ($skipped[$row['name']] ?? 0) + abs(array_sum($row['amounts']) + array_sum($row['totals'] ?? []));
            }
        }
        if ($skipped) {
            $checks[] = $this->check('warning', count($skipped).' nama belum dipilih pegawainya, datanya tidak disimpan ('.$rupiah(array_sum($skipped)).').',
                array_map(fn ($n, $v) => "{$n} — {$rupiah($v)}", array_keys($skipped), $skipped));
        }
        if ($created) {
            $checks[] = $this->check('info', count($created).' nama akan dibuat sebagai pegawai baru (jenis LAINNYA, status TIDAK DI DAFTAR PEGAWAI).', array_keys($created));
        }

        // Periode di masa depan.
        $now = (int) now()->format('Ym');
        $future = array_filter($rows, fn ($r) => $r['year'] * 100 + $r['month'] > $now && (array_filter($r['amounts']) || array_filter($r['totals'] ?? [])));
        if ($future) {
            $checks[] = $this->check('error', count($future).' baris berisi periode setelah bulan ini. Periksa tahun/bulan di file.',
                array_map(fn ($r) => "{$r['name']} — {$period($r)}", array_values($future)));
        }

        // Setoran: status pegawai, dua nama ke satu pegawai, catatan non-impor yang bisa dobel.
        $deposits = array_values(array_filter($rows, fn ($r) => ($r['kind'] ?? 'deposit') === 'deposit'));
        $inactive = [];
        $owners = [];
        $keys = [];
        foreach ($deposits as $row) {
            $id = $resolve($row);
            if (! is_int($id) || ! array_filter($row['amounts'] + ($row['totals'] ?? []))) {
                continue;
            }
            $person = $people[$id] ?? null;
            $end = sprintf('%04d-%02d-28', $row['year'], $row['month']);
            if ($person && $person->employment_status !== 'AKTIF' && (! $person->active_until || $person->active_until->format('Y-m-d') < $end)) {
                $inactive[$id] ??= ['name' => $person->name, 'status' => $person->employment_status, 'from' => $period($row)];
                $inactive[$id]['from'] = min($inactive[$id]['from'], $period($row));
            }
            foreach (array_keys(array_filter($row['amounts'] + ($row['totals'] ?? []))) as $type) {
                $key = "{$id}|{$type}|{$row['year']}|{$row['month']}";
                $owners[$key][NameMatcher::normalize($row['name'])] = $row['name'];
                $keys[$id][$row['year']][$row['month']][$type] = true;
            }
        }
        if ($inactive) {
            $checks[] = $this->check('warning', count($inactive).' pegawai berstatus bukan AKTIF tetapi ada setoran di file. Pastikan nama yang dipilih benar, atau perbarui statusnya di Data Pegawai.',
                array_map(fn ($p) => "{$p['name']} ({$p['status']}) — setoran mulai {$p['from']}", array_values($inactive)));
        }
        $shared = array_filter($owners, fn ($names) => count($names) > 1);
        if ($shared) {
            $details = [];
            foreach ($shared as $key => $names) {
                [$id, $type, $y, $m] = explode('|', $key);
                $details[implode(' + ', $names).' → '.($people[(int) $id]->name ?? $id)] = true;
            }
            $checks[] = $this->check('warning', 'Beberapa nama di file dipetakan ke pegawai yang sama di bulan yang sama; nilainya dijumlahkan.', array_keys($details));
        }
        if ($keys) {
            [$replaced, $others] = $this->existingDeposits($keys);
            if ($replaced > 0) {
                $checks[] = $this->check('info', "{$replaced} catatan impor lama di periode & jenis yang sama akan diganti (tidak dobel).");
            }
            if ($others) {
                $checks[] = $this->check('warning', count($others).' pegawai sudah punya catatan setoran manual/potong gaji di bulan yang sama. Setoran dari file akan ditambahkan, jadi periksa kemungkinan dobel.',
                    array_map(fn ($o) => "{$people[$o['customer_id']]->name} — ".MemberContribution::TYPE_LABELS[$o['type']]." {$o['period']}: {$rupiah($o['amount'])}", array_slice($others, 0, self::MAX_DETAILS)));
            }
        }

        // Pengembalian: saldo cukup, sisa saldo, status masih AKTIF.
        $withdrawals = array_values(array_filter($rows, fn ($r) => ($r['kind'] ?? 'deposit') === 'withdrawal'));
        if ($withdrawals) {
            $checks = array_merge($checks, $this->validateWithdrawals($withdrawals, $deposits, $resolve, $people, $rupiah));
        }

        if (! array_filter($checks, fn ($c) => $c['level'] === 'error')) {
            $checks[] = $this->check('ok', 'Tidak ada kesalahan. Data siap disimpan'.(array_filter($checks, fn ($c) => $c['level'] === 'warning') ? ' (baca peringatan di atas dulu).' : '.'));
        }
        $order = ['error' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        usort($checks, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $checks;
    }

    public static function hasErrors(array $checks): bool
    {
        return (bool) array_filter($checks, fn ($c) => $c['level'] === 'error');
    }

    /**
     * @param  array<string, int|string|null>  $mapping  nama ternormalisasi => customer_id | 'new' | null (lewati)
     * @return array{saved: int, skipped_names: int, created_employees: int}
     */
    public function commit(array $parsed, array $mapping, ?int $userId): array
    {
        $summary = ['saved' => 0, 'skipped_names' => 0, 'created_employees' => 0];

        DB::transaction(function () use ($parsed, $mapping, $userId, &$summary) {
            $resolved = [];
            $periods = []; // [tanda][tahun][bulan][customer] — setoran (>) dan pengembalian (<) diganti terpisah
            $types = [];
            $insert = [];
            $targets = []; // [customer, type, year, month, total] dari kolom "Total ..."

            $now = now();
            foreach ($parsed as $row) {
                $key = NameMatcher::normalize($row['name']);
                if (! array_key_exists($key, $resolved)) {
                    $choice = $mapping[$key] ?? null;
                    if ($choice === 'new') {
                        $customer = Customer::create([
                            'name' => $row['name'],
                            'nip' => $row['nip'] && ! Customer::where('nip', $row['nip'])->exists() ? $row['nip'] : null,
                            'employee_type' => 'LAINNYA',
                            'employment_status' => 'TIDAK DI DAFTAR PEGAWAI',
                            'no_telp' => 0,
                            'address' => '',
                        ]);
                        $resolved[$key] = $customer->id;
                        $summary['created_employees']++;
                    } elseif ($choice) {
                        $resolved[$key] = (int) $choice;
                        NameMatcher::remember($row['name'], (int) $choice);
                    } else {
                        $resolved[$key] = null;
                        $summary['skipped_names']++;
                    }
                }

                $customerId = $resolved[$key];
                if (! $customerId) {
                    continue;
                }

                $sign = ($row['kind'] ?? 'deposit') === 'withdrawal' ? '<' : '>';
                $periods[$sign][$row['year']][$row['month']][$customerId] = true;
                foreach ([...array_keys($row['amounts']), ...array_keys($row['totals'] ?? [])] as $type) {
                    $types[$sign][$type] = true;
                }
                foreach ($row['totals'] ?? [] as $type => $total) {
                    $targets[] = [$customerId, $type, $row['year'], $row['month'], $total, $row['name']];
                }
                foreach ($row['amounts'] as $type => $amount) {
                    if ($amount === 0) {
                        continue;
                    }
                    $insert[] = [
                        'customer_id' => $customerId,
                        'type' => $type,
                        'period_year' => $row['year'],
                        'period_month' => $row['month'],
                        'amount' => $amount,
                        'source' => 'import',
                        'source_name' => $row['name'],
                        'notes' => $row['note'] ?? null,
                        'created_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Timpa hasil import sebelumnya untuk pegawai, periode, dan jenis yang ada di file
            // (file tanpa kolom angsuran tidak menghapus angsuran; setoran tidak menghapus pengembalian dan sebaliknya).
            foreach ($periods as $sign => $byYear) {
                foreach ($byYear as $year => $months) {
                    foreach ($months as $month => $customers) {
                        foreach (array_chunk(array_keys($customers), 500) as $ids) {
                            MemberContribution::where('source', 'import')
                                ->whereIn('type', array_keys($types[$sign] ?? []))
                                ->where('amount', $sign, 0)
                                ->where('period_year', $year)
                                ->where('period_month', $month)
                                ->whereIn('customer_id', $ids)
                                ->delete();
                        }
                    }
                }
            }
            // Kolom total: catatan import = total − catatan lain (manual/tunai) di bulan itu, supaya tidak dobel.
            $others = [];
            foreach (array_chunk(array_unique(array_column($targets, 0)), 500) as $ids) {
                MemberContribution::whereIn('customer_id', $ids)->whereIn('type', MemberContribution::SAVINGS_TYPES)
                    ->selectRaw('customer_id, type, period_year, period_month, SUM(amount) as total')
                    ->groupBy('customer_id', 'type', 'period_year', 'period_month')->get()
                    ->each(function ($r) use (&$others) {
                        $others["{$r->customer_id}|{$r->type}|{$r->period_year}|{$r->period_month}"] = (int) $r->total;
                    });
            }
            foreach ($targets as [$customerId, $type, $year, $month, $total, $sourceName]) {
                $other = $others["{$customerId}|{$type}|{$year}|{$month}"] ?? 0;
                if ($total - $other !== 0) {
                    $insert[] = [
                        'customer_id' => $customerId, 'type' => $type, 'period_year' => $year, 'period_month' => $month,
                        'amount' => $total - $other, 'source' => 'import', 'source_name' => $sourceName, 'notes' => null,
                        'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                MemberContribution::insert($chunk);
            }
            // Hasil upload juga dijurnal otomatis (termasuk baris yang terhapus/berubah), mulai tanggal otomatis akuntansi.
            \App\Services\Accounting\AutoJournalHooks::bulkChanged();
            $summary['saved'] = count($insert);
        });
        app(MemberSync::class)->syncMembers();

        return $summary;
    }

    // ---------------------------------------------------------------- format 1: Master

    private function parseMasterRows(array $rows, array $map, string $sheet): array
    {
        $parsed = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row[$map['name']] ?? ''));
            $year = (int) ($row[$map['year']] ?? 0);
            $month = (int) ($row[$map['month']] ?? 0);
            if ($name === '' || $year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
                continue; // baris judul, nomor kolom, atau total
            }
            $amounts = [];
            foreach (MemberContribution::TYPES as $type) {
                if (isset($map[$type])) {
                    $amounts[$type] = SpreadsheetReader::money($row[$map[$type]] ?? 0);
                }
            }
            $totals = [];
            foreach (MemberContribution::SAVINGS_TYPES as $type) {
                if (isset($map['total_'.$type])) {
                    $totals[$type] = SpreadsheetReader::money($row[$map['total_'.$type]] ?? 0);
                }
            }
            $nip = isset($map['nip']) ? Nip::normalize($row[$map['nip']] ?? '') : '';
            $parsed[] = ['nip' => $nip !== '' ? $nip : null, 'name' => $name, 'year' => $year, 'month' => $month, 'amounts' => $amounts, 'totals' => $totals, 'kind' => 'deposit', 'sheet' => $sheet];
        }

        return $parsed;
    }

    /**
     * @return array<string, int>|null
     */
    private function headerMap(array $rows): ?array
    {
        // Header "Master" bertingkat (Nama di baris 2, Wajib/Pokok di baris 4): gabungkan beberapa baris pertama.
        $map = [];
        $seen = 0;
        foreach ($rows as $row) {
            if (++$seen > 10) {
                break;
            }
            foreach ($row as $index => $cell) {
                $label = SpreadsheetReader::label($cell);
                foreach (self::COLUMNS as $key => $labels) {
                    if (! isset($map[$key]) && in_array($label, $labels, true)) {
                        $map[$key] = $index;
                    }
                }
            }
        }

        $hasAmount = array_intersect(array_keys($map), [...MemberContribution::TYPES, 'total_pokok', 'total_wajib', 'total_sukarela']) !== [];

        return isset($map['name'], $map['year'], $map['month']) && $hasAmount ? $map : null;
    }

    // ---------------------------------------------------------------- format 2: rekap bulanan JAN..DES

    /** @return array{row: int, name: int, nip: ?int, months: array<int, int>}|null */
    private function wideHeader(array $rows): ?array
    {
        $seen = 0;
        foreach ($rows as $rowNumber => $row) {
            if (++$seen > 25) {
                break;
            }
            $name = null;
            $nip = null;
            $months = [];
            foreach ($row as $index => $cell) {
                $label = SpreadsheetReader::label($cell);
                if ($label === '') {
                    continue;
                }
                if ($name === null && in_array($label, self::COLUMNS['name'], true)) {
                    $name = $index;
                } elseif ($nip === null && $label === 'nip') {
                    $nip = $index;
                } elseif (isset(self::MONTHS[rtrim($label, '.')]) && ! in_array(self::MONTHS[rtrim($label, '.')], $months, true)) {
                    $months[$index] = self::MONTHS[rtrim($label, '.')];
                }
            }
            if ($name !== null && count($months) >= 6) {
                return ['row' => $rowNumber, 'name' => $name, 'nip' => $nip, 'months' => $months];
            }
        }

        return null;
    }

    private function parseWide(array $rows, string $sheet, string $fileName): array
    {
        $header = $this->wideHeader($rows);
        $titleText = $sheet;
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber >= $header['row']) {
                break;
            }
            $titleText .= ' '.implode(' ', array_filter(array_map(fn ($c) => is_scalar($c) ? (string) $c : '', $row)));
        }

        $checks = [];
        $year = null;
        if (preg_match('/(?:periode|tahun)\s*:?\s*(?:[a-z]+\s+)?(20\d\d)\b/i', $titleText, $m) || preg_match('/\b(20\d\d)\b/', $titleText, $m)) {
            $year = (int) $m[1];
        } elseif (preg_match('/(?:^|\D)(\d\d)\s*$/', trim($sheet), $m)) {
            $year = 2000 + (int) $m[1];
        }
        $typeSource = 'judul/nama sheet';
        $type = $this->typeFromText($titleText);
        if ($type === null && $fileName !== '') {
            $type = $this->typeFromText($fileName);
            $typeSource = 'nama file';
        }
        if ($type === null) {
            $type = MemberContribution::TYPE_WAJIB;
            $checks[] = $this->check('warning', "Sheet \"{$sheet}\": jenis simpanan tidak tertulis di judul, nama sheet, atau nama file — dianggap Simpanan Wajib.");
            $typeSource = 'bawaan';
        }
        if ($year === null) {
            $checks[] = $this->check('error', "Sheet \"{$sheet}\": tahun tidak ditemukan. Tulis \"Periode : 2025\" di baris judul atau tahun di nama sheet.");
        }

        $parsed = [];
        $columnSums = array_fill(1, 12, 0);
        $fileTotals = null;
        $counts = [];
        $negatives = [];
        $names = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $header['row']) {
                continue;
            }
            $name = trim((string) ($row[$header['name']] ?? ''));
            $first = SpreadsheetReader::label($row[0] ?? '') ?: SpreadsheetReader::label($row[1] ?? '') ?: SpreadsheetReader::label($name);
            $hasNumber = (bool) array_filter(array_keys($header['months']), fn ($i) => is_numeric($row[$i] ?? null) || (is_string($row[$i] ?? null) && preg_match('/\d/', $row[$i])));
            if (in_array($first, self::TOTAL_LABELS, true) || in_array(SpreadsheetReader::label($name), self::TOTAL_LABELS, true)) {
                if ($fileTotals === null && $hasNumber) {
                    $fileTotals = [];
                    foreach ($header['months'] as $index => $month) {
                        $fileTotals[$month] = SpreadsheetReader::money($row[$index] ?? 0);
                    }
                }

                continue;
            }
            if ($name === '' || ! $hasNumber || $year === null) {
                continue; // baris kosong, tanda tangan, atau keterangan
            }
            $names[NameMatcher::normalize($name)][] = $rowNumber;
            $nip = $header['nip'] !== null ? Nip::normalize($row[$header['nip']] ?? '') : '';
            foreach ($header['months'] as $index => $month) {
                $amount = SpreadsheetReader::money($row[$index] ?? 0);
                $columnSums[$month] += $amount;
                if ($amount !== 0) {
                    $counts[$amount] = ($counts[$amount] ?? 0) + 1;
                }
                if ($amount < 0) {
                    $negatives[] = "{$name} — ".$this->monthName($month).": {$amount}";
                }
                $parsed[] = ['nip' => $nip !== '' ? $nip : null, 'name' => $name, 'year' => $year, 'month' => $month,
                    'amounts' => [$type => $amount], 'totals' => [], 'kind' => 'deposit', 'sheet' => $sheet];
            }
        }

        $label = MemberContribution::TYPE_LABELS[$type];
        $total = array_sum($columnSums);
        $checks[] = $this->check('info', "Sheet \"{$sheet}\": rekap bulanan {$label} ({$typeSource}) tahun ".($year ?? '?').', '.count($names).' nama, total Rp '.number_format($total, 0, ',', '.').'.');
        if ($fileTotals !== null) {
            $diff = array_filter(array_keys($fileTotals), fn ($m) => $fileTotals[$m] !== $columnSums[$m]);
            $checks[] = $diff
                ? $this->check('error', "Sheet \"{$sheet}\": total per bulan tidak sama dengan baris Jumlah di file.", array_map(fn ($m) => $this->monthName($m).': baris nama Rp '.number_format($columnSums[$m], 0, ',', '.').' vs Jumlah Rp '.number_format($fileTotals[$m], 0, ',', '.'), array_values($diff)))
                : $this->check('ok', "Sheet \"{$sheet}\": total 12 bulan sama dengan baris Jumlah di file.");
        }
        $dupes = array_filter($names, fn ($r) => count($r) > 1);
        if ($dupes) {
            $checks[] = $this->check('warning', "Sheet \"{$sheet}\": nama yang sama muncul lebih dari sekali; nilainya dijumlahkan.", array_map(fn ($k, $r) => "{$k} (baris ".implode(', ', $r).')', array_keys($dupes), $dupes));
        }
        if ($counts) {
            arsort($counts);
            $usual = array_key_first($counts);
            $unusual = [];
            foreach ($parsed as $p) {
                $amount = $p['amounts'][$type];
                if ($amount !== 0 && $amount !== $usual) {
                    $unusual[] = "{$p['name']} — ".$this->monthName($p['month']).': Rp '.number_format($amount, 0, ',', '.');
                }
            }
            if ($unusual) {
                $checks[] = $this->check('warning', "Sheet \"{$sheet}\": ".count($unusual).' sel tidak sama dengan nilai umum Rp '.number_format($usual, 0, ',', '.').'.', $unusual);
            }
        }
        if ($negatives) {
            $checks[] = $this->check('warning', "Sheet \"{$sheet}\": ada nilai negatif (dicatat sebagai pengurang).", $negatives);
        }

        return [$parsed, ['sheet' => $sheet, 'format' => 'wide', 'type' => $type, 'year' => $year, 'label' => "Rekap bulanan {$label} ".($year ?? '?'), 'rows' => count($names), 'checks' => $checks]];
    }

    private function typeFromText(string $text): ?string
    {
        $found = array_values(array_filter(MemberContribution::SAVINGS_TYPES, fn ($t) => preg_match('/\b'.$t.'\b/i', $text)));

        return count($found) === 1 ? $found[0] : null;
    }

    // ---------------------------------------------------------------- format 3: pengembalian simpanan

    /** @return array{row: int, name: int, date: int, note: ?int, types: array<string, int>}|null */
    private function withdrawalHeader(array $rows): ?array
    {
        $seen = 0;
        foreach ($rows as $rowNumber => $row) {
            if (++$seen > 300) {
                break;
            }
            $name = null;
            $date = null;
            $note = null;
            $types = [];
            foreach ($row as $index => $cell) {
                $label = SpreadsheetReader::label($cell);
                if ($label === '') {
                    continue;
                }
                if ($name === null && in_array($label, self::COLUMNS['name'], true)) {
                    $name = $index;
                } elseif ($date === null && in_array($label, ['tanggal', 'tgl', 'tanggal pengembalian', 'tgl pengembalian'], true)) {
                    $date = $index;
                } elseif ($note === null && in_array($label, ['keterangan', 'ket', 'ket potongan', 'alasan'], true)) {
                    $note = $index;
                } elseif (preg_match('/^(?:pengembalian|penarikan|tarik)(?: simpanan)? (pokok|wajib|sukarela)$/', $label, $m) && ! isset($types[$m[1]])) {
                    $types[$m[1]] = $index;
                }
            }
            if ($name !== null && $date !== null && $types) {
                return ['row' => $rowNumber, 'name' => $name, 'date' => $date, 'note' => $note, 'types' => $types];
            }
        }

        return null;
    }

    private function parseWithdrawals(array $rows, string $sheet): array
    {
        $header = $this->withdrawalHeader($rows);
        $parsed = [];
        $sums = array_fill_keys(array_keys($header['types']), 0);
        $fileTotals = null;
        $started = false;
        $badDates = [];
        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $header['row']) {
                continue;
            }
            $name = trim((string) ($row[$header['name']] ?? ''));
            if ($name === '') {
                // Baris pertama tanpa nama setelah data = baris total; sesudahnya keterangan lain (berhenti).
                if ($started) {
                    $fileTotals = array_map(fn ($i) => abs(SpreadsheetReader::money($row[$i] ?? 0)), $header['types']);

                    break;
                }

                continue;
            }
            $amounts = array_map(fn ($i) => -abs(SpreadsheetReader::money($row[$i] ?? 0)), $header['types']);
            if (! array_filter($amounts)) {
                continue;
            }
            $started = true;
            $date = $this->toDate($row[$header['date']] ?? null);
            if (! $date) {
                $badDates[] = "{$name} (baris {$rowNumber})";

                continue;
            }
            foreach ($amounts as $type => $amount) {
                $sums[$type] += -$amount;
            }
            $note = 'Pengembalian simpanan '.$date->format('d/m/Y');
            if ($header['note'] !== null && trim((string) ($row[$header['note']] ?? '')) !== '') {
                $note .= ' · '.mb_strimwidth(trim((string) $row[$header['note']]), 0, 150);
            }
            $parsed[] = ['nip' => null, 'name' => $name, 'year' => (int) $date->format('Y'), 'month' => (int) $date->format('n'),
                'amounts' => array_filter($amounts), 'totals' => [], 'kind' => 'withdrawal', 'sheet' => $sheet,
                'date' => $date->format('Y-m-d'), 'note' => $note];
        }

        $rupiah = fn ($v) => 'Rp '.number_format($v, 0, ',', '.');
        $labels = implode(' + ', array_map(fn ($t) => MemberContribution::TYPE_LABELS[$t], array_keys($header['types'])));
        $checks = [$this->check('info', "Sheet \"{$sheet}\": pengembalian simpanan ({$labels}), ".count($parsed).' baris, total '.$rupiah(array_sum($sums)).' — dicatat sebagai pengurang saldo di bulan tanggal pengembalian.',
            array_map(fn ($t, $v) => MemberContribution::TYPE_LABELS[$t].': '.$rupiah($v), array_keys($sums), $sums))];
        if ($badDates) {
            $checks[] = $this->check('error', "Sheet \"{$sheet}\": tanggal pengembalian kosong/tidak terbaca.", $badDates);
        }
        if ($fileTotals !== null && array_filter($fileTotals)) {
            $diff = array_filter(array_keys($sums), fn ($t) => $fileTotals[$t] !== $sums[$t]);
            $checks[] = $diff
                ? $this->check('error', "Sheet \"{$sheet}\": jumlah pengembalian tidak sama dengan baris total di file.", array_map(fn ($t) => MemberContribution::TYPE_LABELS[$t].': baris nama '.$rupiah($sums[$t]).' vs total '.$rupiah($fileTotals[$t]), array_values($diff)))
                : $this->check('ok', "Sheet \"{$sheet}\": jumlah pengembalian sama dengan baris total di file.");
        }

        return [$parsed, ['sheet' => $sheet, 'format' => 'withdrawal', 'type' => null, 'year' => null, 'label' => 'Pengembalian simpanan', 'rows' => count($parsed), 'checks' => $checks]];
    }

    private function validateWithdrawals(array $withdrawals, array $deposits, callable $resolve, $people, callable $rupiah): array
    {
        $checks = [];
        usort($withdrawals, fn ($a, $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);
        $over = [];
        $partial = [];
        $active = [];
        $ok = 0;
        $used = [];
        foreach ($withdrawals as $row) {
            $id = $resolve($row);
            if (! is_int($id)) {
                continue;
            }
            $person = $people[$id] ?? null;
            if ($person && $person->employment_status === 'AKTIF') {
                $active[$id] = "{$person->name} — pengembalian {$row['date']}";
            }
            foreach ($row['amounts'] as $type => $amount) {
                $until = $row['year'] * 100 + $row['month'];
                // Saldo s.d. bulan pengembalian: catatan di aplikasi (kecuali pengembalian hasil impor yang akan diganti)
                // + setoran dari file ini, dikurangi pengembalian sebelumnya di file ini.
                $balance = (int) MemberContribution::where('customer_id', $id)->where('type', $type)
                    ->whereRaw('period_year * 100 + period_month <= ?', [$until])
                    ->where(fn ($q) => $q->where('source', '!=', 'import')->orWhere('amount', '>', 0))
                    ->sum('amount');
                foreach ($deposits as $d) {
                    if ($resolve($d) === $id && $d['year'] * 100 + $d['month'] <= $until) {
                        $balance += $d['amounts'][$type] ?? 0;
                    }
                }
                $balance -= $used[$id][$type] ?? 0;
                $used[$id][$type] = ($used[$id][$type] ?? 0) + (-$amount);
                $name = ($person->name ?? $row['name']).' — '.MemberContribution::TYPE_LABELS[$type]." {$row['date']}: pengembalian ".$rupiah(-$amount).', saldo '.$rupiah($balance);
                if (-$amount > $balance) {
                    $over[] = $name.' (kurang '.$rupiah(-$amount - $balance).')';
                } else {
                    $ok++;
                    if ($balance + $amount > 0) {
                        $partial[] = $name.' → sisa '.$rupiah($balance + $amount);
                    }
                }
            }
        }
        if ($over) {
            $checks[] = $this->check('error', count($over).' pengembalian melebihi saldo simpanan yang tercatat di aplikasi. Lengkapi dulu data setorannya (mis. impor rekap tahun sebelumnya), atau periksa nama yang dipilih.', $over);
        }
        if ($ok) {
            $checks[] = $this->check('ok', "{$ok} pengembalian tidak melebihi saldo.");
        }
        if ($partial) {
            $checks[] = $this->check('info', count($partial).' pengembalian tidak menghabiskan saldo (masih ada sisa).', $partial);
        }
        if ($active) {
            $checks[] = $this->check('warning', count($active).' pegawai yang menerima pengembalian masih berstatus AKTIF. Biasanya pengembalian karena mutasi/pensiun — perbarui statusnya di Data Pegawai bila perlu.', array_values($active));
        }

        return $checks;
    }

    // ---------------------------------------------------------------- bantuan

    /**
     * @param  array<int, array<int, array<int, array<string, bool>>>>  $keys  [customer][tahun][bulan][jenis]
     * @return array{0: int, 1: array<int, array<string, mixed>>}
     */
    private function existingDeposits(array $keys): array
    {
        $replaced = 0;
        $others = [];
        foreach (array_chunk(array_keys($keys), 300) as $ids) {
            $records = MemberContribution::whereIn('customer_id', $ids)->whereIn('type', MemberContribution::TYPES)->where('amount', '>', 0)
                ->get(['customer_id', 'type', 'period_year', 'period_month', 'amount', 'source']);
            foreach ($records as $r) {
                if (! isset($keys[$r->customer_id][$r->period_year][$r->period_month][$r->type])) {
                    continue;
                }
                if ($r->source === 'import') {
                    $replaced++;
                } else {
                    $k = "{$r->customer_id}|{$r->type}|{$r->period_year}|{$r->period_month}";
                    $others[$k] ??= ['customer_id' => $r->customer_id, 'type' => $r->type, 'period' => sprintf('%04d-%02d', $r->period_year, $r->period_month), 'amount' => 0];
                    $others[$k]['amount'] += $r->amount;
                }
            }
        }

        return [$replaced, array_values($others)];
    }

    private function toDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        if (is_numeric($value) && $value > 20000 && $value < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $value);
        }
        if (is_string($value) && trim($value) !== '') {
            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
                $date = \DateTime::createFromFormat('!'.$format, trim($value));
                if ($date) {
                    return $date;
                }
            }
        }

        return null;
    }

    private function monthName(int $month): string
    {
        return ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'][$month];
    }

    /** @return array{level: string, title: string, details: array<int, string>} */
    private function check(string $level, string $title, array $details = []): array
    {
        $extra = count($details) - self::MAX_DETAILS;

        return ['level' => $level, 'title' => $title, 'details' => array_merge(array_slice(array_values($details), 0, self::MAX_DETAILS), $extra > 0 ? ["… dan {$extra} lainnya"] : [])];
    }
}
