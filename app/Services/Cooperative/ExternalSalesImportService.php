<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\ShuPeriod;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Import penjualan dari Excel sebagai sumber jasa pembelian SHU sebelum POS dipakai.
 *
 * Format: sheet dengan kolom TGL BELANJA, NAMA PEGAWAI (atau Nama Final / Nama Pembeli), TOTAL HARGA per baris
 * barang — mis. "PENJUALAN (2)" atau rekap setahun "Penjualan 2025" (Sheet1: Bulan, TGL BELANJA, NAMA PEGAWAI,
 * BARANG YANG DIBELI, JUMLAH BARANG, HARGA BARANG, TOTAL HARGA, TOTAL BELANJA, PG, QR, CASH, VC, TGL BAYAR).
 * Sheet rekap lain (harian, per pembeli, PG per bulan) dilewati.
 *
 * Alur: {@see inspect()} → {@see preview()} + {@see validate()} → {@see commit()}. Simpan menimpa seluruh
 * penjualan Excel tahun tersebut. {@see import()} = impor langsung tanpa pratinjau (dipakai skrip/tes lama).
 */
class ExternalSalesImportService
{
    private const COLUMNS = [
        'date' => ['tgl belanja', 'tanggal', 'tgl', 'tanggal belanja'],
        'name' => ['nama pegawai', 'nama', 'nama pembeli', 'pembeli'],
        'final_name' => ['nama final'],
        'amount' => ['total harga', 'total', 'jumlah', 'nominal'],
        'basket' => ['total belanja'],
        'pay_pg' => ['pg', 'potong gaji'],
        'pay_qr' => ['qr', 'qris'],
        'pay_cash' => ['cash', 'tunai'],
        'pay_vc' => ['vc', 'voucher'],
        'pay_tf' => ['tf', 'transfer'],
    ];

    private const PAYMENT_LABELS = ['pay_pg' => 'PG', 'pay_qr' => 'QR', 'pay_cash' => 'Cash', 'pay_vc' => 'Voucher', 'pay_tf' => 'Transfer'];

    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    private const MAX_DETAILS = 60;

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * Baca file: penjualan tahun $year dikelompokkan per nama per tanggal, plus angka untuk validasi.
     *
     * @return array{sheet: string, year: int, sales: array<int, array{buyer_name: string, sale_date: ?string, amount: int}>, stats: array<string, mixed>}
     */
    public function inspect(string $path, int $year): array
    {
        $found = null;
        $sheet = $this->reader->read($path, ['penjualan (2)', 'penjualan', 'sheet1'], function (array $rows) use (&$found) {
            $found = SpreadsheetReader::locateHeader($rows, self::COLUMNS, ['name', 'amount']);

            return $found !== null;
        });
        if ($found === null) {
            throw new RuntimeException('Kolom "Nama Pegawai" dan "Total Harga" tidak ditemukan.');
        }

        [$headerRow, $map] = $found;
        $grouped = [];
        $stats = ['rows' => 0, 'total' => 0, 'zero_rows' => 0, 'other_year_rows' => 0, 'other_year_amount' => 0, 'no_date_rows' => 0,
            'negative_rows' => 0, 'negative_amount' => 0, 'months' => array_fill(1, 12, 0), 'basket' => isset($map['basket']) ? 0 : null, 'payments' => []];
        foreach (self::PAYMENT_LABELS as $key => $label) {
            if (isset($map[$key])) {
                $stats['payments'][$key] = 0;
            }
        }

        foreach ($sheet['rows'] as $rowNumber => $row) {
            if ($rowNumber <= $headerRow) {
                continue;
            }
            $date = $this->toDate($row[$map['date'] ?? -1] ?? null);
            $dateYear = $date ? (int) $date->format('Y') : null;
            // Kolom per transaksi (total belanja & cara bayar) dihitung untuk cek, walau baris barangnya kosong.
            if ($dateYear === null || $dateYear === $year) {
                if ($stats['basket'] !== null) {
                    $stats['basket'] += SpreadsheetReader::money($row[$map['basket']] ?? 0);
                }
                foreach (array_keys($stats['payments']) as $key) {
                    $stats['payments'][$key] += SpreadsheetReader::money($row[$map[$key]] ?? 0);
                }
            }

            $name = trim((string) ($row[$map['final_name'] ?? -1] ?? '')) ?: trim((string) ($row[$map['name']] ?? ''));
            $amount = SpreadsheetReader::money($row[$map['amount']] ?? 0);
            if ($name === '' || $amount === 0) {
                if ($name !== '' && $amount === 0) {
                    $stats['zero_rows']++;
                }

                continue; // baris subtotal per pembeli tidak punya TOTAL HARGA
            }
            if ($dateYear !== null && $dateYear !== $year) {
                $stats['other_year_rows']++;
                $stats['other_year_amount'] += $amount;

                continue;
            }
            if ($date === null) {
                $stats['no_date_rows']++;
            } else {
                $stats['months'][(int) $date->format('n')] += $amount;
            }
            if ($amount < 0) {
                $stats['negative_rows']++;
                $stats['negative_amount'] += $amount;
            }
            $sale = $date?->format('Y-m-d');
            $key = $name.'|'.$sale;
            $grouped[$key] ??= ['buyer_name' => $name, 'sale_date' => $sale, 'amount' => 0];
            $grouped[$key]['amount'] += $amount;
            $stats['rows']++;
            $stats['total'] += $amount;
        }

        if ($grouped === []) {
            throw new RuntimeException("Tidak ada baris penjualan tahun {$year} di file ini.");
        }
        $dates = array_filter(array_column($grouped, 'sale_date'));
        $stats['first'] = $dates ? min($dates) : null;
        $stats['last'] = $dates ? max($dates) : null;

        return ['sheet' => $sheet['sheet'], 'year' => $year, 'sales' => array_values($grouped), 'stats' => $stats];
    }

