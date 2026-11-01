<?php

namespace App\Services\Cooperative;

use App\Models\Setting;

/**
 * Aturan pembiayaan yang bisa diubah di aplikasi (disimpan di tabel settings), terpisah
 * untuk Pembiayaan Dana dan Pembiayaan Barang. Nama, alamat, dan kota koperasi diambil
 * dari Profil Toko; nama Ketua & Kepala Unit Usaha dari SK Pengurus Koperasi.
 */
class FinancingSettings
{
    /** Biaya formulir dihitung per kelipatan Rp 5 jt pembiayaan dana. */
    public const FEE_UNIT = 5000000;

    public const DEFAULTS = [
        // Pembiayaan Dana
        'financing_fee_pegawai' => 250000,        // biaya formulir pegawai per Rp 5 jt
        'financing_fee_ppnpn' => 150000,          // biaya formulir PPNPN per Rp 5 jt
        'financing_max_pegawai' => 15000000,
        'financing_max_ppnpn' => 10000000,
        'financing_tenor_dana_max' => 12,
        'coop_bank' => '',                        // rekening pembayaran biaya formulir
        'coop_contact_pegawai' => '',
        'coop_contact_ppnpn' => '',
        // Pembiayaan Barang
        'financing_margin_barang' => 7.5,         // bagi hasil (%)
        'financing_admin_barang' => 1.5,          // pendapatan lain (%), dipotong saat pencairan
        'financing_tenor_barang' => 12,
    ];

    public const KEYS = [
        'dana' => ['financing_fee_pegawai', 'financing_fee_ppnpn', 'financing_max_pegawai', 'financing_max_ppnpn', 'financing_tenor_dana_max', 'coop_bank', 'coop_contact_pegawai', 'coop_contact_ppnpn'],
        'barang' => ['financing_margin_barang', 'financing_admin_barang', 'financing_tenor_barang'],
    ];

    /** Identitas koperasi untuk formulir: dari Profil Toko dan SK Pengurus (tidak diisi ulang). */
    public static function coop(): array
    {
        $board = app(BoardService::class);

        return [
            'name' => (string) Setting::get('store_name', ''),
            'address' => (string) Setting::get('store_address', ''),
            'city' => (string) Setting::get('store_city', ''),
            'chair' => $board->currentHolder('Ketua'),
            'business_head' => $board->currentHolder('Kepala Unit Usaha'),
        ];
    }

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
        $units = (int) ceil($principal / self::FEE_UNIT);

        return $units * (int) $settings[self::isPpnpn($employeeType) ? 'financing_fee_ppnpn' : 'financing_fee_pegawai'];
    }
}
