<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\CustomerAlias;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import daftar pegawai dua langkah:
 *  1. preview(): klasifikasi tiap baris — baru / sama (skip) / berubah / perlu dicek / error.
 *  2. commit(): terapkan keputusan user per baris.
 */
class EmployeeImportService
{
    public const FIELDS = ['nip', 'name', 'employee_type', 'rank', 'grade', 'position', 'work_unit', 'employment_status', 'active_from', 'active_until'];

    private const EXACT_FIELDS = ['nip', 'active_from', 'active_until'];

    public const FIELD_LABELS = [
        'nip' => 'NIP',
        'name' => 'Nama',
        'employee_type' => 'Jenis',
        'rank' => 'Pangkat',
        'grade' => 'Gol',
        'position' => 'Jabatan',
        'work_unit' => 'Unit',
        'employment_status' => 'Status',
        'active_from' => 'Aktif sejak',
        'active_until' => 'Aktif sampai',
        'aliases' => 'Alias',
    ];

    private const COLUMNS = [
        'nip' => ['nip', 'nip pegawai', 'nomor induk pegawai'],
        'name' => ['nama', 'n a m a', 'nama pegawai', 'nama lengkap'],
        'employee_type' => ['jenis', 'jenis pegawai', 'tipe pegawai', 'status kepegawaian'],
        'rank' => ['pangkat'],
        'grade' => ['gol', 'golongan', 'gol.', 'gol/ruang'],
        'position' => ['jabatan'],
        'work_unit' => ['unit', 'unit kerja', 'bagian', 'bidang'],
        'employment_status' => ['status', 'status pegawai'],
        'aliases' => ['alias', 'nama lain', 'ejaan nama lain'],
        'active_from' => ['aktif sejak', 'tanggal masuk', 'tgl masuk', 'mulai aktif'],
        'active_until' => ['aktif sampai', 'tanggal keluar', 'tgl keluar', 'tanggal mutasi', 'tanggal pensiun'],
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return array<int, array<string, mixed>> baris ter-parse, kunci = nomor baris Excel
     */
    public function parse(string $path): array
    {
        $found = null;
        $sheet = $this->reader->read($path, ['latest', 'pegawai', 'daftar pegawai'], function (array $rows) use (&$found) {
            $found = SpreadsheetReader::locateHeader($rows, self::COLUMNS, ['name']);

            return $found !== null;
        });

        if ($found === null) {
            throw new RuntimeException('Kolom "Nama" tidak ditemukan. Gunakan template import pegawai.');
        }

        [$headerRow, $map] = $found;
        $nameLabels = self::COLUMNS['name'];
        $section = null;
        $parsed = [];
        foreach ($sheet['rows'] as $rowNumber => $row) {
            $value = fn (string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
            $name = $rowNumber <= $headerRow ? '' : preg_replace('/\s+/', ' ', $value('name'));
            if (in_array(SpreadsheetReader::label($name), $nameLabels, true)) {
                continue; // header yang diulang per bagian
            }
            if ($name === '') {
                // Baris judul bagian ("BAGIAN UMUM") dipakai sebagai unit kerja baris di bawahnya.
                $texts = array_values(array_filter($row, fn ($c) => is_string($c) && trim($c) !== ''));
                if (count($texts) === 1 && ! preg_match('/\d{5,}/', $texts[0])) {
                    $section = preg_replace('/\s+/', ' ', trim($texts[0]));
                }

                continue;
            }
            $nip = Nip::normalize($row[$map['nip'] ?? -1] ?? '');
            if ($nip === '-' || $nip === '0') {
                $nip = '';
            }

            $parsed[$rowNumber] = [
                'nip' => $nip !== '' ? $nip : null,
                'name' => $name,
                'employee_type' => $this->employeeType($value('employee_type'), $nip),
                'rank' => $this->dash($value('rank')),
                'grade' => $this->dash(str_replace('/', '.', $value('grade'))),
                'position' => $this->dash($value('position')),
                'work_unit' => $this->dash($value('work_unit')) ?? $section,
                'employment_status' => $this->dash(strtoupper($value('employment_status'))),
                'active_from' => $this->date($row[$map['active_from'] ?? -1] ?? null),
                'active_until' => $this->date($row[$map['active_until'] ?? -1] ?? null),
                'aliases' => array_values(array_unique(array_filter(array_map(
                    fn ($a) => NameMatcher::normalize($a),
                    preg_split('/[;,\n]/', $value('aliases')) ?: []
                )))),
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array<string, mixed>>
     */
    public function classify(array $parsed): array
    {
        $existing = Customer::employees()->with('aliases:id,customer_id,alias')->get(array_merge(['id'], self::FIELDS));
        $byNip = $existing->filter(fn ($c) => $c->nip)->keyBy('nip');
        $byName = $existing->groupBy(fn ($c) => NameMatcher::normalize($c->name));
        $nipCount = array_count_values(array_filter(array_column($parsed, 'nip')));

        $result = [];
        foreach ($parsed as $rowNumber => $row) {
            $warnings = [];
            $candidates = [];
            $key = NameMatcher::normalize($row['name']);

            if ($row['nip'] && ($problem = Nip::problem($row['nip']))) {
                $warnings[] = $problem;
            }
            if (! $row['nip']) {
                $warnings[] = 'Tanpa NIP — dicocokkan lewat nama';
            }
            if ($row['nip'] && ($nipCount[$row['nip']] ?? 0) > 1) {
                $warnings[] = 'NIP ini muncul lebih dari sekali di file';
            }

            $match = $row['nip'] ? $byNip->get($row['nip']) : ($byName->get($key)?->first());
            // Pegawai lama tanpa NIP (mis. dari import simpanan) dengan nama persis sama: lengkapi NIP-nya.
            $match ??= $byName->get($key)?->first(fn ($c) => ! $c->nip);

            if ($match) {
                $diff = $this->diff($match, $row);
                $status = $diff === [] ? 'same' : 'changed';
                $candidates[] = $this->candidate($match, $row['nip'] && $match->nip ? 'NIP sama' : 'Nama sama');
                if ($row['nip'] && $match->nip && NameMatcher::normalize($match->name) !== $key) {
                    $warnings[] = 'NIP sama tetapi nama berbeda: "'.$match->name.'"';
                    if (! $this->similarName($key, NameMatcher::normalize($match->name))) {
                        $status = 'suspect'; // bisa jadi salah ketik NIP milik orang lain
                    }
                }
            } else {
                foreach ($existing as $employee) {
                    $reason = null;
                    $employeeKey = NameMatcher::normalize($employee->name);
                    // NIP berdekatan antar orang berbeda itu wajar (tgl lahir & TMT sama); curigai hanya bila namanya juga mirip.
                    if ($row['nip'] && $employee->nip && Nip::similar($row['nip'], $employee->nip) && $this->namePercent($key, $employeeKey) >= 70) {
                        $reason = 'NIP mirip ('.$employee->nip.')';
                    } elseif ($this->similarName($key, $employeeKey)) {
                        $reason = $employee->nip && $row['nip'] ? 'Nama sama/mirip, NIP beda ('.$employee->nip.')' : 'Nama mirip';
                    }
                    if ($reason) {
                        $candidates[] = $this->candidate($employee, $reason);
                    }
                }
                $diff = [];
                $status = $candidates === [] ? 'new' : 'suspect';
            }

            if ($row['nip'] && ($nipCount[$row['nip']] ?? 0) > 1 && $status !== 'same') {
                $status = 'suspect';
            }
            if ($row['nip'] && Nip::problem($row['nip']) && $status === 'new') {
                $status = 'suspect'; // NIP salah format tidak diimport tanpa konfirmasi
            }

            $result[$rowNumber] = [
                'row' => $rowNumber,
                'data' => $row,
                'status' => $status,
                'diff' => $diff,
                'warnings' => $warnings,
                'candidates' => array_slice($candidates, 0, 3),
                'default_action' => match ($status) {
                    'new' => 'create',
                    'changed' => 'update',
                    default => 'skip',
                },
            ];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $classified
     * @param  array<int|string, string>  $actions  nomor baris => create | update | update:{id} | skip
     * @return array{created: int, updated: int, skipped: int}
     */
    public function commit(array $classified, array $actions): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($classified, $actions, &$summary) {
            foreach ($classified as $rowNumber => $item) {
                $action = (string) ($actions[$rowNumber] ?? $item['default_action']);
                $data = $item['data'];

                if ($action === 'create') {
                    if ($data['nip'] && Customer::where('nip', $data['nip'])->exists()) {
                        $summary['skipped']++;

                        continue;
                    }
                    $customer = Customer::create(['employment_status' => $data['employment_status'] ?? 'AKTIF'] + $this->attributes($data) + ['no_telp' => 0, 'address' => '']);
                    $this->addAliases($customer, $data['aliases'] ?? []);
                    $summary['created']++;
                } elseif ($action === 'update' || str_starts_with($action, 'update:')) {
                    $targetId = str_starts_with($action, 'update:')
                        ? (int) substr($action, 7)
                        : ($item['candidates'][0]['id'] ?? null);
                    $target = $targetId ? Customer::find($targetId) : null;
                    if (! $target) {
                        $summary['skipped']++;

                        continue;
                    }
                    if ($data['nip'] && Customer::where('nip', $data['nip'])->whereKeyNot($target->id)->exists()) {
                        $summary['skipped']++;

                        continue;
                    }
                    $oldName = $target->name;
                    $target->update(array_filter($this->attributes($data), fn ($v) => $v !== null && $v !== ''));
                    $this->addAliases($target, $data['aliases'] ?? []);
                    if (NameMatcher::normalize($oldName) !== NameMatcher::normalize($target->name)) {
                        NameMatcher::remember($oldName, $target->id);
                    }
                    $summary['updated']++;
                } else {
                    $summary['skipped']++;
                }
            }
        });

        return $summary;
    }

    /**
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function diff(Customer $existing, array $row): array
    {
        $diff = [];
        $known = $existing->aliases->pluck('alias')->push(NameMatcher::normalize($existing->name))->all();
        $newAliases = array_values(array_diff($row['aliases'] ?? [], $known));
        if ($newAliases !== []) {
            $diff['aliases'] = ['old' => $existing->aliases->pluck('alias')->implode(', '), 'new' => '+ '.implode(', ', $newAliases)];
        }
        foreach (self::FIELDS as $field) {
            $new = $row[$field];
            if ($new === null || $new === '') {
                continue; // kolom kosong di file tidak menghapus data
            }
            $old = $existing->{$field};
            if ($old instanceof \DateTimeInterface) {
                $old = $old->format('Y-m-d');
            }
            $changed = in_array($field, self::EXACT_FIELDS, true)
                ? (string) $old !== (string) $new
                : NameMatcher::normalize((string) $old) !== NameMatcher::normalize((string) $new);
            if ($changed) {
                $diff[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    private function addAliases(Customer $customer, array $aliases): void
    {
        foreach ($aliases as $alias) {
            // Alias yang sudah dipakai pegawai lain tidak dipindahkan diam-diam.
            if ($alias !== NameMatcher::normalize($customer->name) && ! CustomerAlias::where('alias', $alias)->exists()) {
                CustomerAlias::create(['customer_id' => $customer->id, 'alias' => $alias]);
            }
        }
    }

    private function candidate(Customer $customer, string $reason): array
    {
        return ['id' => $customer->id, 'name' => $customer->name, 'nip' => $customer->nip, 'reason' => $reason];
    }

    private function similarName(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        similar_text($a, $b, $percent);

        return $percent >= 90 || (min(strlen($a), strlen($b)) >= 5 && levenshtein($a, $b) <= 2);
    }

    private function namePercent(string $a, string $b): float
    {
        similar_text($a, $b, $percent);

        return $percent;
    }

    private function employeeType(string $raw, string $nip): string
    {
        $raw = strtoupper(trim($raw));
        foreach (Customer::EMPLOYEE_TYPES as $type) {
            if ($raw === $type) {
                return $type;
            }
        }
        if (str_contains($raw, 'PPNPN') || str_contains($raw, 'KONTRAK') || str_contains($raw, 'HONOR')) {
            return 'PPNPN';
        }

        return strlen($nip) === 18 ? 'PNS' : ($raw === '' ? 'PPNPN' : 'LAINNYA');
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function dash(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || $value === '-' ? null : $value;
    }
}
