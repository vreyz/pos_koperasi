<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\MemberContribution;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * Katalog kejadian per divisi. Petugas mengisi kejadian (bukan debit/kredit); katalog ini
 * menerjemahkannya menjadi jurnal. Satu kejadian bisa menghasilkan lebih dari satu jurnal
 * (contoh: penjualan kantin = terima uang muka, lalu langsung diakui bagi hasil & hutang kantin).
 */
class EventCatalog
{
    public const KANTIN_SHARE_KEY = 'accounting.kantin_share_percent';

    public const KANTIN_SHARE_DEFAULT = 10;

    private const FLOW_TOKO = ['operasi', 'Penerimaan & pembayaran usaha toko, kantin, katering & konsinyasi'];

    private const FLOW_PENGADAAN = ['operasi', 'Penerimaan & pembayaran usaha pengadaan kantor'];

    private const FLOW_POTONGAN = ['operasi', 'Penerimaan potongan gaji anggota'];

    private const FLOW_TITIPAN = ['operasi', 'Penyaluran titipan dana, iuran & kelebihan potong'];

    private const FLOW_PEMBIAYAAN = ['operasi', 'Pencairan & angsuran pembiayaan anggota'];

    private const FLOW_UMUM = ['operasi', 'Beban usaha, pajak & pendapatan lain'];

    public const FLOW_JURNAL_UMUM = ['operasi', 'Lain-lain (jurnal umum)'];

    /**
     * Kejadian yang mulai tanggal otomatis (pengaturan) dibuat sendiri dari menu asalnya,
     * jadi tidak boleh dicatat manual lagi supaya tidak dobel. Nilai = menu asal.
     */
    public const AUTO_CODES = [
        'TK-02' => 'Penerimaan Barang', 'TK-03' => 'Penerimaan Barang', 'TK-05' => 'Kasir (POS)', 'TK-11' => 'Retur Penjualan',
        'TK-12' => 'Kasir (POS)', 'TK-13' => 'Konsinyasi (Instruksi Pembayaran)', 'TK-14' => 'Stock Opname', 'TK-15' => 'Kasir (POS) outlet Kantin', 'TK-21' => 'Piutang',
        'PD-01' => 'Pembiayaan Dana', 'PD-02' => 'Pembiayaan Dana', 'PD-05' => 'Pembiayaan Dana', 'PD-06' => 'Pembiayaan Dana',
        'PB-01' => 'Pembiayaan Barang', 'PB-02' => 'Pembiayaan Barang', 'PB-03' => 'Pembiayaan Barang', 'PB-04' => 'Pembiayaan Barang',
        'SP-01' => 'Angsuran & Potongan', 'SP-05' => 'Simpanan Anggota', 'TB-04' => 'SHU',
    ];

    public static function kantinShare(): float
    {
        return (float) Setting::get(self::KANTIN_SHARE_KEY, self::KANTIN_SHARE_DEFAULT);
    }

