<?php

namespace App\Services\Accounting;

use App\Models\Account;

/**
 * Bagan akun (COA) bawaan koperasi. Nama "SHU Tahun Sebelumnya/Berjalan" diganti
 * "Dana Cadangan Tahun Lalu/Berjalan" sesuai arahan pengurus.
 */
class DefaultChart
{
    public const GROUPS = [
        'aset' => 'Aset',
        'kewajiban' => 'Kewajiban',
        'ekuitas' => 'Ekuitas',
        'pendapatan' => 'Pendapatan',
        'beban_pokok' => 'Beban Pokok',
        'beban_usaha' => 'Beban Usaha',
        'pajak' => 'Pajak',
    ];

    /** Divisi pada laporan PHU. Beban usaha selalu "umum" (tidak dialokasikan ke divisi). */
    public const DIVISIONS = [
        'toko' => 'Toko & Kantin',
        'pengadaan' => 'Pengadaan Kantor',
        'dana' => 'Pembiayaan Dana',
        'barang' => 'Pembiayaan Barang',
        'umum' => 'Umum',
    ];

    /** Divisi pencatat jurnal (termasuk yang tidak punya kolom PHU sendiri). */
    public const JOURNAL_DIVISIONS = self::DIVISIONS + [
        'simpanan' => 'Simpanan & Potongan',
        'tutup' => 'Tutup Buku & RAT',
    ];

    public const LINE_KAS = 'Kas dan Setara Kas';

    public const LINE_DCB = 'Dana Cadangan Tahun Berjalan';

