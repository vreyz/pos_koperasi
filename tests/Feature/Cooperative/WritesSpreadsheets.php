<?php

namespace Tests\Feature\Cooperative;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait WritesSpreadsheets
{
    /**
     * @param  array<int, array<int, mixed>>  $rows  baris mulai A1; string diawali '=' ditulis sebagai rumus
     */
    protected function xlsx(array $rows, string $sheet = 'Sheet1', array $extraSheets = []): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle($sheet);
        $this->fill($spreadsheet->getActiveSheet(), $rows);
        foreach ($extraSheets as $title => $extraRows) {
            $this->fill($spreadsheet->createSheet()->setTitle($title), $extraRows);
        }

        $path = tempnam(sys_get_temp_dir(), 'coop').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    protected function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function fill($worksheet, array $rows): void
    {
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $cell = $worksheet->getCell([$c + 1, $r + 1]);
                is_string($value) && ! str_starts_with($value, '=')
                    ? $cell->setValueExplicit($value, DataType::TYPE_STRING)
                    : $cell->setValue($value);
            }
        }
    }
}