    /** Definisi untuk form (tanpa logika jurnal). */
    public function definitions(): array
    {
        $m = fn ($key, $label, $extra = []) => ['key' => $key, 'label' => $label, 'type' => 'money'] + $extra;
        $cash = fn ($key = 'cash', $label = 'Rekening kas', $default = '11102') => ['key' => $key, 'label' => $label, 'type' => 'cash', 'default' => $default];
        $acc = fn ($key, $label, array $options, $extra = []) => ['key' => $key, 'label' => $label, 'type' => 'account', 'options' => $options, 'default' => $options[0]] + $extra;
        $cust = fn ($required = true, $label = 'Pegawai') => ['key' => 'customer_id', 'label' => $label, 'type' => 'customer', 'required' => $required];

        $defs = [
            // ---------------- Toko & Kantin
            ['TK-01', 'toko', 'Transfer modal belanja ke staf toko', 'Bendahara mengirim uang belanja barang dagang ke rekening staf toko.', [$m('amount', 'Jumlah'), $cash('cash', 'Dari rekening')]],
            ['TK-02', 'toko', 'Belanja barang dagang (tunai)', 'Barang dari grosir/online sudah datang dan dicek. Ongkir/bensin ikut dijumlahkan.', [$m('amount', 'Total belanja + ongkir'), $cash('cash', 'Dibayar dari', '11103')]],
            ['TK-03', 'toko', 'Belanja barang dagang (tempo)', 'Barang diterima, dibayar belakangan ke supplier.', [$m('amount', 'Nilai faktur')]],
            ['TK-04', 'toko', 'Bayar hutang supplier', null, [$m('amount', 'Jumlah'), $cash()]],
            ['TK-05', 'toko', 'Rekap penjualan toko (kasir)', 'Isi per cara bayar. HPP = harga pokok barang terjual (dari laporan kasir).', [
                $m('tunai', 'Tunai (masuk Kas di Kasir)'), $m('qris', 'QRIS / Transfer'), $m('pg', 'Potong gaji'), $m('kantor', 'Tagihan unit kantor'),
                $m('voucher', 'Voucher SHU/HUT'), $m('hpp', 'Harga pokok barang terjual'), $cash('cash', 'QRIS/transfer masuk ke')]],
            ['TK-10', 'toko', 'Setor uang kasir ke rekening koperasi', 'Uang tunai kasir disetor ke rekening koperasi (bukan rekening pribadi).', [$m('amount', 'Jumlah disetor'), $cash('cash', 'Ke rekening')]],
            ['TK-11', 'toko', 'Retur penjualan', null, [$m('amount', 'Nilai jual dikembalikan'), $m('hpp', 'Harga pokok barang kembali'), $cash('cash', 'Uang dikembalikan dari', '11104')]],
            ['TK-12', 'toko', 'Penjualan barang konsinyasi', 'Barang titipan: bagian penitip jadi hutang, koperasi hanya mengakui komisi.', [
                $m('amount', 'Total penjualan'), $m('komisi', 'Komisi koperasi'), $acc('received', 'Diterima sebagai', ['11102', '11104', '11204'])]],
            ['TK-13', 'toko', 'Bayar hasil penjualan ke penitip', null, [$m('amount', 'Jumlah'), $cash()]],
            ['TK-14', 'toko', 'Selisih stock opname', null, [$m('amount', 'Nilai selisih (harga pokok)'), ['key' => 'arah', 'label' => 'Barang', 'type' => 'select', 'options' => ['kurang' => 'Kurang dari catatan', 'lebih' => 'Lebih dari catatan'], 'default' => 'kurang']]],
            ['TK-15', 'toko', 'Penjualan kantin (rekap harian)', 'Dua jurnal: (1) uang diterima dicatat sebagai uang muka kantin, (2) langsung dibagi: bagi hasil koperasi sesuai persen pengaturan, sisanya hutang ke penjaga kantin.', [
                $m('qris', 'QRIS / Transfer'), $m('tunai', 'Tunai'), $m('pg', 'Potong gaji'), $cash('cash', 'QRIS/transfer masuk ke')]],
            ['TK-16', 'toko', 'Transfer hasil penjualan ke penjaga kantin', null, [$m('amount', 'Jumlah'), $cash()]],
            ['TK-17', 'toko', 'Tagihan vendor katering', 'Rincian tagihan mingguan (pegawai, pejabat, PKL).', [$m('amount', 'Jumlah tagihan')]],
            ['TK-18', 'toko', 'Bayar vendor katering', null, [$m('amount', 'Jumlah'), $cash()]],
            ['TK-19', 'toko', 'Tagih katering (rekap bulanan)', null, [$m('amount', 'Jumlah'), $acc('debit', 'Ditagih ke', ['11202', '11204'])]],
            ['TK-20', 'toko', 'Pelunasan tagihan katering', null, [$m('amount', 'Jumlah'), $cash()]],
            ['TK-21', 'toko', 'Pelunasan piutang penjualan', 'Ibu kantin, unit kantor, atau kelebihan bayar vendor.', [$m('amount', 'Jumlah'), $acc('account', 'Piutang', ['11205', '11206', '11204', '11208']), $cash()]],
            // ---------------- Pengadaan Kantor
            ['PK-02', 'pengadaan', 'Transfer modal belanja pengadaan ke staf', null, [$m('amount', 'Jumlah'), $cash('cash', 'Dari rekening')]],
            ['PK-03', 'pengadaan', 'Belanja barang/jasa pesanan kantor', 'Galon atau vendor tempo: pilih "Hutang Vendor Pengadaan".', [$m('amount', 'Jumlah'), $acc('paid', 'Dibayar dari', ['11105', '21105', '11102'])]],
            ['PK-04', 'pengadaan', 'Invoice ke kantor', 'Barang + invoice diserahkan ke kantor (PPK/Staf RT).', [$m('amount', 'Nilai invoice'), ['key' => 'ref', 'label' => 'Nomor invoice', 'type' => 'text']]],
            ['PK-05', 'pengadaan', 'Kantor membayar invoice', 'Kantor membayar setelah dipotong PPh 22/23.', [$m('amount', 'Nilai invoice'), $m('pph22', 'PPh 22 dipotong'), $m('pph23', 'PPh 23 dipotong'), $cash()]],
            ['PK-06', 'pengadaan', 'Sisa modal belanja dikembalikan', null, [$m('amount', 'Jumlah'), $cash('cash', 'Ke rekening')]],
            ['PK-07', 'pengadaan', 'Bayar vendor pengadaan (galon, dll)', null, [$m('amount', 'Jumlah'), $cash()]],
            ['PK-08', 'pengadaan', 'Refund dari vendor', null, [$m('amount', 'Jumlah'), $cash()]],
            // ---------------- Pembiayaan Dana
            ['PD-01', 'dana', 'Terima biaya formulir (dibayar di depan)', 'Biaya formulir dibayar anggota sebelum pencairan; tidak memotong transfer.', [$cust(), $m('amount', 'Jumlah'), $cash('cash', 'Diterima di')]],
            ['PD-02', 'dana', 'Pencairan pembiayaan dana', 'Uang yang ditransfer = pokok - pelunasan pinjaman lama (bila top-up). Biaya formulir dicatat terpisah (PD-01).', [
                $cust(), $acc('account', 'Jenis', ['11301', '11302']), $m('pokok', 'Pokok pinjaman'),
                $m('pelunasan', 'Pelunasan pinjaman lama (dipotong)'), $cash('cash', 'Ditransfer dari')]],
            ['PD-05', 'dana', 'Angsuran / pelunasan langsung (transfer)', 'Angsuran lewat potongan gaji masuk dari Rekap Potongan.', [$cust(), $acc('account', 'Jenis', ['11301', '11302']), $m('amount', 'Jumlah'), $cash()]],
            ['PD-06', 'dana', 'Cadangan pembiayaan bermasalah', null, [$cust(), $m('amount', 'Jumlah cadangan')]],
            ['PD-07', 'dana', 'Hapus buku pembiayaan macet', null, [$cust(), $acc('account', 'Jenis', ['11301', '11302', '11303']), $m('amount', 'Jumlah')]],
            // ---------------- Pembiayaan Barang
            ['PB-01', 'barang', 'Terima biaya formulir (dibayar di depan)', null, [$cust(), $m('amount', 'Jumlah'), $cash('cash', 'Diterima di')]],
            ['PB-02', 'barang', 'Realisasi pembiayaan barang', 'Koperasi membeli barang dan menyerahkannya ke anggota.', [
                $cust(), $m('harga', 'Harga barang'), $m('margin', 'Margin koperasi'), $cash('cash', 'Dibayar dari')]],
            ['PB-03', 'barang', 'Akui margin angsuran', 'Bagian margin dari angsuran bulan ini (margin / tenor).', [$cust(false), $m('amount', 'Margin bulan ini')]],
            ['PB-04', 'barang', 'Angsuran / pelunasan langsung (transfer)', null, [$cust(), $m('amount', 'Jumlah diterima'), $m('margin', 'Bagian margin'), $cash()]],
            // ---------------- Simpanan & Potongan
            ['SP-01', 'simpanan', 'Rekap potongan gaji bulanan (TKTT)', 'Diambil dari menu Angsuran & Potongan per pegawai. Satu jurnal, tapi tiap baris menyimpan nama pegawai.', [
                ['key' => 'period', 'label' => 'Bulan potongan', 'type' => 'period'],
                $m('uang_makan', 'Uang makan / katering (total)'), $m('dkm', 'Titipan DKM'), $m('dkp', 'Titipan DKP'), $m('dwp', 'Titipan Dharma Wanita'),
                $m('rumdin', 'Titipan Rumah Dinas'), $m('kopesat', 'Iuran Kopesat')]],
            ['SP-11', 'simpanan', 'Uang makan & titipan dalam rekap potongan', 'Bagian rekap potongan yang tidak ada di file impor: uang makan/katering dan titipan pihak lain.', [
                ['key' => 'period', 'label' => 'Bulan potongan', 'type' => 'period'],
                $m('uang_makan', 'Uang makan / katering'), $m('dkm', 'Titipan DKM'), $m('dkp', 'Titipan DKP'), $m('dwp', 'Titipan Dharma Wanita'),
                $m('rumdin', 'Titipan Rumah Dinas'), $m('kopesat', 'Iuran Kopesat')]],
            ['SP-02', 'simpanan', 'Terima uang potongan dari rekening DKP', 'Pegawai yang gagal autodebet tetap tercatat sebagai piutang sampai dibayar.', [
                ['key' => 'period', 'label' => 'Bulan potongan', 'type' => 'period'], ['key' => 'failed', 'label' => 'Pegawai gagal autodebet', 'type' => 'customers'],
                $m('biaya', 'Biaya transfer'), $cash('cash', 'Masuk ke')]],
            ['SP-03', 'simpanan', 'Uang potongan masuk (rekap belum ada)', null, [$m('amount', 'Jumlah'), $cash()]],
            ['SP-04', 'simpanan', 'Simpanan wajib PPNPN (autodebet)', null, [$cust(false), $m('amount', 'Jumlah'), $cash()]],
            ['SP-05', 'simpanan', 'Setoran simpanan langsung', null, [$cust(), $acc('account', 'Jenis simpanan', ['31201', '31202', '31101', '31301']), $m('amount', 'Jumlah'), $cash()]],
            ['SP-06', 'simpanan', 'Pengembalian simpanan disetujui (anggota keluar)', 'Dipotong sisa pinjaman bila ada; sisanya jadi hutang pengembalian.', [
                $cust(), $m('pokok', 'Simpanan pokok'), $m('wajib', 'Simpanan wajib'), $acc('wajib_account', 'Jenis wajib', ['31201', '31202']),
                $m('sukarela', 'Simpanan sukarela'), $m('potong', 'Dipotong sisa pinjaman'), $acc('loan_account', 'Pinjaman', ['11301', '11302', '11303'])]],
            ['SP-07', 'simpanan', 'Bayar pengembalian simpanan', null, [$cust(), $m('amount', 'Jumlah'), $cash()]],
            ['SP-08', 'simpanan', 'Salurkan titipan dana', null, [$acc('account', 'Titipan', ['21121', '21122', '21123', '21124']), $m('amount', 'Jumlah'), $cash()]],
            ['SP-09', 'simpanan', 'Setor iuran Kopesat', null, [$m('amount', 'Jumlah'), $cash()]],
            ['SP-10', 'simpanan', 'Kembalikan kelebihan potong', null, [$cust(false), $m('amount', 'Jumlah'), $cash()]],
            // ---------------- Umum
            ['UM-01', 'umum', 'Gaji kasir', 'Pengurus tidak digaji; hanya kasir.', [$m('amount', 'Jumlah'), $cash()]],
            ['UM-02', 'umum', 'Biaya umum koperasi', null, [$acc('account', 'Jenis biaya', ['61102', '61103', '61105', '61107', '61108', '61199']), $m('amount', 'Jumlah'), $cash()]],
            ['UM-04', 'umum', 'Jasa giro / bagi hasil bank', null, [$m('amount', 'Jumlah'), $cash()]],
            ['UM-07', 'umum', 'Beli aset tetap / perlengkapan', null, [$acc('account', 'Aset', ['12101', '12102', '12103', '12104', '12105', '11601']), $m('amount', 'Jumlah'), $cash()]],
            ['UM-08', 'umum', 'Penyusutan aset tetap', null, [$acc('account', 'Akumulasi penyusutan', ['12205', '12204', '12201', '12202', '12203']), $m('amount', 'Jumlah')]],
            ['UM-09', 'umum', 'PPh badan terutang (akhir tahun)', null, [$m('amount', 'Jumlah')]],
            ['UM-10', 'umum', 'Kompensasi kredit pajak', null, [$m('pph22', 'PPh 22'), $m('pph23', 'PPh 23')]],
            ['UM-11', 'umum', 'Setor kekurangan pajak', null, [$m('amount', 'Jumlah'), $cash()]],
            ['UM-12', 'umum', 'Pindah dana antar rekening', null, [$m('amount', 'Jumlah'), $cash('from', 'Dari'), $cash('to', 'Ke', '11101')]],
            // ---------------- Tutup Buku & RAT
            ['TB-03', 'tutup', 'Pindah Dana Cadangan Tahun Berjalan ke Tahun Lalu', 'Dilakukan di awal tahun. Kosongkan jumlah untuk memindah seluruh saldo.', [$m('amount', 'Jumlah (kosong = seluruh saldo)')]],
            ['TB-04', 'tutup', 'Pembagian SHU (keputusan RAT)', null, [$m('amount', 'Jumlah'), $acc('account', 'Dibagikan sebagai', ['21130', '11102'])]],
            ['TB-06', 'tutup', 'Voucher kedaluwarsa', null, [$m('amount', 'Jumlah')]],
        ];

        $flows = $this->flows();
        $out = [];
        foreach ($defs as [$code, $division, $label, $hint, $fields]) {
            $out[$code] = ['code' => $code, 'division' => $division, 'label' => $label, 'hint' => $hint, 'fields' => $fields, 'flow' => $flows[$code] ?? null];
        }

        return $out;
    }

