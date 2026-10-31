<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\Financing;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

/**
 * Impor data pembiayaan dari Excel koperasi. Format dikenali otomatis per sheet:
 *  - Daftar pembiayaan dana (Nama Pemohon, STATUS, Tgl Pengiriman Dana, Nominal Pembiayaan, Angsuran 1..10)
 *  - Daftar pencairan (Tanggal, jumlah, Penerima, Biaya Formulir) → biaya formulir & rekening
 *  - Daftar pembiayaan barang (Nomor Formulir, Nama Pemohon, Nominal Pengajuan, Plus Bagi Hasil, cicilan 1..12)
 * Impor ulang tidak membuat data dobel (kunci: jenis + pegawai + nominal + tanggal cair).
 */
class FinancingImportService
{
    private const MONTHS = ['januari' => 1, 'februari' => 2, 'pebruari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'nopember' => 11, 'desember' => 12];

    public function __construct(private readonly FinancingService $financings) {}

    /**
     * @return array{contracts: array<int, array<string, mixed>>, disbursements: array<int, array<string, mixed>>}
     */
    public function parse(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $sheetNames = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [null];
        $contracts = [];
        $disbursements = [];

        foreach ($sheetNames as $name) {
            $rows = $this->rows($path, $name);
            $map = null;
            $kind = null;
            foreach ($rows as $row) {
                $labels = array_map(fn ($c) => SpreadsheetReader::label($c), $row);
                $detected = $this->detect($labels);
                if ($detected) {
                    [$kind, $map] = $detected;

                    continue;
                }
                if (! $map) {
                    continue;
                }
                $parsed = match ($kind) {
                    'dana' => $this->danaRow($row, $map),
                    'barang' => $this->barangRow($row, $map),
                    'pencairan' => $this->disbursementRow($row, $map),
                };
                if ($parsed === null) {
                    continue;
                }
                $kind === 'pencairan' ? $disbursements[] = $parsed : $contracts[] = $parsed;
            }
        }

        if ($contracts === [] && $disbursements === []) {
            throw new RuntimeException('Tidak ditemukan data pembiayaan. Pastikan ada kolom "Nama Pemohon" dan "Nominal Pembiayaan"/"Nominal Pengajuan", atau daftar pencairan dengan "Penerima" dan "Biaya Formulir".');
        }

        // File yang sama bisa memuat kontrak yang sama dua kali (mis. sheet lama + sheet LK): ambil yang paling lengkap.
        $unique = [];
        foreach ($contracts as $c) {
            $key = $c['type'].'|'.NameMatcher::normalize($c['name']).'|'.$c['principal'].'|'.$c['disbursed_at'];
            if (! isset($unique[$key]) || $c['paid'] >= $unique[$key]['paid']) {
                $unique[$key] = $c;
            }
        }

        return ['contracts' => array_values($unique), 'disbursements' => $disbursements];
    }

    /**
     * @return array{names: array<int, array<string, mixed>>, contracts: int, disbursements: int, by_type: array<string, int>}
     */
    public function preview(array $parsed): array
    {
        $matcher = new NameMatcher;
        $names = [];
        foreach (array_merge($parsed['contracts'], $parsed['disbursements']) as $row) {
            $key = NameMatcher::normalize($row['name']);
            if (isset($names[$key])) {
                $names[$key]['count']++;

                continue;
            }
            $id = $matcher->match($row['name']);
            $names[$key] = [
                'key' => $key,
                'name' => $row['name'],
                'count' => 1,
                'status_hint' => $row['status_hint'] ?? null,
                'customer_id' => $id,
                'suggestions' => $id ? [] : $matcher->suggestions($row['name']),
            ];
        }
        $names = array_values($names);
        usort($names, fn ($a, $b) => [(bool) $a['customer_id'], $a['name']] <=> [(bool) $b['customer_id'], $b['name']]);

        return [
            'names' => $names,
            'contracts' => count($parsed['contracts']),
            'disbursements' => count($parsed['disbursements']),
            'by_type' => array_count_values(array_column($parsed['contracts'], 'type')),
        ];
    }

    /**
     * @param  array<string, int|string|null>  $mapping  nama ternormalisasi => customer_id | null (lewati)
     * @return array{created: int, updated: int, skipped: int, fees_applied: int, fees_unmatched: array<int, string>}
     */
    public function commit(array $parsed, array $mapping, ?int $userId): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'fees_applied' => 0, 'fees_unmatched' => []];
        $matcher = new NameMatcher;
        $resolve = function (string $name) use ($mapping, $matcher) {
            $key = NameMatcher::normalize($name);
            if (array_key_exists($key, $mapping)) {
                $id = $mapping[$key] ? (int) $mapping[$key] : null;
                if ($id) {
                    NameMatcher::remember($name, $id);
                }

                return $id;
            }

            return $matcher->match($name);
        };

