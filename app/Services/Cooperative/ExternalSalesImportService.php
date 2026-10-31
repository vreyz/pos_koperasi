<?php

namespace App\Services\Cooperative;

use App\Models\ExternalSale;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Import penjualan dari Excel (format "PENJUALAN": TGL BELANJA, NAMA PEGAWAI /
 * Nama Final, TOTAL HARGA) sebagai sumber jasa pembelian SHU sebelum POS dipakai.
 * Import menimpa seluruh data Excel tahun tersebut.
 */
class ExternalSalesImportService
{
    private const COLUMNS = [
        'date' => ['tgl belanja', 'tanggal', 'tgl', 'tanggal belanja'],
        'name' => ['nama pegawai', 'nama', 'nama pembeli', 'pembeli'],
        'final_name' => ['nama final'],
        'amount' => ['total harga', 'total', 'jumlah', 'nominal'],
    ];

    public function __construct(private readonly SpreadsheetReader $reader) {}

    /**
     * @return array{rows: int, total: int, matched: int, unmatched_names: int}
     */
    public function import(string $path, int $year): array
    {
        $found = null;
        $sheet = $this->reader->read($path, ['penjualan (2)', 'penjualan'], function (array $rows) use (&$found) {
            $found = SpreadsheetReader::locateHeader($rows, self::COLUMNS, ['name', 'amount']);

            return $found !== null;
        });
        if ($found === null) {
            throw new RuntimeException('Kolom "Nama Pegawai" dan "Total Harga" tidak ditemukan.');
        }

        [$headerRow, $map] = $found;
        $matcher = new NameMatcher;
        $grouped = [];
        $rowCount = 0;

        foreach ($sheet['rows'] as $rowNumber => $row) {
            if ($rowNumber <= $headerRow) {
                continue;
            }
            $name = trim((string) ($row[$map['final_name'] ?? -1] ?? '')) ?: trim((string) ($row[$map['name']] ?? ''));
            $amount = SpreadsheetReader::money($row[$map['amount']] ?? 0);
            if ($name === '' || $amount === 0) {
                continue; // baris subtotal per pembeli tidak punya TOTAL HARGA
            }
            $date = $row[$map['date'] ?? -1] ?? null;
            $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : null;
            if ($date !== null && (int) substr($date, 0, 4) !== $year) {
                continue;
            }
            $key = $name.'|'.$date;
            $grouped[$key] ??= ['buyer_name' => $name, 'sale_date' => $date, 'amount' => 0];
            $grouped[$key]['amount'] += $amount;
            $rowCount++;
        }

        if ($grouped === []) {
            throw new RuntimeException("Tidak ada baris penjualan tahun {$year} di file ini.");
        }

        DB::transaction(function () use ($grouped, $year, $matcher) {
            ExternalSale::where('year', $year)->delete();
            $now = now();
            $insert = [];
            foreach ($grouped as $sale) {
                $insert[] = $sale + ['year' => $year, 'customer_id' => $matcher->match($sale['buyer_name']), 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($insert, 500) as $chunk) {
                ExternalSale::insert($chunk);
            }
        });

        $sales = ExternalSale::where('year', $year);
        $unmatched = (int) (clone $sales)->where(fn ($q) => $q->whereNull('customer_id')->orWhereHas('customer', fn ($c) => $c->whereNull('employee_type')))->distinct('buyer_name')->count('buyer_name');
        app(MemberSync::class)->syncBuyers();

        return [
            'rows' => $rowCount,
            'total' => (int) (clone $sales)->sum('amount'),
            'matched' => (int) (clone $sales)->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id'),
            'unmatched_names' => $unmatched,
        ];
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
}