    /** Kategori arus kas per kejadian. */
    public function flows(): array
    {
        $map = [];
        foreach (['TK-01', 'TK-02', 'TK-03', 'TK-04', 'TK-05', 'TK-10', 'TK-11', 'TK-12', 'TK-13', 'TK-14', 'TK-15', 'TK-16', 'TK-17', 'TK-18', 'TK-19', 'TK-20', 'TK-21'] as $c) {
            $map[$c] = self::FLOW_TOKO;
        }
        foreach (['PK-02', 'PK-03', 'PK-04', 'PK-05', 'PK-06', 'PK-07', 'PK-08'] as $c) {
            $map[$c] = self::FLOW_PENGADAAN;
        }
        foreach (['PD-01', 'PD-02', 'PD-05', 'PD-06', 'PD-07', 'PB-01', 'PB-02', 'PB-03', 'PB-04'] as $c) {
            $map[$c] = self::FLOW_PEMBIAYAAN;
        }
        foreach (['SP-01', 'SP-02', 'SP-03', 'SP-11'] as $c) {
            $map[$c] = self::FLOW_POTONGAN;
        }
        foreach (['SP-08', 'SP-09', 'SP-10'] as $c) {
            $map[$c] = self::FLOW_TITIPAN;
        }
        foreach (['UM-01', 'UM-02', 'UM-04', 'UM-08', 'UM-09', 'UM-10', 'UM-11', 'UM-12'] as $c) {
            $map[$c] = self::FLOW_UMUM;
        }
        $map['UM-07'] = ['investasi', 'Pembelian aset tetap & perlengkapan'];
        $map['SP-04'] = ['pendanaan', 'Setoran simpanan anggota'];
        $map['SP-05'] = ['pendanaan', 'Setoran simpanan anggota'];
        $map['SP-06'] = ['pendanaan', 'Pengembalian simpanan anggota'];
        $map['SP-07'] = ['pendanaan', 'Pengembalian simpanan anggota'];
        $map['TB-04'] = ['pendanaan', 'Pembagian SHU tunai'];

        return $map;
    }

