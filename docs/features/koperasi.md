# Koperasi: Pegawai, Simpanan, SHU

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menyatukan data pegawai, simpanan anggota, dan penjualan POS dalam **satu identitas pegawai (NIP)**
sehingga perhitungan SHU tidak lagi memerlukan pencocokan nama manual antar file Excel.

## Model data

| Tabel | Isi |
|-------|-----|
| `customers` (+kolom) | Pegawai = customer dengan `employee_type` (PNS/PPPK/PPNPN/LAINNYA), `nip` (unik), `rank`, `grade`, `position`, `work_unit`, `employment_status` |
| `customer_aliases` | Ejaan nama lain (mis. "AGUS Y") → pegawai. Terisi otomatis saat user mencocokkan nama di import |
| `member_contributions` | Pembayaran per bulan: `pokok`, `wajib`, `sukarela`, `angsuran` (pinjaman). `amount` negatif = penarikan |
| `external_sales` | Penjualan dari Excel per pembeli per tanggal, sumber SHU sebelum POS dipakai |
| `shu_periods` / `shu_allocations` | Pengaturan SHU per tahun buku dan hasil per orang |

Transaksi POS memakai `transactions.customer_id`, jadi belanja pegawai di kasir otomatis masuk SHU.

## Menu

### Koperasi → Data Pegawai (`employees.*`)
- CRUD pegawai + alias nama, filter jenis/status, export Excel.
- **Import Excel** dua langkah (pratinjau → simpan). Kolom: NIP, Nama / "N A M A", Jenis, Pangkat, Gol, Jabatan, Unit, Status, Alias (pisahkan dengan `;`).
  - NIP sudah ada & data sama → **dilewati**
  - NIP sudah ada & data beda → **dikonfirmasi** (tampil perbedaan lama → baru)
  - Belum ada → **langsung diimport**
  - NIP mirip (beda 1–2 digit), nama mirip dengan NIP beda, NIP sama tapi nama jauh beda,
    NIP dobel di file, atau format NIP salah → **perlu dicek**, default dilewati (NIP salah tidak pernah diimport otomatis)
- Validasi NIP 18 digit: tanggal lahir, bulan TMT, digit jenis kelamin, dan akhiran `000`
  (tanda NIP dibulatkan Excel karena disimpan sebagai angka).

### Koperasi → Simpanan Anggota (`savings.*`)
- Rekap per anggota per tahun (pokok, wajib, sukarela, angsuran, saldo simpanan) dan riwayat.
- Setor / tarik manual (penarikan dibatasi saldo jenis simpanan tersebut).
- **Generate potongan bulanan** wajib/pokok untuk pegawai berstatus AKTIF (pokok hanya sekali per orang).
- **Import Excel** format sheet "Master" (header bertingkat didukung): Nama Pegawai, Wajib, Pokok, Sukarela,
  Angsuran Koperasi, Bulan, Tahun; NIP opsional. Nama yang belum cocok dipetakan di layar
  (saran nama mirip, atau buat pegawai baru). Import ulang periode yang sama menimpa, tidak dobel.

### Koperasi → SHU (`shu.*`)
- Satu perhitungan per **tahun buku Y** (dibagikan di Y+1), pengaturan disalin dari tahun sebelumnya.
- Pengaturan: total SHU, base per orang, porsi % jasa simpanan / pembelian / pinjaman (total 100%),
  jenis simpanan yang dihitung, periode simpanan (tahun Y saja / sejak awal), sumber penjualan
  (**POS** atau **Excel**), pengecualian, pembulatan.
- Panel **Aturan SHU** menampilkan aturan yang berlaku dalam bahasa biasa.
- Sumber Excel: upload file penjualan (TGL BELANJA, NAMA PEGAWAI / Nama Final, TOTAL HARGA),
  petakan nama yang belum cocok → tersimpan sebagai alias.
- Hitung → hasil per orang → Export Excel → Kunci (final) / buka kunci.

## Aturan SHU (semua bisa diatur per tahun buku)

1. **Hanya pegawai aktif di tahun Y** yang ikut: masa aktif (`active_from`–`active_until` di Data Pegawai,
   kosong = aktif) mencakup tahun Y. Non-pegawai tidak dapat walau belanja. Pengecualian tidak dapat apa pun.
2. Penerima harus punya simpanan/iuran, pembelian, atau angsuran pinjaman di Y.
3. **Base SHU** (nominal bisa diganti):
   - *Siapa*: hanya pembayar simpanan (default) **atau** semua pegawai aktif yang ikut.
   - *PPNPN dapat base?* default **ya**, bisa dimatikan.
   - *Besaran*: penuh per orang **atau** proporsional bulan membayar (base × bulan ÷ 12).
   - Pegawai yang belanja tapi tidak iuran: dapat jasa pembelian, tanpa base (bila base hanya untuk pembayar).
