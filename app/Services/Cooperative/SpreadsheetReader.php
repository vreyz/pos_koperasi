<?php

namespace App\Services\Cooperative;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Membaca satu sheet Excel/CSV sebagai array baris. Sel rumus memakai nilai
 * tersimpan terakhir (yang tampil di Excel), jadi tidak perlu menghitung ulang
 * rumus yang merujuk sheet/file lain.
 */
class SpreadsheetReader
{
    /**
     * @param  array<int, string>  $preferredSheets  nama sheet yang dicoba lebih dulu (case-insensitive)
     * @param  callable(array<int, array<int, mixed>>): bool|null  $accept  dipakai memilih sheet bila tidak ada nama yang cocok
     * @return array{sheet: string, rows: array<int, array<int, mixed>>}
     */
    public function read(string $path, array $preferredSheets = [], ?callable $accept = null): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $sheetNames = method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [null];

        $ordered = [];
        foreach ($preferredSheets as $wanted) {
            foreach ($sheetNames as $name) {
                if ($name !== null && strcasecmp(trim($name), $wanted) === 0) {
                    $ordered[] = $name;
                }
            }
        }
        foreach ($sheetNames as $name) {
            if (! in_array($name, $ordered, true)) {
                $ordered[] = $name;
            }
        }

        foreach ($ordered as $name) {
            $rows = $this->readSheet($path, $name);
            if ($accept === null || $accept($rows)) {
                return ['sheet' => (string) $name, 'rows' => $rows];
            }
        }

        return ['sheet' => '', 'rows' => []];
    }

    /**
     * Semua sheet yang lolos $accept (mis. "Master" dan "Master (backup)").
     *
     * @return array<string, array<int, array<int, mixed>>> nama sheet => baris
     */
    public function readAll(string $path, callable $accept): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $sheets = [];
        foreach (method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : [null] as $name) {
            $rows = $this->readSheet($path, $name);
            if ($accept($rows)) {
                $sheets[(string) $name] = $rows;
            }
        }

        return $sheets;
    }

    /** @return list<string> */
    public function sheetNames(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);

        return array_map('strval', method_exists($reader, 'listWorksheetNames') ? $reader->listWorksheetNames($path) : ['']);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function readSheet(string $path, ?string $sheet): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if ($sheet !== null && method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheet]);
        }
        // Sel kosong berformat tidak dimuat (tanpa ini sheet besar bisa makan belasan menit).
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        // Hanya sel yang benar-benar ada: sheet berformat sampai jutaan baris kosong tetap cepat.
        $rows = [];
        foreach ($worksheet->getCoordinates(false) as $coordinate) {
            $cell = $worksheet->getCell($coordinate);
            $isFormula = $cell->getDataType() === DataType::TYPE_FORMULA;
            $value = $isFormula ? $cell->getOldCalculatedValue() : $cell->getValue();
            if ($value === null || $value === '') {
                continue;
            }
            if ($value instanceof RichText) {
                $value = $value->getPlainText();
            }
            // Cek format tanggal hanya untuk sel biasa: pada sel rumus isDateTime menghitung ulang rumusnya (sangat lambat).
            if (! $isFormula && is_numeric($value) && ExcelDate::isDateTime($cell)) {
                $value = ExcelDate::excelToDateTimeObject((float) $value);
            }
            [$column, $row] = Coordinate::coordinateFromString($coordinate);
            $rows[(int) $row][Coordinate::columnIndexFromString($column) - 1] = $value;
        }
        ksort($rows);
        foreach ($rows as $index => $values) {
            $rows[$index] = array_replace(array_fill(0, max(array_keys($values)) + 1, null), $values);
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * Cari baris header: baris pertama (dalam $scan baris) yang memuat semua kata kunci wajib.
     * Kembalikan [nomorBaris, map kolom => index] atau null.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, array<int, string>>  $columns  kunci => daftar label yang diterima (huruf kecil, tanpa spasi berlebih)
     * @param  array<int, string>  $required
     * @return array{0: int, 1: array<string, int>}|null
     */
    public static function locateHeader(array $rows, array $columns, array $required, int $scan = 15): ?array
    {
        $seen = 0;
        foreach ($rows as $rowNumber => $row) {
            if (++$seen > $scan) {
                break;
            }
            $map = [];
            foreach ($row as $index => $cell) {
                $label = self::label($cell);
                if ($label === '') {
                    continue;
                }
                foreach ($columns as $key => $labels) {
                    if (! isset($map[$key]) && in_array($label, $labels, true)) {
                        $map[$key] = $index;
                    }
                }
            }
            if (array_diff($required, array_keys($map)) === []) {
                return [$rowNumber, $map];
            }
        }

        return null;
    }

    public static function label(mixed $cell): string
    {
        if (! is_string($cell)) {
            return '';
        }

        return preg_replace('/\s+/', ' ', strtolower(trim(str_replace(['_', "\n"], ' ', $cell))));
    }

    public static function money(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return (int) round($value);
        }
        if (! is_string($value)) {
            return 0;
        }
        $clean = preg_replace('/[^0-9\-]/', '', $value);

        return $clean === '' || $clean === '-' ? 0 : (int) $clean;
    }
}