    /**
     * [kode, nama, kelompok, saldo normal, pos laporan, divisi PHU, akun kas?, keterangan]
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:?string,6:bool,7:?string}>
     */
    public static function accounts(): array
    {
        $kas = self::LINE_KAS;
        $pu = 'Piutang Usaha';
        $pp = 'Piutang Pinjaman Anggota';
        $pl = 'Piutang Lainnya';
        $hu = 'Hutang Usaha & Vendor';
        $hs = 'Hutang Simpanan & Potongan';

        $rows = [
            ['11101', 'Kas Kecil', 'aset', 'D', $kas, null, true, null],
            ['11102', 'Bank BSI Giro Koperasi', 'aset', 'D', $kas, null, true, 'QRIS, transfer, dan setoran masuk ke sini'],
            ['11103', 'Rekening Belanja Staf Toko', 'aset', 'D', $kas, null, true, 'Modal belanja barang dagang'],
            ['11104', 'Kas di Kasir (belum disetor)', 'aset', 'D', $kas, null, true, 'Uang tunai kasir sampai disetor ke rekening koperasi'],
            ['11105', 'Kas Belanja Pengadaan (modal di staf)', 'aset', 'D', $kas, null, true, 'Modal belanja pesanan kantor'],
            ['11190', 'Pindah Buku Antar Rekening', 'aset', 'D', $kas, null, true, 'Perantara; saldo harus 0'],
            ['11201', 'Piutang Invoice Pengadaan Kantor', 'aset', 'D', $pu, null, false, null],
            ['11202', 'Piutang Katering', 'aset', 'D', $pu, null, false, null],
            ['11204', 'Piutang Potong Gaji (PG) Toko & Kantin', 'aset', 'D', $pu, null, false, 'Ditagih lewat rekap potongan gaji'],
            ['11205', 'Piutang Penjualan ke Ibu Kantin', 'aset', 'D', $pu, null, false, null],
            ['11206', 'Piutang Belanja Unit Kantor', 'aset', 'D', $pu, null, false, 'Kantor belanja di toko, dibayar bendahara kantor'],
            ['11207', 'Piutang Biaya Formulir Pembiayaan', 'aset', 'D', $pu, null, false, null],
            ['11208', 'Piutang Kelebihan Bayar Vendor', 'aset', 'D', $pu, null, false, null],
            ['11301', 'Pembiayaan Dana - Pegawai', 'aset', 'D', $pp, null, false, null],
            ['11302', 'Pembiayaan Dana - PPNPN', 'aset', 'D', $pp, null, false, null],
            ['11303', 'Pembiayaan Barang (harga + margin)', 'aset', 'D', $pp, null, false, null],
            ['11304', 'Margin Pembiayaan Barang Ditangguhkan', 'aset', 'K', $pp, null, false, 'Kontra 11303'],
            ['11309', 'Cadangan Kerugian Pembiayaan', 'aset', 'K', $pp, null, false, 'Kontra'],
            ['11401', 'Piutang Potongan TKTT Anggota', 'aset', 'D', $pl, null, false, 'Pegawai gagal autodebet, ditagih manual'],
            ['11402', 'Piutang Rekap Potongan Gaji (DKP)', 'aset', 'D', $pl, null, false, 'Per pegawai; lunas saat uang dari rekening DKP masuk'],
            ['11403', 'Piutang DKM', 'aset', 'D', $pl, null, false, null],
            ['11404', 'Piutang Kelebihan Bayar Kopesat', 'aset', 'D', $pl, null, false, null],
            ['11501', 'Persediaan Barang Dagang', 'aset', 'D', 'Persediaan', null, false, null],
            ['11601', 'Perlengkapan', 'aset', 'D', 'Perlengkapan', null, false, null],
            ['11701', 'Kredit Pajak PPh 22', 'aset', 'D', 'Kredit Pajak', null, false, null],
            ['11702', 'Kredit Pajak PPh 23', 'aset', 'D', 'Kredit Pajak', null, false, null],
            ['12101', 'Perlengkapan Kasir', 'aset', 'D', 'Aset Tetap (neto)', null, false, null],
            ['12102', 'Etalase', 'aset', 'D', 'Aset Tetap (neto)', null, false, null],
            ['12103', 'Rak Display', 'aset', 'D', 'Aset Tetap (neto)', null, false, null],
            ['12104', 'Kulkas Showcase', 'aset', 'D', 'Aset Tetap (neto)', null, false, null],
            ['12105', 'Kolam Ikan & Hidroponik', 'aset', 'D', 'Aset Tetap (neto)', null, false, null],
            ['12201', 'Akumulasi Penyusutan - Perlengkapan Kasir', 'aset', 'K', 'Aset Tetap (neto)', null, false, 'Kontra'],
            ['12202', 'Akumulasi Penyusutan - Etalase', 'aset', 'K', 'Aset Tetap (neto)', null, false, 'Kontra'],
            ['12203', 'Akumulasi Penyusutan - Rak Display', 'aset', 'K', 'Aset Tetap (neto)', null, false, 'Kontra'],
            ['12204', 'Akumulasi Penyusutan - Kulkas Showcase', 'aset', 'K', 'Aset Tetap (neto)', null, false, 'Kontra'],
            ['12205', 'Akumulasi Penyusutan - Kolam Ikan & Hidroponik', 'aset', 'K', 'Aset Tetap (neto)', null, false, 'Kontra'],

            ['21101', 'Hutang Konsinyasi (bagian penitip)', 'kewajiban', 'K', $hu, null, false, null],
            ['21102', 'Hutang Operasional', 'kewajiban', 'K', 'Hutang Operasional', null, false, null],
            ['21103', 'Hutang Vendor Katering', 'kewajiban', 'K', $hu, null, false, null],
            ['21104', 'Hutang Bagian Penjaga Kantin', 'kewajiban', 'K', $hu, null, false, 'Uang muka kantin dikurangi bagi hasil koperasi'],
            ['21105', 'Hutang Vendor Pengadaan (galon, dll)', 'kewajiban', 'K', $hu, null, false, null],
            ['21106', 'Hutang Iuran Kopesat', 'kewajiban', 'K', $hs, null, false, null],
            ['21107', 'Hutang Vendor Snack', 'kewajiban', 'K', $hu, null, false, null],
            ['21108', 'Hutang Pengembalian Simpanan', 'kewajiban', 'K', $hs, null, false, null],
            ['21109', 'Hutang Pajak PPh Badan', 'kewajiban', 'K', 'Hutang Pajak', null, false, null],
            ['21110', 'Hutang Lebih Potong TKTT', 'kewajiban', 'K', $hs, null, false, null],
            ['21111', 'Hutang Supplier Barang Dagang', 'kewajiban', 'K', $hu, null, false, 'Belanja tempo'],
            ['21112', 'Uang Muka Penjualan Kantin', 'kewajiban', 'K', $hu, null, false, 'Seluruh penerimaan kantin dicatat di sini dulu'],
            ['21121', 'Titipan Dana DKM (infaq pegawai)', 'kewajiban', 'K', 'Titipan Dana Pihak Lain', null, false, null],
            ['21122', 'Titipan Dana DKP', 'kewajiban', 'K', 'Titipan Dana Pihak Lain', null, false, null],
            ['21123', 'Titipan Dana Dharma Wanita', 'kewajiban', 'K', 'Titipan Dana Pihak Lain', null, false, null],
            ['21124', 'Titipan Dana Rumah Dinas', 'kewajiban', 'K', 'Titipan Dana Pihak Lain', null, false, null],
            ['21130', 'Hutang Voucher (SHU/HUT) Belum Ditukar', 'kewajiban', 'K', 'Hutang Voucher', null, false, null],
            ['21140', 'Saldo Kredit Pelanggan (retur)', 'kewajiban', 'K', $hu, null, false, 'Retur penjualan yang dijadikan saldo belanja'],
            ['21190', 'Perantara Potongan Gaji (TKTT)', 'kewajiban', 'K', $hs, null, false, 'Uang potongan masuk sebelum rekap ada; saldo harus 0'],

            ['31101', 'Simpanan Pokok', 'ekuitas', 'K', 'Simpanan Pokok', null, false, null],
            ['31201', 'Simpanan Wajib - Pegawai', 'ekuitas', 'K', 'Simpanan Wajib', null, false, null],
            ['31202', 'Simpanan Wajib - PPNPN', 'ekuitas', 'K', 'Simpanan Wajib', null, false, null],
            ['31301', 'Simpanan Sukarela', 'ekuitas', 'K', 'Simpanan Sukarela', null, false, null],
            ['31401', 'Simpanan Pinjaman', 'ekuitas', 'K', 'Simpanan Sukarela', null, false, 'Kolom "Simpanan Pinjaman" di rekap potongan gaji'],
            ['32101', 'Pendapatan Bagi Hasil atau Bonus', 'ekuitas', 'K', 'Pendapatan Bagi Hasil atau Bonus', null, false, null],
            ['33101', 'Dana Cadangan Tahun Lalu', 'ekuitas', 'K', 'Dana Cadangan Tahun Lalu', null, false, 'Dulu: SHU Tahun Sebelumnya'],
            ['33102', 'Dana Cadangan Tahun Berjalan', 'ekuitas', 'K', self::LINE_DCB, null, false, 'Dulu: SHU Tahun Berjalan'],
            ['33201', 'Pembagian SHU', 'ekuitas', 'D', 'Dana Cadangan Tahun Lalu', null, false, 'Kontra; ditutup ke 33101'],
            ['33301', 'Koreksi Tahun Sebelumnya', 'ekuitas', 'D', 'Dana Cadangan Tahun Lalu', null, false, 'Kontra; ditutup ke 33101'],
            ['33901', 'Selisih Ekuitas Belum Teridentifikasi', 'ekuitas', 'K', 'Selisih Ekuitas Belum Teridentifikasi', null, false, null],
            ['39999', 'Ikhtisar SHU', 'ekuitas', 'K', self::LINE_DCB, null, false, 'Hanya saat tutup buku'],

            ['41100', 'Penjualan Barang Toko', 'pendapatan', 'K', 'Penjualan barang toko', 'toko', false, null],
            ['41109', 'Retur & Potongan Penjualan', 'pendapatan', 'D', 'Retur & potongan penjualan', 'toko', false, 'Kontra'],
            ['42101', 'Komisi Barang Konsinyasi', 'pendapatan', 'K', 'Komisi barang konsinyasi', 'toko', false, null],
            ['43101', 'Bagi Hasil Kantin', 'pendapatan', 'K', 'Bagi hasil kantin', 'toko', false, 'Persen diatur di pengaturan akuntansi'],
            ['45101', 'Pendapatan Katering', 'pendapatan', 'K', 'Pendapatan katering', 'toko', false, null],
            ['44101', 'Pendapatan Pengadaan Kantor', 'pendapatan', 'K', 'Pendapatan pengadaan kantor', 'pengadaan', false, 'Nilai invoice bruto'],
            ['46101', 'Pendapatan Biaya Formulir Pembiayaan Dana', 'pendapatan', 'K', 'Pendapatan biaya formulir pembiayaan', 'dana', false, 'Dibayar anggota di depan, tidak memotong pencairan'],
            ['46102', 'Margin Pembiayaan Barang', 'pendapatan', 'K', 'Margin pembiayaan barang', 'barang', false, null],
            ['46103', 'Pendapatan Biaya Formulir Pembiayaan Barang', 'pendapatan', 'K', 'Pendapatan biaya formulir pembiayaan', 'barang', false, 'Dibayar anggota di depan'],
            ['47101', 'Voucher Tidak Ditukar', 'pendapatan', 'K', 'Pendapatan lain-lain', 'umum', false, null],
            ['47102', 'Pendapatan Lainnya', 'pendapatan', 'K', 'Pendapatan lain-lain', 'umum', false, null],
            ['47103', 'Jasa Giro / Bagi Hasil Bank', 'pendapatan', 'K', 'Pendapatan lain-lain', 'umum', false, null],

            ['51100', 'Harga Pokok Penjualan Barang Dagang', 'beban_pokok', 'D', 'Harga pokok penjualan', 'toko', false, null],
            ['51105', 'Selisih Stock Opname', 'beban_pokok', 'D', 'Selisih stock opname', 'toko', false, null],
            ['55101', 'Beban Vendor Katering', 'beban_pokok', 'D', 'Beban vendor katering', 'toko', false, null],
            ['54101', 'Beban Pokok Pengadaan Kantor', 'beban_pokok', 'D', 'Beban pokok pengadaan kantor', 'pengadaan', false, null],
            ['61109', 'Beban Kerugian Pembiayaan', 'beban_pokok', 'D', 'Beban kerugian pembiayaan', 'dana', false, null],

            ['61101', 'Beban Gaji Kasir', 'beban_usaha', 'D', 'Beban gaji kasir', 'umum', false, 'Pengurus tidak digaji'],
            ['61102', 'Beban Operasional', 'beban_usaha', 'D', 'Beban operasional', 'umum', false, null],
            ['61103', 'Biaya Administrasi Bank', 'beban_usaha', 'D', 'Biaya administrasi & transfer bank', 'umum', false, null],
            ['61104', 'Biaya Transfer TKTT', 'beban_usaha', 'D', 'Biaya administrasi & transfer bank', 'umum', false, null],
            ['61105', 'Biaya Transfer Bank', 'beban_usaha', 'D', 'Biaya administrasi & transfer bank', 'umum', false, null],
            ['61106', 'Beban Penyusutan', 'beban_usaha', 'D', 'Beban penyusutan', 'umum', false, null],
            ['61107', 'Beban Sewa/PNBP Gedung', 'beban_usaha', 'D', 'Beban sewa gedung', 'umum', false, null],
            ['61108', 'Beban Rapat Pengurus & RAT', 'beban_usaha', 'D', 'Beban rapat & RAT', 'umum', false, null],
            ['61199', 'Beban Lain-lain', 'beban_usaha', 'D', 'Beban lain-lain', 'umum', false, null],
            ['71101', 'Beban Pajak Penghasilan', 'pajak', 'D', 'Pajak penghasilan', 'umum', false, 'Dihitung akhir tahun'],
        ];

        return $rows;
    }