    /**
     * Terjemahkan isian kejadian menjadi satu atau lebih jurnal.
     *
     * @return list<array{description:string,lines:list<array>}>
     */
    public function build(string $code, array $in, string $date): array
    {
        $def = $this->definitions()[$code] ?? null;
        if (! $def) {
            throw ValidationException::withMessages(['event' => "Kejadian {$code} tidak dikenal."]);
        }
        $v = fn ($k) => (int) round((float) ($in[$k] ?? 0));
        $s = fn ($k, $default = null) => isset($in[$k]) && $in[$k] !== '' ? (string) $in[$k] : $default;
        $cust = isset($in['customer_id']) && $in['customer_id'] !== '' ? (int) $in['customer_id'] : null;
        foreach ($def['fields'] as $f) {
            if (($f['type'] === 'customer') && ($f['required'] ?? false) && ! $cust) {
                throw ValidationException::withMessages(['customer_id' => 'Pilih pegawai.']);
            }
        }
        $D = fn ($acc, $amt, $c = null, $memo = null) => ['account' => $acc, 'debit' => $amt, 'credit' => 0, 'customer_id' => $c, 'memo' => $memo];
        $K = fn ($acc, $amt, $c = null, $memo = null) => ['account' => $acc, 'debit' => 0, 'credit' => $amt, 'customer_id' => $c, 'memo' => $memo];
        $amt = $v('amount');
        $cash = $s('cash', '11102');
        $label = $def['label'];

        $one = fn (array $lines, ?string $desc = null) => [['description' => $desc ?? $label, 'lines' => $lines]];

        return match ($code) {
            'TK-01' => $one([$D('11103', $amt), $K($cash, $amt)]),
            'TK-02' => $one([$D('11501', $amt), $K($cash, $amt)]),
            'TK-03' => $one([$D('11501', $amt), $K('21111', $amt)]),
            'TK-04' => $one([$D('21111', $amt), $K($cash, $amt)]),
            'TK-05' => $one([
                $D('11104', $v('tunai')), $D($cash, $v('qris')), $D('11204', $v('pg')), $D('11206', $v('kantor')), $D('21130', $v('voucher')),
                $K('41100', $v('tunai') + $v('qris') + $v('pg') + $v('kantor') + $v('voucher')),
                $D('51100', $v('hpp')), $K('11501', $v('hpp')),
            ]),
            'TK-10' => $one([$D($cash, $amt), $K('11104', $amt)]),
            'TK-11' => $one([$D('41109', $amt), $K($cash, $amt), $D('11501', $v('hpp')), $K('51100', $v('hpp'))]),
            'TK-12' => $this->guard($v('komisi') <= $amt, 'Komisi tidak boleh lebih besar dari total penjualan.')
                ?? $one([$D($s('received', '11102'), $amt), $K('21101', $amt - $v('komisi')), $K('42101', $v('komisi'))]),
            'TK-13' => $one([$D('21101', $amt), $K($cash, $amt)]),
            'TK-14' => $s('arah') === 'lebih' ? $one([$D('11501', $amt), $K('51105', $amt)]) : $one([$D('51105', $amt), $K('11501', $amt)]),
            'TK-15' => $this->kantin($v('qris'), $v('tunai'), $v('pg'), $cash, $D, $K),
            'TK-16' => $one([$D('21104', $amt), $K($cash, $amt)]),
            'TK-17' => $one([$D('55101', $amt), $K('21103', $amt)]),
            'TK-18' => $one([$D('21103', $amt), $K($cash, $amt)]),
            'TK-19' => $one([$D($s('debit', '11202'), $amt), $K('45101', $amt)]),
            'TK-20' => $one([$D($cash, $amt), $K('11202', $amt)]),
            'TK-21' => $one([$D($cash, $amt), $K($s('account', '11205'), $amt)]),
            'PK-02' => $one([$D('11105', $amt), $K($cash, $amt)]),
            'PK-03' => $one([$D('54101', $amt), $K($s('paid', '11105'), $amt)]),
            'PK-04' => $one([$D('11201', $amt, null, $s('ref')), $K('44101', $amt, null, $s('ref'))], $s('ref') ? "Invoice {$s('ref')} ke kantor" : null),
            'PK-05' => $one([$D($cash, $amt - $v('pph22') - $v('pph23')), $D('11701', $v('pph22')), $D('11702', $v('pph23')), $K('11201', $amt)]),
            'PK-06' => $one([$D($cash, $amt), $K('11105', $amt)]),
            'PK-07' => $one([$D('21105', $amt), $K($cash, $amt)]),
            'PK-08' => $one([$D($cash, $amt), $K('54101', $amt)]),
            'PD-01' => $one([$D($cash, $amt, $cust), $K('46101', $amt, $cust)]),
            'PD-02' => $one([
                $D($s('account', '11301'), $v('pokok'), $cust),
                $K($cash, $v('pokok') - $v('pelunasan'), $cust),
                $K($s('account', '11301'), $v('pelunasan'), $cust, 'Pelunasan pinjaman lama'),
            ]),
            'PD-05' => $one([$D($cash, $amt, $cust), $K($s('account', '11301'), $amt, $cust)]),
            'PD-06' => $one([$D('61109', $amt, $cust), $K('11309', $amt, $cust)]),
            'PD-07' => $one([$D('11309', $amt, $cust), $K($s('account', '11301'), $amt, $cust)]),
            'PB-01' => $one([$D($cash, $amt, $cust), $K('46103', $amt, $cust)]),
            'PB-02' => $one([
                $D('11303', $v('harga') + $v('margin'), $cust),
                $K($cash, $v('harga'), $cust),
                $K('11304', $v('margin'), $cust),
            ]),
            'PB-03' => $one([$D('11304', $amt, $cust), $K('46102', $amt, $cust)]),
            'PB-04' => $one([$D($cash, $amt, $cust), $K('11303', $amt, $cust), $D('11304', $v('margin'), $cust), $K('46102', $v('margin'), $cust)]),
            'SP-01' => $this->payrollRecap($s('period'), $in, $D, $K),
            'SP-11' => $this->payrollRecap($s('period'), $in, $D, $K, sources: []),
            'SP-02' => $this->payrollReceipt($s('period'), array_map('intval', (array) ($in['failed'] ?? [])), $v('biaya'), $cash, $D, $K),
            'SP-03' => $one([$D($cash, $amt), $K('21190', $amt)]),
            'SP-04' => $one([$D($cash, $amt, $cust), $K('31202', $amt, $cust)]),
            'SP-05' => $one([$D($cash, $amt, $cust), $K($s('account', '31201'), $amt, $cust)]),
            'SP-06' => $one([
                $D('31101', $v('pokok'), $cust), $D($s('wajib_account', '31201'), $v('wajib'), $cust), $D('31301', $v('sukarela'), $cust),
                $K($s('loan_account', '11301'), $v('potong'), $cust),
                $K('21108', $v('pokok') + $v('wajib') + $v('sukarela') - $v('potong'), $cust),
            ]),
            'SP-07' => $one([$D('21108', $amt, $cust), $K($cash, $amt, $cust)]),
            'SP-08' => $one([$D($s('account', '21121'), $amt), $K($cash, $amt)]),
            'SP-09' => $one([$D('21106', $amt), $K($cash, $amt)]),
            'SP-10' => $one([$D('21110', $amt, $cust), $K($cash, $amt, $cust)]),
            'UM-01' => $one([$D('61101', $amt), $K($cash, $amt)]),
            'UM-02' => $one([$D($s('account', '61102'), $amt), $K($cash, $amt)]),
            'UM-04' => $one([$D($cash, $amt), $K('47103', $amt)]),
            'UM-07' => $one([$D($s('account', '12101'), $amt), $K($cash, $amt)]),
            'UM-08' => $one([$D('61106', $amt), $K($s('account', '12205'), $amt)]),
            'UM-09' => $one([$D('71101', $amt), $K('21109', $amt)]),
            'UM-10' => $one([$D('21109', $v('pph22') + $v('pph23')), $K('11701', $v('pph22')), $K('11702', $v('pph23'))]),
            'UM-11' => $one([$D('21109', $amt), $K($cash, $amt)]),
            'UM-12' => $this->guard($s('from') !== $s('to'), 'Rekening asal dan tujuan harus berbeda.')
                ?? $one([$D($s('to', '11101'), $amt), $K($s('from', '11102'), $amt)]),
            'TB-03' => $this->moveReserve($amt, $date, $D, $K),
            'TB-04' => $one([$D('33201', $amt), $K($s('account', '21130'), $amt)]),
            'TB-06' => $one([$D('21130', $amt), $K('47101', $amt)]),
            default => throw ValidationException::withMessages(['event' => "Kejadian {$code} belum punya pola jurnal."]),
        };
    }