4. **SHU Pengurus Koperasi**: nominal **per orang per bulan bertugas**; bulan bertugas tiap pengurus
   dicentang (Jan–Des) karena susunan pengurus bisa berganti di tengah tahun. SHU pengurus = nominal × jumlah
   bulan dicentang, dipotong dari SHU lebih dulu.
5. **Koreksi manual per orang** (base pengganti, tambahan/pengurangan nominal, mis. SHU pengurus koperasi) disimpan
   terpisah, tetap berlaku saat dihitung ulang, dan dipotong dari SHU lebih dulu.
6. Sisa = total − base − SHU pengurus − koreksi, dibagi ke **4 komponen** dengan porsi (%) yang bisa diubah dan wajib total 100%
   (bawaan periode baru: simpanan 40%, belanja 40%, pembiayaan dana 10%, pembiayaan barang 10%):
   - Simpanan: jenis (pokok/wajib/sukarela) dan periode (tahun Y / sejak awal) bisa dipilih.
   - Belanja: POS (`paid`/`unpaid`, dikurangi retur `completed`) atau import Excel.
   - Pembiayaan dana: **biaya formulir** kontrak yang dicairkan di Y.
   - Pembiayaan barang: **biaya lain** saat pencairan di Y + **bagi hasil** dari cicilan yang dibayar di Y.
   - Cicilan/angsuran tidak dihitung (kolom `portion_loans` lama tidak dipakai; nilainya dipindah ke pembiayaan dana).
6. Pembulatan: terdekat / ke atas / ke bawah / tidak, dengan kelipatan Rp 100–10.000.

## Pencocokan Nama (`name-links.*`)

Satu daftar untuk semua nama: nama pembeli di penjualan Excel, nama di file simpanan (`member_contributions.source_name`),
dan alias. Kolom SHU menampilkan hasil SHU tahun terpilih per pegawai. Menghubungkan/memindahkan nama langsung
memindahkan baris datanya dan menyimpan alias untuk import berikutnya. Nama penjualan dan alias bisa diputus;
nama simpanan hanya bisa dipindahkan ke pegawai lain.

## Pembiayaan Dana & Pembiayaan Barang (`financing.*`)

- **Dana**: pinjaman uang tanpa bagi hasil, jangka waktu bawaan & maksimal 12 bulan. Biaya formulir pegawai Rp 250.000 /
  PPNPN Rp 150.000 per Rp 5 jt pembiayaan; plafon pegawai Rp 15 jt, PPNPN Rp 10 jt.
- **Barang**: harga + bagi hasil 7,5% dicicil (bawaan 12 bulan, bisa dilunasi lebih cepat); biaya lain 1,5% dipotong saat cair.
- **Aturan terpisah** per jenis (`FinancingSettings::KEYS`, tombol Aturan di masing-masing menu) dan dipakai di formulir cetak.
- Identitas koperasi di formulir **tidak diisi ulang**: nama/alamat/kota dari Profil Toko (`store_name`, `store_address`,
  `store_city`); nama Ketua & Kepala Unit Usaha dari SK Pengurus yang berlaku (`BoardService::currentHolder`).
- Alur & otorisasi: pengajuan (`financing-create`) → diperiksa Divisi Pembiayaan (`financing-check`) → direview Kepala
  Unit Usaha (`financing-review`) → disetujui Ketua (`financing-approve`) → dicairkan Bendahara (`financing-disburse`,
  membuat jadwal cicilan mulai bulan berikutnya). Bisa ditolak dengan alasan sebelum cair.
- Cicilan dicentang lunas manual, otomatis dari potongan gaji, atau pelunasan dipercepat. Tanda macet; peringatan
  bila pegawai mutasi (`active_until`) masih punya sisa.
- Cetak: formulir permohonan (versi pegawai/PPNPN untuk dana, versi barang), formulir kosong, bukti pencairan (dengan terbilang).
- **Cek Angsuran** (`financing.reconcile`): per pegawai per bulan membandingkan jadwal cicilan, status lunas (monitoring),
  dan potongan gaji `angsuran` dari rekap Master → selisih + tombol tandai lunas sesuai potongan.
- **Impor**: daftar dana (PEMBIAYAAN KOPERASI / Data Pembiayaan LK), daftar barang, dan Permohonan Pencairan (biaya formulir,
  rekening) — dikenali otomatis per sheet, tidak dobel saat diimpor ulang.

## Pengurus Koperasi (`board.*`)

