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

## Aturan SHU

1. **Base SHU** untuk setiap pegawai selain PPNPN yang membayar simpanan (pokok/wajib/sukarela) pada tahun Y.
   Pegawai mutasi/pensiun di tahun Y tetap dapat. Yang berhenti membayar sebelum Y tidak dapat.
2. Sisa = total SHU − (base × jumlah pembayar), dibagi sesuai porsi.
3. Jasa simpanan ∝ simpanan anggota yang membayar di Y.
4. Jasa pembelian ∝ belanja pegawai di Y. POS: `payment_status` `paid`/`unpaid` dikurangi retur `completed`.
   PPNPN tidak pernah dapat base, tetapi tetap dapat jasa pembelian (dan jasa simpanan bila membayar).
5. Jasa pinjaman ∝ angsuran pinjaman yang dibayar di Y.
6. Pengecualian dan customer non-pegawai (UMUM, dll.) tidak mendapat SHU.

## Permission

`employees-access|create|edit|delete|import`, `savings-access|create|delete|import`,
`shu-access|manage|finalize`. Dibuat oleh `PermissionSeeder` dan oleh migrasi
`2026_10_31_000002_add_cooperative_permissions` (untuk instalasi yang sudah berjalan).

## File terkait

- `app/Services/Cooperative/` — `EmployeeImportService`, `ContributionImportService`,
  `ExternalSalesImportService`, `ShuCalculator`, `NameMatcher`, `Nip`, `SpreadsheetReader`
- `app/Http/Controllers/Apps/{Employee,MemberContribution,Shu}Controller.php`
- `resources/js/Pages/Dashboard/Cooperative/`
- `tests/Feature/Cooperative/`