    private function guard(bool $ok, string $message): ?array
    {
        if (! $ok) {
            throw ValidationException::withMessages(['event' => $message]);
        }

        return null;
    }

    /** Kantin: (1) Kas/QRIS/PG -> Uang Muka; (2) Uang Muka -> Bagi Hasil x% + Hutang Penjaga Kantin. */
    private function kantin(int $qris, int $tunai, int $pg, string $cash, callable $D, callable $K): array
    {
        $total = $qris + $tunai + $pg;
        if ($total <= 0) {
            throw ValidationException::withMessages(['event' => 'Isi penerimaan penjualan kantin.']);
        }
        $share = self::kantinShare();
        $koperasi = (int) round($total * $share / 100);
        $label = rtrim(rtrim(number_format($share, 2, ',', '.'), '0'), ',');

        return [
            ['description' => 'Penerimaan penjualan kantin (uang muka)', 'lines' => [
                $D($cash, $qris), $D('11104', $tunai), $D('11204', $pg), $K('21112', $total),
            ]],
            ['description' => "Bagi hasil kantin {$label}% dan hutang ke penjaga kantin", 'lines' => [
                $D('21112', $total), $K('43101', $koperasi), $K('21104', $total - $koperasi),
            ]],
        ];
    }

    /**
     * Rekap potongan gaji: debit Piutang Rekap per pegawai, kredit simpanan/angsuran/PG per pegawai
     * (data menu Angsuran & Potongan), ditambah total uang makan dan titipan pihak lain.
     */
    /** Rekap potongan untuk jurnal otomatis: hanya data impor/potong gaji, tanpa tambahan manual. */
    public function payrollRecapFor(string $period, array $sources = ['import', 'potong_gaji']): array
    {
        $D = fn ($acc, $amt, $c = null, $memo = null) => ['account' => $acc, 'debit' => $amt, 'credit' => 0, 'customer_id' => $c, 'memo' => $memo];
        $K = fn ($acc, $amt, $c = null, $memo = null) => ['account' => $acc, 'debit' => 0, 'credit' => $amt, 'customer_id' => $c, 'memo' => $memo];

        return $this->payrollRecap($period, [], $D, $K, $sources, false);
    }

