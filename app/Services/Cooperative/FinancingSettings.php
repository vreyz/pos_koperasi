<?php

namespace App\Services\Cooperative;

use App\Models\Setting;

/**
 * Aturan pembiayaan yang bisa diubah di aplikasi (disimpan di tabel settings).
 * Nilai bawaan diambil dari formulir koperasi (update 04-08-2026).
 */
class FinancingSettings
{
    public const DEFAULTS = [
        'financing_fee_unit' => 5000000,          // 1 formulir = Rp 5 jt pembiayaan dana
        'financing_fee_pegawai' => 250000,        // biaya formulir pegawai per Rp 5 jt
        'financing_fee_ppnpn' => 150000,          // biaya formulir PPNPN per Rp 5 jt
        'financing_max_pegawai' => 15000000,
        'financing_max_ppnpn' => 10000000,
        'financing_tenor_dana_max' => 10,
        'financing_margin_barang' => 7.5,         // bagi hasil (%)
        'financing_admin_barang' => 1.5,          // pendapatan lain (%), dipotong saat pencairan
        'financing_tenor_barang' => 12,
        'coop_name' => 'Koperasi Konsumen Bea Cukai Kanwil',
        'coop_office' => 'Kanwil DJBC Banten',
        'coop_city' => 'Tangerang Selatan',
        'coop_bank' => '',
        'coop_contact_pegawai' => '',
        'coop_contact_ppnpn' => '',
        'coop_chair' => '',
        'coop_business_head' => '',
    ];

    public static function all(): array
    {
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = Setting::get($key, $default);
            $values[$key] = is_numeric($default) && is_numeric($value) ? $value + 0 : $value;
        }

        return $values;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    public static function isPpnpn(?string $employeeType): bool
    {
        return $employeeType === 'PPNPN';
    }

    public static function maxDana(?string $employeeType): int
    {
        return (int) self::get(self::isPpnpn($employeeType) ? 'financing_max_ppnpn' : 'financing_max_pegawai');
    }

    /** Biaya formulir pembiayaan dana: per kelipatan Rp 5 jt, tarif PPNPN lebih kecil. */
    public static function danaFee(int $principal, ?string $employeeType): int
    {
        $settings = self::all();
        $units = (int) ceil($principal / max(1, (int) $settings['financing_fee_unit']));

        return $units * (int) $settings[self::isPpnpn($employeeType) ? 'financing_fee_ppnpn' : 'financing_fee_pegawai'];
    }
}
