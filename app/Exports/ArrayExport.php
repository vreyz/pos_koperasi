<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ArrayExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings
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
}