    /**
     * Ringkasan per nama pembeli untuk layar pencocokan (yang belum cocok dengan pegawai di atas).
     *
     * @return array{names: array<int, array<string, mixed>>, rows: int, total: int, buyers: int, matched: int}
     */
    public function preview(array $data): array
    {
        $matcher = new NameMatcher;
        $resolve = $this->resolver($matcher);
        $names = [];
        foreach ($data['sales'] as $sale) {
            $key = NameMatcher::normalize($sale['buyer_name']);
            $names[$key] ??= ['key' => $key, 'name' => $sale['buyer_name'], 'days' => 0, 'total' => 0, 'first' => null, 'last' => null, 'customer_id' => $resolve($sale['buyer_name'])];
            $names[$key]['days']++;
            $names[$key]['total'] += $sale['amount'];
            if ($sale['sale_date']) {
                $names[$key]['first'] = min($names[$key]['first'] ?? $sale['sale_date'], $sale['sale_date']);
                $names[$key]['last'] = max($names[$key]['last'] ?? $sale['sale_date'], $sale['sale_date']);
            }
        }
        $employees = Customer::employees()->whereIn('id', array_filter(array_column($names, 'customer_id')))->pluck('id')->flip();
        foreach ($names as $key => $n) {
            $names[$key]['employee'] = $n['customer_id'] && isset($employees[$n['customer_id']]);
            $names[$key]['suggestions'] = $names[$key]['employee'] ? [] : $matcher->suggestions($n['name']);
        }
        $names = array_values($names);
        usort($names, fn ($a, $b) => [$a['employee'], -$a['total']] <=> [$b['employee'], -$b['total']]);

        return ['names' => $names, 'rows' => $data['stats']['rows'], 'total' => $data['stats']['total'], 'buyers' => count($names),
            'matched' => count(array_filter($names, fn ($n) => $n['employee']))];
    }

    /** Pemetaan otomatis: nama yang cocok (pegawai atau alias lain) → id. */
    public function autoMapping(array $preview): array
    {
        return collect($preview['names'])->mapWithKeys(fn ($n) => [$n['key'] => $n['customer_id']])->all();
    }