    /** @param  ?list<string>  $sources  null = semua sumber; [] = tanpa data per pegawai (hanya tambahan) */
    private function payrollRecap(?string $period, array $in, callable $D, callable $K, ?array $sources = null, bool $failWhenEmpty = true): array
    {
        [$year, $month] = $this->period($period);
        $rows = $sources === [] ? collect() : MemberContribution::query()
            ->where('period_year', $year)->where('period_month', $month)
            ->when($sources !== null, fn ($q) => $q->whereIn('source', $sources))
            ->whereIn('type', MemberContribution::DEDUCTION_TYPES)
            ->where('amount', '>', 0)
            ->with('customer:id,name,employee_type')
            ->get();

        // Angsuran dibagi sama seperti menu Pembiayaan: tunggakan terlama dulu, lalu cicilan berikutnya
        // (dana/barang sesuai cicilan yang diisi); sisa setelah semua lunas = hutang lebih potong.
        $financing = app(\App\Services\Cooperative\FinancingService::class);
        $period = sprintf('%04d-%02d', $year, $month);
        $loanLines = function (Customer $c, int $amount, string $memo) use ($financing, $period, $K): array {
            $split = $financing->payrollPlan($c->id)['split'][$period] ?? [];
            $total = array_sum($split);
            if ($total <= 0) {
                $split = ['dana' => $amount];
                $total = $amount;
            }
            $lines = [];
            $left = $amount;
            $keys = array_keys($split);
            foreach ($keys as $n => $type) {
                $part = $n === count($keys) - 1 ? $left : (int) round($amount * $split[$type] / $total);
                $left -= $part;
                $acc = match ($type) {
                    'barang' => '11303',
                    'lebih' => '21110',
                    default => $c->employee_type === 'PPNPN' ? '11302' : '11301',
                };
                if ($part > 0) {
                    $lines[] = $K($acc, $part, $c->id, $type === 'lebih' ? $memo.' (lebih potong)' : $memo);
                }
            }

            return $lines;
        };

        $memo = sprintf('%04d-%02d', $year, $month);
        $lines = [];
        $perCustomer = [];
        foreach ($rows as $row) {
            $c = $row->customer;
            if (! $c) {
                continue;
            }
            if ($row->type === MemberContribution::TYPE_ANGSURAN) {
                array_push($lines, ...$loanLines($c, (int) $row->amount, $memo));
                $perCustomer[$c->id] = ($perCustomer[$c->id] ?? 0) + (int) $row->amount;

                continue;
            }
            $account = match ($row->type) {
                MemberContribution::TYPE_POKOK => '31101',
                MemberContribution::TYPE_WAJIB => $c->employee_type === 'PPNPN' ? '31202' : '31201',
                MemberContribution::TYPE_SUKARELA => '31301',
                MemberContribution::TYPE_PINJAMAN => '31401',
                MemberContribution::TYPE_PG => '11204',
                default => null,
            };
            if (! $account) {
                continue;
            }
            $lines[] = $K($account, (int) $row->amount, $c->id, $memo);
            $perCustomer[$c->id] = ($perCustomer[$c->id] ?? 0) + (int) $row->amount;
        }
        $debits = [];
        foreach ($perCustomer as $customerId => $total) {
            $debits[] = $D('11402', $total, $customerId, $memo);
        }

        $extra = ['uang_makan' => '11202', 'dkm' => '21121', 'dkp' => '21122', 'dwp' => '21123', 'rumdin' => '21124', 'kopesat' => '21106'];
        $extraTotal = 0;
        foreach ($extra as $key => $account) {
            $amount = (int) round((float) ($in[$key] ?? 0));
            if ($amount > 0) {
                $lines[] = $K($account, $amount, null, $memo);
                $extraTotal += $amount;
            }
        }
        if ($extraTotal > 0) {
            $debits[] = $D('11402', $extraTotal, null, $memo.' (titipan & uang makan)');
        }
        if (! $perCustomer && ! $extraTotal) {
            if (! $failWhenEmpty) {
                return [];
            }
            throw ValidationException::withMessages(['period' => $sources === [] ? 'Isi minimal satu nilai.' : "Belum ada data potongan {$memo} di menu Angsuran & Potongan."]);
        }
        $title = $sources === [] ? "Uang makan & titipan dalam rekap potongan {$memo}" : "Rekap potongan gaji {$memo}";

        return [['description' => $title, 'lines' => array_merge($debits, $lines)]];
    }