        DB::transaction(function () use ($parsed, $resolve, $userId, &$summary) {
            foreach ($parsed['contracts'] as $row) {
                $customerId = $resolve($row['name']);
                $customer = $customerId ? Customer::find($customerId) : null;
                if (! $customer || ! $row['disbursed_at']) {
                    $summary['skipped']++;

                    continue;
                }

                $data = $this->financings->compute([
                    'type' => $row['type'],
                    'principal' => $row['principal'],
                    'tenor' => $row['tenor'],
                    'margin_percent' => $row['margin_percent'] ?? null,
                    'admin_fee' => $row['admin_fee'] ?? null,
                ], $customer);
                if (! empty($row['installment'])) {
                    $data['installment'] = (int) round($row['installment']);
                }

                $attributes = $data + [
                    'customer_id' => $customer->id,
                    'number' => $row['number'] ?? null,
                    'item_name' => $row['item_name'] ?? null,
                    'applied_at' => $row['applied_at'] ?? null,
                    'disbursed_at' => $row['disbursed_at'],
                    'first_due_month' => Carbon::parse($row['disbursed_at'])->startOfMonth()->addMonth()->toDateString(),
                    'transferred_amount' => $row['transferred'] ?? null,
                    'is_bad_debt' => $row['bad_debt'],
                    'notes' => $row['note'] ?? null,
                    'status' => 'berjalan',
                    'created_by' => $userId,
                ];

                $existing = Financing::where('type', $row['type'])
                    ->where('customer_id', $customer->id)
                    ->where('principal', $row['principal'])
                    ->whereDate('disbursed_at', $row['disbursed_at'])
                    ->first();

                if ($existing) {
                    $existing->update(collect($attributes)->except(['status', 'created_by', 'admin_fee'])->all());
                    $alreadyPaid = $existing->installments()->whereNotNull('paid_at')->count();
                    if ($row['paid'] > $alreadyPaid) {
                        $existing->installments()->whereNull('paid_at')->orderBy('seq')->limit($row['paid'] - $alreadyPaid)->get()
                            ->each(fn ($i) => $i->update(['paid_at' => $i->due_month->toDateString(), 'paid_source' => 'import']));
                        $this->financings->refreshStatus($existing->fresh());
                    }
                    $summary['updated']++;
                } else {
                    $financing = Financing::create($attributes);
                    $this->financings->buildSchedule($financing, min($row['paid'], $financing->tenor));
                    $summary['created']++;
                }
            }

            $this->settleToppedUpLoans();

            // Daftar pencairan: pasang biaya formulir & rekening ke kontrak dana yang cocok (pegawai + nominal + tahun, tanggal terdekat).
            foreach ($parsed['disbursements'] as $row) {
                $customerId = $resolve($row['name']);
                $match = $customerId ? Financing::where('type', 'dana')->where('customer_id', $customerId)
                    ->where('principal', $row['principal'])
                    ->whereYear('disbursed_at', $row['year'])
                    ->get()
                    ->sortBy(fn ($f) => $row['date'] ? abs($f->disbursed_at->diffInDays(Carbon::parse($row['date']))) : 0)
                    ->first() : null;
                if (! $match) {
                    $summary['fees_unmatched'][] = $row['name'].' (Rp '.number_format($row['principal'], 0, ',', '.').', '.$row['year'].')';

                    continue;
                }
                $match->update(array_filter([
                    'admin_fee' => $row['fee'],
                    'bank_name' => $row['bank_name'],
                    'bank_account' => $row['bank_account'],
                ], fn ($v) => $v !== null));
                $summary['fees_applied']++;
            }
        });

