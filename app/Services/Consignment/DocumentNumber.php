<?php

namespace App\Services\Consignment;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Nomor dokumen koperasi: urut per tahun (mulai 01 tiap Januari) + kode + bulan romawi + tahun.
 * - Invoice kantin: 40/BC-KKJ/X/2026 (tahun 4 digit)
 * - Instruksi pembayaran konsinyasi: 30/IP-NAP/XI/26 (tahun 2 digit)
 * Kode tengah bisa diubah di pengaturan.
 */
class DocumentNumber
{
    public const TYPES = [
        'kantin' => ['setting' => 'documents.kantin_invoice_code', 'default' => 'BC-KKJ', 'year_digits' => 4, 'table' => 'kantin_invoices'],
        'consignment' => ['setting' => 'documents.consignment_payment_code', 'default' => 'IP-NAP', 'year_digits' => 2, 'table' => 'consignment_payments'],
    ];

    private const ROMAN = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public static function code(string $type): string
    {
        $t = self::TYPES[$type];

        return (string) (Setting::get($t['setting'], $t['default']) ?: $t['default']);
    }

    public static function format(string $type, int $seq, $date): string
    {
        $d = Carbon::parse($date);
        $year = self::TYPES[$type]['year_digits'] === 2 ? $d->format('y') : $d->format('Y');

        return sprintf('%02d/%s/%s/%s', $seq, self::code($type), self::ROMAN[$d->month], $year);
    }

    public static function nextSeq(string $type, $date): int
    {
        $year = Carbon::parse($date)->year;

        return ((int) \DB::table(self::TYPES[$type]['table'])->where('year', $year)->max('seq')) + 1;
    }

    public static function next(string $type, $date): string
    {
        return self::format($type, self::nextSeq($type, $date), $date);
    }

    /** Angka urut dari nomor yang diketik (bagian sebelum "/" pertama). */
    public static function parseSeq(string $number): ?int
    {
        return preg_match('/^\s*(\d+)\s*\//', $number, $m) ? (int) $m[1] : null;
    }
}