    /**
     * @param  array<string, int|null>  $mapping  nama ternormalisasi => customer_id | null (simpan tanpa pegawai)
     * @return array<int, array{level: string, title: string, details: array<int, string>, names?: array<int, string>}>
     */
    public function validate(array $data, array $mapping, ?ShuPeriod $period = null): array
    {
        $s = $data['stats'];
        $year = $data['year'];
        $rp = fn ($v) => 'Rp '.number_format((int) $v, 0, ',', '.');
        $checks = [];

        if ($period?->isFinal()) {
            $checks[] = $this->check('error', "SHU {$year} sudah final. Buka kembali (reopen) dulu sebelum mengganti data penjualan.");
        }
        $checks[] = $this->check('info', "Sheet \"{$data['sheet']}\": ".number_format($s['rows'], 0, ',', '.')." baris barang tahun {$year}, total {$rp($s['total'])}"
            .($s['first'] ? ", tanggal {$s['first']} s.d. {$s['last']}" : '').'.',
            array_map(fn ($m) => self::MONTHS[$m].': '.$rp($s['months'][$m]), range(1, 12)));

        $empty = array_keys(array_filter($s['months'], fn ($v) => $v === 0));
        if ($empty && count($empty) < 12) {
            $checks[] = $this->check('warning', 'Tidak ada penjualan di bulan: '.implode(', ', array_map(fn ($m) => self::MONTHS[$m], $empty)).'. Pastikan data setahun lengkap.');
        }
        if ($s['basket'] !== null) {
            $checks[] = $s['basket'] === $s['total']
                ? $this->check('ok', 'Jumlah kolom TOTAL BELANJA sama dengan jumlah TOTAL HARGA ('.$rp($s['total']).').')
                : $this->check('warning', 'Jumlah kolom TOTAL BELANJA '.$rp($s['basket']).' tidak sama dengan jumlah TOTAL HARGA '.$rp($s['total']).' (selisih '.$rp($s['basket'] - $s['total']).').');
        }
        if ($s['payments']) {
            $paid = array_sum($s['payments']);
            $detail = array_map(fn ($k, $v) => self::PAYMENT_LABELS[$k].': '.$rp($v), array_keys($s['payments']), $s['payments']);
            $checks[] = $paid === $s['total']
                ? $this->check('ok', 'Pembayaran ('.implode(' + ', array_map(fn ($k) => self::PAYMENT_LABELS[$k], array_keys($s['payments']))).') = total penjualan '.$rp($paid).'.', $detail)
                : $this->check('warning', 'Jumlah pembayaran '.$rp($paid).' tidak sama dengan total penjualan '.$rp($s['total']).' (selisih '.$rp($paid - $s['total']).').', $detail);
        }
        if ($s['no_date_rows']) {
            $checks[] = $this->check('warning', "{$s['no_date_rows']} baris tanpa tanggal belanja tetap dihitung untuk {$year}.");
        }
        if ($s['other_year_rows']) {
            $checks[] = $this->check('info', "{$s['other_year_rows']} baris bertanggal di luar {$year} dilewati ({$rp($s['other_year_amount'])}).");
        }
        if ($s['zero_rows']) {
            $checks[] = $this->check('info', "{$s['zero_rows']} baris bernilai 0 dilewati.");
        }
        if ($s['negative_rows']) {
            $checks[] = $this->check('info', "{$s['negative_rows']} baris bernilai negatif (retur/koreksi) ikut dihitung ({$rp($s['negative_amount'])}).");
        }

        // Nama pembeli: belum cocok dengan pegawai → tetap disimpan tanpa pegawai (tidak ikut SHU belanja).
        $byName = [];
        foreach ($data['sales'] as $sale) {
            $key = NameMatcher::normalize($sale['buyer_name']);
            $byName[$key] ??= ['name' => $sale['buyer_name'], 'total' => 0];
            $byName[$key]['total'] += $sale['amount'];
        }
        $ids = array_values(array_unique(array_filter($mapping)));
        $employees = Customer::employees()->whereIn('id', $ids ?: [0])->pluck('id')->flip();
        // Terhubung ke pelanggan non-pegawai (mis. Umum, Audit) = keputusan sebelumnya → info; tidak dikenal sama sekali → peringatan.
        $nonEmployee = array_filter($byName, fn ($n, $key) => ($mapping[$key] ?? null) && ! isset($employees[$mapping[$key]]), ARRAY_FILTER_USE_BOTH);
        $unmatched = array_filter($byName, fn ($n, $key) => ! ($mapping[$key] ?? null), ARRAY_FILTER_USE_BOTH);
        $share = fn ($rows) => $rp(array_sum(array_column($rows, 'total'))).', '.round(array_sum(array_column($rows, 'total')) / max(1, $s['total']) * 100, 1).'% penjualan';
        uasort($unmatched, fn ($a, $b) => $b['total'] <=> $a['total']);
        uasort($nonEmployee, fn ($a, $b) => $b['total'] <=> $a['total']);
        if ($unmatched) {
            $checks[] = $this->check('warning', count($unmatched).' nama pembeli belum dikenali ('.$share($unmatched).'). Tetap disimpan, tetapi tidak ikut SHU belanja sampai dicocokkan. Pilih pegawainya di bawah bila memang pegawai.',
                array_map(fn ($n) => "{$n['name']} — {$rp($n['total'])}", array_values($unmatched)))
                + ['names' => array_keys($unmatched)];
        } else {
            $checks[] = $this->check('ok', 'Semua nama pembeli dikenali (pegawai atau pelanggan non-pegawai yang sudah dicocokkan sebelumnya).');
        }
        if ($nonEmployee) {
            $checks[] = $this->check('info', count($nonEmployee).' nama pembeli terhubung ke pelanggan non-pegawai, sesuai pencocokan sebelumnya ('.$share($nonEmployee).'); tidak ikut SHU belanja.',
                array_map(fn ($n) => "{$n['name']} — {$rp($n['total'])}", array_values($nonEmployee)));
        }

        $existing = ExternalSale::where('year', $year);
        $count = (clone $existing)->count();
        if ($count) {
            $old = (int) (clone $existing)->sum('amount');
            $checks[] = $this->check($old === $s['total'] ? 'info' : 'warning', "Data penjualan Excel {$year} yang sudah ada ({$count} baris, {$rp($old)}) akan diganti dengan isi file ini"
                .($old === $s['total'] ? ' (totalnya sama).' : ' (selisih '.$rp($s['total'] - $old).').'));
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
     * Simpan (menimpa penjualan Excel tahun itu). Pilihan nama → pegawai diingat sebagai alias.
     *
     * @param  array<string, int|null>|null  $mapping  null = pakai pencocokan otomatis
     * @return array{rows: int, total: int, matched: int, unmatched_names: int}
     */
    public function commit(array $data, ?array $mapping = null): array
    {
        $year = $data['year'];
        $resolve = $this->resolver(new NameMatcher);
        $auto = [];
        $chosen = [];
        foreach ($data['sales'] as $sale) {
            $key = NameMatcher::normalize($sale['buyer_name']);
            if (! array_key_exists($key, $auto)) {
                $auto[$key] = $resolve($sale['buyer_name']);
                if ($mapping !== null && ($mapping[$key] ?? null) && $mapping[$key] !== $auto[$key]) {
                    $chosen[$key] = [$sale['buyer_name'], (int) $mapping[$key]];
                }
            }
        }

        DB::transaction(function () use ($data, $year, $auto, $mapping, $chosen) {
            foreach ($chosen as [$name, $id]) {
                NameMatcher::remember($name, $id);
            }
            ExternalSale::where('year', $year)->delete();
            $now = now();
            $insert = [];
            foreach ($data['sales'] as $sale) {
                $key = NameMatcher::normalize($sale['buyer_name']);
                $customerId = $mapping !== null && array_key_exists($key, $mapping) ? ($mapping[$key] ?: null) : $auto[$key];
                $insert[] = $sale + ['year' => $year, 'customer_id' => $customerId, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                ExternalSale::insert($chunk);
            }
        });

        $sales = ExternalSale::where('year', $year);
        $unmatched = (int) (clone $sales)->where(fn ($q) => $q->whereNull('customer_id')->orWhereHas('customer', fn ($c) => $c->whereNull('employee_type')))->distinct('buyer_name')->count('buyer_name');
        app(MemberSync::class)->syncBuyers();

        return [
            'rows' => $data['stats']['rows'],
            'total' => (int) (clone $sales)->sum('amount'),
            'matched' => (int) (clone $sales)->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id'),
            'unmatched_names' => $unmatched,
        ];
    }

    /**
     * Impor langsung tanpa pratinjau (pencocokan nama otomatis).
     *
     * @return array{rows: int, total: int, matched: int, unmatched_names: int}
     */
    public function import(string $path, int $year): array
    {
        return $this->commit($this->inspect($path, $year));
    }

    /**
     * Petakan nama pembeli yang belum cocok ke pegawai dan ingat sebagai alias.
     */
    public function mapName(int $year, string $buyerName, int $customerId): int
    {
        NameMatcher::remember($buyerName, $customerId);

        return ExternalSale::where('year', $year)
            ->where('buyer_name', $buyerName)
            ->update(['customer_id' => $customerId]);
    }

    /**
     * Pencocokan otomatis: alias/nama pegawai dulu, lalu hubungan yang sudah tersimpan di penjualan Excel
     * sebelumnya (mis. "UMUM" → pelanggan non-pegawai yang dipilih saat impor lama), supaya impor ulang tidak
     * menghapus pencocokan yang pernah dibuat.
     *
     * @return callable(string): ?int
     */
    private function resolver(NameMatcher $matcher): callable
    {
        $known = [];
        ExternalSale::whereNotNull('customer_id')->orderBy('year')->orderBy('id')->get(['buyer_name', 'customer_id'])
            ->each(function ($sale) use (&$known) {
                $known[NameMatcher::normalize($sale->buyer_name)] = (int) $sale->customer_id;
            });
        $existing = Customer::whereIn('id', array_values(array_unique($known)) ?: [0])->pluck('id')->flip();

        return fn (string $name) => $matcher->match($name) ?? (isset($existing[$known[NameMatcher::normalize($name)] ?? 0]) ? $known[NameMatcher::normalize($name)] : null);
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

    /** @return array{level: string, title: string, details: array<int, string>} */
    private function check(string $level, string $title, array $details = []): array
    {
        $extra = count($details) - self::MAX_DETAILS;

        return ['level' => $level, 'title' => $title, 'details' => array_merge(array_slice(array_values($details), 0, self::MAX_DETAILS), $extra > 0 ? ["… dan {$extra} lainnya"] : [])];
    }
}
