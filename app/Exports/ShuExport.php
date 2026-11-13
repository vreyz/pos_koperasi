<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Unduhan SHU per anggota dengan header dua baris (format "shu-2025.xlsx" koperasi):
 * baris 1 = kelompok (DATA PEGAWAI · DASAR PERHITUNGAN · PERHITUNGAN SHU, sel digabung & berwarna),
 * baris 2 = judul kolom (rata tengah, terlipat, bergaris), Calibri 11, lebar kolom tetap.
 */
class ShuExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    /** [judul kolom, lebar kolom] per kelompok. */
    public const GROUPS = [
        ['DATA PEGAWAI', 'C7DAF1', [
            ['NIP', 19], ['Nama', 39.5], ['Jenis', 6.4], ['Status', 9.4],
        ]],
        ['DASAR PERHITUNGAN', 'E6E0ED', [
            ['Simpanan Koperasi', 12.4], ['Porsi Simpanan (%)', 4.9], ['Belanja Toko', 12.4], ['Porsi Belanja (%)', 5.3],
            ['Pembiayaan Dana (biaya formulir)', 9.8], ['Pembiayaan Barang (bagi hasil + biaya lain)', 11.4], ['Pendapatan Pembiayaan', 11.4],
            ['Porsi Pendapatan Pembiayaan (%)', 7.8], ['Bln Byr Simpanan dlm 1 Tahun', 8.1],
        ]],
        ['PERHITUNGAN SHU', 'FDEADA', [
            ['SHU Dasar', 8.8], ['SHU Pengurus Koperasi', 9.8], ['SHU Simpanan', 9.8], ['SHU Belanja', 11.4],
            ['SHU Pendapatan Pembiayaan', 9.8], ['Koreksi', 4.3], ['Total SHU', 11.4], ['Total Dibulatkan', 11.4],
        ]],
    ];

    /** @param  array<int, array<int, mixed>>  $rows  satu baris per anggota, urutan kolom sesuai GROUPS */
    public function __construct(private readonly array $rows, private readonly string $title = 'SHU') {}

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);
    }

    public function array(): array
    {
        $groupRow = [];
        $headerRow = [];
        foreach (self::GROUPS as [$group, , $columns]) {
            foreach ($columns as $i => [$label]) {
                $groupRow[] = $i === 0 ? $group : null;
                $headerRow[] = $label;
            }
        }

        return [$groupRow, $headerRow, ...$this->rows];
    }

    /** Kolom NIP (A) di baris data ditulis sebagai teks: 18 digit tidak berubah jadi 1,98E+17. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value !== null && $cell->getColumn() === 'A' && $cell->getRow() > 2) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getParent()->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
                $col = 1;
                foreach (self::GROUPS as [, $color, $columns]) {
                    $first = Coordinate::stringFromColumnIndex($col);
                    $last = Coordinate::stringFromColumnIndex($col + count($columns) - 1);
                    $sheet->mergeCells("{$first}1:{$last}1");
                    $sheet->getStyle("{$first}1:{$last}2")->applyFromArray([
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'font' => ['color' => ['rgb' => '000000']],
                    ]);
                    foreach ($columns as $i => [, $width]) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col + $i))->setWidth($width);
                    }
                    $col += count($columns);
                }
                $sheet->getRowDimension(1)->setRowHeight(38.5);
                $sheet->getRowDimension(2)->setRowHeight(84.5);
            },
        ];
    }
}