- Upload SK pengurus (PDF/Word/foto, maks 20 MB). File disimpan di storage privat (`storage/app/private/board-decrees`,
  tidak masuk git) dan hanya bisa dibuka pengguna dengan izin `board-access`.
- Nama pengurus dibaca otomatis: Word di server (`BoardService::text`, baris tabel jadi "Jabatan : Nama"), PDF di
  browser (pdf.js, `?worker`). Pola SK "Jabatan : Nama" — nama bernomor di baris bawah ikut jabatan itu; label yang
  terpotong ("Divisi Pembiayaan" / "Barang") disambung. Nama dicocokkan lewat NIP, nama persis/alias, atau nama mirip
  (ditandai *periksa*); yang tidak ketemu tetap tampil untuk dipilih manual. Tanggal SK diambil dari bagian "Ditetapkan di … pada tanggal …" (tanpa tanggal hari → tanggal 1). Scan/foto tidak terbaca → diisi manual.
- Jabatan dibakukan: Pembina, Ketua, Wakil Ketua, Sekretaris, Bendahara, Ketua Pengawas, Pengawas, Kepala Unit Usaha,
  Anggota Pengurus. Divisi bertanda "-" = Anggota Pengurus; bagian lain (Akuntansi dan Pelaporan, IT Support) = orang
  pertama Kepala Unit Usaha, sisanya Anggota Pengurus. Label asli SK ditampilkan sebagai keterangan.
- Masa berlaku: `effective_from` s.d. `effective_until`, atau sehari sebelum SK berikutnya mulai. Bulan bertugas =
  SK yang berlaku di **akhir bulan** (`BoardService::membersForYear`).
- SHU: periode baru langsung terisi pengurus + bulan dari SK; di halaman SHU ada tombol *Ambil dari SK pengurus*.

## Unduh & unggah data

Semua file unduhan bisa diedit lalu diunggah ulang; unggah ulang **memperbarui**, tidak membuat data dobel.

| Data | Unduh | Unggah ulang (kunci pencocokan) |
|------|-------|---------------------------------|
| Pegawai | Data Pegawai → Unduh pegawai | Impor Excel pegawai (NIP; beda → konfirmasi) |
| Anggota | Data Pegawai → Unduh anggota | — (anggota otomatis dari pembayar iuran) |
| Simpanan | Simpanan → Data {tahun} (kolom `Total Pokok/Wajib/Sukarela`) | Impor Excel simpanan: nilai = total bulan itu; catatan import disesuaikan dengan selisih terhadap catatan manual/tunai |
| Pembiayaan | Pembiayaan Dana/Barang → Unduh Excel | Impor Excel pembiayaan (jenis + pegawai + nominal + tanggal cair) |
| Pengurus | Pengurus Koperasi → Unduh Excel | Upload SK |
| SHU | SHU → Ekspor Excel | — |

Impor simpanan hanya menimpa jenis yang ada kolomnya di file (file tanpa kolom angsuran tidak menghapus angsuran).
Kolom berformat teks (`ArrayExport` format `@`) ditulis sebagai teks supaya NIP 18 digit tidak berubah jadi angka.

## Anggota & customer

- Pembayar simpanan pokok/wajib/sukarela otomatis ditandai **anggota** (member, kode = NIP atau `AGT-xxxxx`).
- Nama pembeli di data penjualan Excel yang bukan pegawai otomatis dibuat sebagai **customer biasa** (non-member, tanpa SHU).
- Jalan saat impor simpanan/penjualan, saat migrasi, atau manual: `php artisan koperasi:sync-anggota`.
- Impor rekap simpanan memakai sheet berformat Master dengan data **paling baru** (mis. "Master (backup)" s.d. 2026).

## Tampilan Menu

Pengaturan → Tampilan Menu (`menu-settings`): sembunyikan menu sidebar yang tidak dipakai (disimpan di setting
`hidden_menus`). Hanya tampilan; hak akses tetap lewat Role.

## Permission

`employees-access|create|edit|delete|import`, `savings-access|create|delete|import`,
`shu-access|manage|finalize`, `financing-access|create|check|review|approve|disburse|import`, `board-access|manage`, `menu-settings`. Dibuat oleh `PermissionSeeder` dan oleh migrasi
`2026_10_31_000002_add_cooperative_permissions` (untuk instalasi yang sudah berjalan).

## File terkait

- `app/Services/Cooperative/` — `EmployeeImportService`, `ContributionImportService`,
  `ExternalSalesImportService`, `ShuCalculator`, `NameMatcher`, `Nip`, `SpreadsheetReader`
- `app/Http/Controllers/Apps/{Employee,MemberContribution,Shu}Controller.php`
- `resources/js/Pages/Dashboard/Cooperative/`
- `tests/Feature/Cooperative/`