    /** Urutan pos Neraca. [pos, sisi (aset|kewajiban|ekuitas)] */
    public const BALANCE_LINES = [
        ['Kas dan Setara Kas', 'aset'], ['Piutang Usaha', 'aset'], ['Piutang Pinjaman Anggota', 'aset'], ['Piutang Lainnya', 'aset'],
        ['Persediaan', 'aset'], ['Perlengkapan', 'aset'], ['Kredit Pajak', 'aset'], ['Aset Tetap (neto)', 'aset'],
        ['Hutang Usaha & Vendor', 'kewajiban'], ['Hutang Operasional', 'kewajiban'], ['Hutang Simpanan & Potongan', 'kewajiban'],
        ['Hutang Pajak', 'kewajiban'], ['Titipan Dana Pihak Lain', 'kewajiban'], ['Hutang Voucher', 'kewajiban'],
        ['Simpanan Pokok', 'ekuitas'], ['Simpanan Wajib', 'ekuitas'], ['Simpanan Sukarela', 'ekuitas'],
        ['Pendapatan Bagi Hasil atau Bonus', 'ekuitas'], ['Dana Cadangan Tahun Lalu', 'ekuitas'], [self::LINE_DCB, 'ekuitas'],
        ['Selisih Ekuitas Belum Teridentifikasi', 'ekuitas'],
    ];

