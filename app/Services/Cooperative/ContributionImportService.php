<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\MemberContribution;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import rekap potongan simpanan per bulan (format sheet "Master": Nama Pegawai,
 * Wajib, Pokok, Sukarela, Angsuran Koperasi, Bulan, Tahun; kolom NIP opsional).
 * Import ulang untuk periode yang sama menimpa data import sebelumnya, tidak dobel.
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
        'month' => ['bulan'],
        'year' => ['tahun'],
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return array<int, array{nip: ?string, name: string, year: int, month: int, amounts: array<string, int>}>
     */
    public function parse(string $path): array
    {
        $sheets = $this->reader->readAll($path, fn (array $rows) => $this->headerMap($rows) !== null);
        if ($sheets === []) {
            throw new RuntimeException('Kolom Nama, Tahun, Bulan, dan minimal satu kolom simpanan (Pokok/Wajib/Sukarela) tidak ditemukan.');
        }

        // Beberapa sheet berformat sama (mis. "Master" s.d. 2025 dan "Master (backup)" s.d. 2026): pakai yang datanya paling baru.
        $best = null;
        foreach ($sheets as $rows) {
            $parsed = $this->parseRows($rows, $this->headerMap($rows));
            $latest = $parsed === [] ? 0 : max(array_map(fn ($r) => $r['year'] * 100 + $r['month'], $parsed));
            if ($best === null || [$latest, count($parsed)] > [$best[0], count($best[1])]) {
                $best = [$latest, $parsed];
            }
        }

        return $best[1];
    }

    /**
     * @return array<int, array{nip: ?string, name: string, year: int, month: int, amounts: array<string, int>}>
     */
    private function parseRows(array $rows, array $map): array
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
            $nip = isset($map['nip']) ? Nip::normalize($row[$map['nip']] ?? '') : '';
            $parsed[] = ['nip' => $nip !== '' ? $nip : null, 'name' => $name, 'year' => $year, 'month' => $month, 'amounts' => $amounts];
        }

        return $parsed;
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
                $matchedId = ($row['nip'] ? $byNip[$row['nip']] ?? null : null) ?? $matcher->match($row['name']);
                $names[$key] = [
                    'key' => $key,
                    'name' => $row['name'],
                    'rows' => 0,
                    'total' => 0,
                    'first' => null,
                    'last' => null,
                    'customer_id' => $matchedId,
                    'suggestions' => $matchedId ? [] : $matcher->suggestions($row['name']),
                ];
            }
            $period = sprintf('%04d-%02d', $row['year'], $row['month']);
            $names[$key]['rows']++;
            $names[$key]['total'] += array_sum($row['amounts']);
            $names[$key]['first'] = min($names[$key]['first'] ?? $period, $period);
            $names[$key]['last'] = max($names[$key]['last'] ?? $period, $period);
        }

        $names = array_values($names);
        usort($names, fn ($a, $b) => [(bool) $a['customer_id'], $a['name']] <=> [(bool) $b['customer_id'], $b['name']]);
        $years = array_keys($years);
        sort($years);

        return ['names' => $names, 'years' => $years, 'rows' => count($parsed)];
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
            $periods = [];
            $insert = [];
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

                $periods[$row['year']][$row['month']][$customerId] = true;
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
                        'created_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Timpa hasil import sebelumnya untuk pegawai & periode yang sama.
            foreach ($periods as $year => $months) {
                foreach ($months as $month => $customers) {
                    foreach (array_chunk(array_keys($customers), 500) as $ids) {
                        MemberContribution::where('source', 'import')
                            ->where('period_year', $year)
                            ->where('period_month', $month)
                            ->whereIn('customer_id', $ids)
                            ->delete();
                    }
                }
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                MemberContribution::insert($chunk);
            }
            $summary['saved'] = count($insert);
        });
        app(MemberSync::class)->syncMembers();

        return $summary;
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

        $hasAmount = array_intersect(array_keys($map), MemberContribution::TYPES) !== [];

        return isset($map['name'], $map['year'], $map['month']) && $hasAmount ? $map : null;
    }
}