        return $summary;
    }

    /**
     * "Dana yang di TF" lebih kecil dari nominal = sisa pinjaman dana lama dipotong dari pencairan baru.
     * Cicilan pinjaman lama yang jatuh tempo setelah pencairan baru ditandai lunas (pelunasan).
     */
    private function settleToppedUpLoans(): void
    {
        $topUps = Financing::where('type', 'dana')->whereNotNull('transferred_amount')
            ->whereColumn('transferred_amount', '<', 'principal')->whereNotNull('disbursed_at')->get();
        foreach ($topUps as $new) {
            $old = Financing::where('type', 'dana')->where('customer_id', $new->customer_id)->whereKeyNot($new->id)
                ->where('disbursed_at', '<', $new->disbursed_at)->orderByDesc('disbursed_at')->first();
            if (! $old) {
                continue;
            }
            // Termasuk yang di monitoring sudah dicentang: dibayar lewat potongan pencairan, bukan potong gaji.
            $count = $old->installments()->where(fn ($q) => $q->whereNull('paid_at')->orWhere('paid_source', 'import'))
                ->where('due_month', '>=', $new->disbursed_at->copy()->startOfMonth())
                ->update(['paid_at' => $new->disbursed_at->toDateString(), 'paid_source' => 'pelunasan', 'note' => 'Dilunasi dari pencairan pembiayaan berikutnya']);
            if ($count > 0) {
                $this->financings->refreshStatus($old->fresh());
            }
        }
    }

    // ---------------------------------------------------------------- parsing

    private function detect(array $labels): ?array
    {
        $find = function (array $needles) use ($labels) {
            foreach ($labels as $i => $label) {
                foreach ($needles as $needle) {
                    if ($label !== '' && str_starts_with($label, $needle)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $name = $find(['nama pemohon']);

        // Barang dicek dulu: sheet barang juga punya kolom "Nominal Pembiayaan Dana yang disetujui".
        if ($name !== null && ($principal = $find(['nominal pengajuan'])) !== null) {
            return ['barang', [
                'name' => $name,
                'number' => $find(['nomor formulir']),
                'applied' => $find(['tanggal surat permohonan', 'tanggal pengambilan formulir']),
                'status' => $find(['status permohonan']),
                'principal' => $principal,
                'admin' => $find(['plus pendapatan lainnya']),
                'margin' => $find(['plus bagi hasil']),
                'total' => $find(['total pembiayaan']),
                'installment' => $find(['angsuran']),
                'date' => $find(['tanggal pengiriman dana']),
                'first_installment' => $find(['tanggal jatuh tempo angsuran']),
            ]];
        }

        if ($name !== null && ($principal = $find(['nominal pembiayaan'])) !== null) {
            $angsuran = [];
            foreach ($labels as $i => $label) {
                if (preg_match('/^angsuran (\d+)$/', $label)) {
                    $angsuran[] = $i;
                }
            }

            return ['dana', [
                'name' => $name,
                'status' => $find(['status']),
                'date' => $find(['tgl pengiriman dana', 'tanggal pengiriman dana']),
                'principal' => $principal,
                'installment' => $find(['angsuran tiap bulan']),
                'transferred' => $find(['dana yang di tf']),
                'transfer_date' => $find(['tanggal di tf']),
                'angsuran' => $angsuran,
                'last' => max(array_keys($labels)),
            ]];
        }

        $receiver = $find(['penerima']);
        $fee = $find(['biaya formulir']);
        if ($receiver !== null && $fee !== null) {
            return ['pencairan', [
                'date' => $find(['tanggal']),
                'amount' => $find(['jumlah']),
                'receiver' => $receiver,
                'fee' => $fee,
                'bank' => $find(['keperluan 3']),
            ]];
        }

        return null;
    }

    private function danaRow(array $row, array $map): ?array
    {
        $name = trim((string) ($row[$map['name']] ?? ''));
        $principal = SpreadsheetReader::money($row[$map['principal']] ?? 0);
        if ($name === '' || $principal <= 0 || is_numeric($name)) {
            return null;
        }
        $date = $this->date($row[$map['date']] ?? null);
        $transferDate = $this->date($row[$map['transfer_date'] ?? -1] ?? null);
        if ($date === null || (int) substr($date, 0, 4) > (int) date('Y')) {
            $date = $transferDate ?? $date; // mis. "2027-07-15" salah ketik → pakai tanggal transfer
        }
        $installment = SpreadsheetReader::money($row[$map['installment'] ?? -1] ?? 0);
        $tenor = $installment > 0 ? (int) round($principal / $installment) : max(1, count($map['angsuran']));
        $paid = count(array_filter($map['angsuran'], fn ($i) => $this->filled($row[$i] ?? null)));
        $tail = implode(' ', array_filter(array_slice($row, max($map['angsuran'] ?: [0]) + 1), 'is_string'));
        $status = is_string($row[$map['status'] ?? -1] ?? null) ? strtoupper(trim($row[$map['status']])) : '';

        return [
            'type' => 'dana',
            'name' => $name,
            'status_hint' => $status ?: null,
            'principal' => $principal,
            'installment' => $installment ?: null,
            'tenor' => max(1, min($tenor, 24)),
            'disbursed_at' => $date,
            'transferred' => SpreadsheetReader::money($row[$map['transferred'] ?? -1] ?? 0) ?: null,
            'paid' => $paid,
            'bad_debt' => str_contains(strtolower($tail), 'macet'),
            'note' => trim($tail) ?: null,
        ];
    }

    private function barangRow(array $row, array $map): ?array
    {
        $name = trim((string) ($row[$map['name']] ?? ''));
        $principal = SpreadsheetReader::money($row[$map['principal']] ?? 0);
        if ($name === '' || $principal <= 0) {
            return null;
        }
        if (str_contains(strtolower((string) ($row[$map['status'] ?? -1] ?? '')), 'tolak')) {
            return null;
        }
        $margin = SpreadsheetReader::money($row[$map['margin'] ?? -1] ?? 0);
        $total = SpreadsheetReader::money($row[$map['total'] ?? -1] ?? 0) ?: $principal + $margin;
        $installment = SpreadsheetReader::money($row[$map['installment'] ?? -1] ?? 0);
        $tenor = $installment > 0 ? (int) round($total / $installment) : (int) FinancingSettings::get('financing_tenor_barang');

        // Kolom cicilan: pasangan [nominal, checklist] mulai dari "Tanggal Jatuh Tempo Angsuran".
        $paid = 0;
        if ($map['first_installment'] !== null) {
            for ($i = 0; $i < $tenor; $i++) {
                $amountCell = $row[$map['first_installment'] + $i * 2] ?? null;
                $check = $row[$map['first_installment'] + $i * 2 + 1] ?? null;
                if (is_numeric($amountCell) && $amountCell > 0 && $this->filled($check)) {
                    $paid++;
                }
            }
        }

        return [
            'type' => 'barang',
            'name' => $name,
            'number' => trim((string) ($row[$map['number'] ?? -1] ?? '')) ?: null,
            'applied_at' => $this->date($row[$map['applied'] ?? -1] ?? null),
            'principal' => $principal,
            'margin_percent' => $principal > 0 ? round($margin / $principal * 100, 2) : null,
            'admin_fee' => SpreadsheetReader::money($row[$map['admin'] ?? -1] ?? 0),
            'installment' => $installment ?: null,
            'tenor' => max(1, min($tenor, 36)),
            'disbursed_at' => $this->date($row[$map['date'] ?? -1] ?? null),
            'transferred' => null,
            'paid' => $paid,
            'bad_debt' => false,
            'note' => null,
        ];
    }

    private function disbursementRow(array $row, array $map): ?array
    {
        $name = trim((string) ($row[$map['receiver']] ?? ''));
        $principal = SpreadsheetReader::money($row[$map['amount'] ?? -1] ?? 0);
        if ($name === '' || $principal <= 0) {
            return null;
        }
        $rawDate = $row[$map['date'] ?? -1] ?? null;
        $date = $this->date($rawDate);
        $year = $date ? (int) substr($date, 0, 4) : (preg_match('/(20\d\d)/', (string) $rawDate, $m) ? (int) $m[1] : null);
        $bank = (string) ($row[$map['bank'] ?? -1] ?? '');
        preg_match('/ke\s+([A-Za-z]+)\s+No\.?\s*Rek\.?\s*([0-9\-\s]+)/i', $bank, $b);

        return [
            'name' => $name,
            'principal' => $principal,
            'date' => $date,
            'year' => $year ?? (int) date('Y'),
            'fee' => SpreadsheetReader::money($row[$map['fee']] ?? 0) ?: null,
            'bank_name' => $b[1] ?? null,
            'bank_account' => isset($b[2]) ? trim($b[2]) : null,
        ];
    }

    private function filled(mixed $value): bool
    {
        return $value instanceof DateTimeInterface || (is_scalar($value) && trim((string) $value) !== '');
    }

    /**
     * Tanggal dari sel Excel atau teks Indonesia ("12 Januari 2024", "Jumat, 10 Januari 2025").
     */
    public function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }
        if (preg_match('/(\d{1,2})\s+([a-z]+)\s+(\d{4})/', $text, $m) && isset(self::MONTHS[$m[2]])) {
            return sprintf('%04d-%02d-%02d', $m[3], self::MONTHS[$m[2]], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function rows(string $path, ?string $sheet): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if ($sheet !== null && method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheet]);
        }
        $reader->setReadEmptyCells(false);
        $book = $reader->load($path);
        $ws = $book->getActiveSheet();
        // Batasi ke area berisi (sheet pencairan memiliki 1 juta baris kosong berformat).
        $maxRow = min($ws->getHighestDataRow(), 5000);
        $maxCol = $ws->getHighestDataColumn();
        $rows = [];
        foreach ($ws->rangeToArray("A1:{$maxCol}{$maxRow}", null, false, false, false) as $i => $values) {
            foreach ($values as $c => $v) {
                $cell = $ws->getCell([$c + 1, $i + 1]);
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $values[$c] = $cell->getOldCalculatedValue();
                } elseif (is_numeric($v) && Date::isDateTime($cell)) {
                    $values[$c] = Date::excelToDateTimeObject((float) $v);
                }
            }
            if (array_filter($values, fn ($v) => $v !== null && $v !== '') !== []) {
                $rows[] = $values;
            }
        }
        $book->disconnectWorksheets();

        return $rows;
    }
}