    /** Urutan pos PHU. [pos, bagian (usaha|lain|beban|pajak)] */
    public const INCOME_LINES = [
        ['Penjualan barang toko', 'usaha'], ['Retur & potongan penjualan', 'usaha'], ['Harga pokok penjualan', 'usaha'], ['Selisih stock opname', 'usaha'],
        ['Komisi barang konsinyasi', 'usaha'], ['Bagi hasil kantin', 'usaha'], ['Pendapatan katering', 'usaha'], ['Beban vendor katering', 'usaha'],
        ['Pendapatan pengadaan kantor', 'usaha'], ['Beban pokok pengadaan kantor', 'usaha'],
        ['Pendapatan biaya formulir pembiayaan', 'usaha'], ['Margin pembiayaan barang', 'usaha'], ['Beban kerugian pembiayaan', 'usaha'],
        ['Pendapatan lain-lain', 'lain'],
        ['Beban gaji kasir', 'beban'], ['Beban operasional', 'beban'], ['Biaya administrasi & transfer bank', 'beban'], ['Beban penyusutan', 'beban'],
        ['Beban sewa gedung', 'beban'], ['Beban rapat & RAT', 'beban'], ['Beban lain-lain', 'beban'],
        ['Pajak penghasilan', 'pajak'],
    ];

    /** Pasang akun yang belum ada (tidak menimpa nama yang sudah diubah pengguna). */
    public static function install(): int
    {
        $added = 0;
        foreach (self::accounts() as $i => [$code, $name, $group, $normal, $line, $division, $cash, $note]) {
            $account = Account::firstOrNew(['code' => $code]);
            if (! $account->exists) {
                $account->fill(['name' => $name, 'group' => $group, 'normal_balance' => $normal, 'report_line' => $line,
                    'division' => $division, 'is_cash' => $cash, 'description' => $note, 'is_active' => true, 'sort' => ($i + 1) * 10])->save();
                $added++;
            }
        }

        return $added;
    }
}
