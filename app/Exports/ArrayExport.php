<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ArrayExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithHeadings, WithStrictNullComparison
{
    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<string, string>  $formats  kolom => format, mis. ['B' => '@'] agar NIP tetap teks
     */
    public function __construct(
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $formats = [],
    ) {}

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function columnFormats(): array
    {
        return $this->formats;
    }

    /** Kolom berformat teks ('@') ditulis sebagai teks: NIP 18 digit tidak berubah jadi angka 1,98E+17. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value !== null && ($this->formats[$cell->getColumn()] ?? null) === '@') {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