    /** Uang potongan diterima: lunasi piutang rekap per pegawai, kecuali yang gagal autodebet. */
    private function payrollReceipt(?string $period, array $failed, int $fee, string $cash, callable $D, callable $K): array
    {
        [$year, $month] = $this->period($period);
        $memo = sprintf('%04d-%02d', $year, $month);
        $accountId = Account::where('code', '11402')->value('id');
        $open = JournalLine::query()
            ->where('account_id', $accountId)
            ->where('memo', 'like', $memo.'%')
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(debit) - SUM(credit) as remaining')
            ->get();

        $credits = [];
        $total = 0;
        foreach ($open as $row) {
            $remaining = (int) $row->remaining;
            if ($remaining <= 0 || ($row->customer_id && in_array((int) $row->customer_id, $failed, true))) {
                continue;
            }
            $credits[] = $K('11402', $remaining, $row->customer_id, $row->customer_id ? $memo : $memo.' (titipan & uang makan)');
            $total += $remaining;
        }
        if ($total <= 0) {
            throw ValidationException::withMessages(['period' => "Tidak ada piutang rekap potongan {$memo} yang terbuka. Buat Rekap potongan gaji dulu."]);
        }

        return [['description' => "Penerimaan potongan gaji {$memo} dari rekening DKP", 'lines' => array_merge(
            [$D($cash, $total - $fee, null, $memo), $D('61104', $fee, null, $memo)],
            $credits,
        )]];
    }

    private function moveReserve(int $amount, string $date, callable $D, callable $K): array
    {
        if ($amount <= 0) {
            $amount = -app(ReportService::class)->accountBalance('33102', $date);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Saldo Dana Cadangan Tahun Berjalan kosong.']);
        }

        return [['description' => 'Pindah Dana Cadangan Tahun Berjalan ke Dana Cadangan Tahun Lalu', 'lines' => [$D('33102', $amount), $K('33101', $amount)]]];
    }

    private function period(?string $period): array
    {
        if (! $period || ! preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
            throw ValidationException::withMessages(['period' => 'Pilih bulan potongan.']);
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
