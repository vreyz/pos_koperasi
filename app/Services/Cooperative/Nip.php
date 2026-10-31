<?php

namespace App\Services\Cooperative;

/**
 * NIP PNS 18 digit: tgl lahir (8) + TMT CPNS tahun-bulan (6) + jenis kelamin (1) + urut (3).
 */
class Nip
{
    public static function normalize(mixed $value): string
    {
        if (is_float($value) || is_int($value)) {
            $value = number_format((float) $value, 0, '', '');
        }

        return preg_replace('/[^0-9A-Za-z]/', '', (string) $value) ?? '';
    }

    /**
     * Kembalikan pesan masalah, atau null bila format wajar.
     */
    public static function problem(string $nip): ?string
    {
        if ($nip === '') {
            return null;
        }
        if (! ctype_digit($nip)) {
            return 'NIP mengandung huruf/simbol';
        }
        if (strlen($nip) !== 18) {
            return 'NIP '.strlen($nip).' digit (seharusnya 18)';
        }
        $birthYear = (int) substr($nip, 0, 4);
        if (! checkdate((int) substr($nip, 4, 2), (int) substr($nip, 6, 2), $birthYear)) {
            return 'Tanggal lahir di NIP tidak valid';
        }
        $tmtYear = (int) substr($nip, 8, 4);
        $tmtMonth = (int) substr($nip, 12, 2);
        if ($tmtMonth < 1 || $tmtMonth > 12) {
            return 'Bulan TMT di NIP tidak valid';
        }
        if (! in_array($nip[14], ['1', '2'], true)) {
            return 'Digit ke-15 (jenis kelamin) harus 1 atau 2';
        }
        if ($tmtYear - $birthYear < 17 || $tmtYear > (int) date('Y') + 1) {
            return 'Tahun TMT di NIP tidak wajar';
        }
        if (str_ends_with($nip, '000')) {
            return 'NIP berakhiran 000 — kemungkinan dibulatkan Excel (simpan NIP sebagai teks)';
        }

        return null;
    }

    /**
     * Dua NIP berbeda tipis (salah ketik 1–2 digit, atau 1 digit hilang/lebih).
     */
    public static function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) {
            return false;
        }

        return levenshtein($a, $b) <= 2;
    }
}
