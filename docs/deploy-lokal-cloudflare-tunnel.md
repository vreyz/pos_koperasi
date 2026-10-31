# Jalankan Lokal + Akses Online (kabanjawara.anzero.workers.dev)

Kembali ke indeks dokumentasi: `docs/README.md`

Aplikasi dan **seluruh data berjalan di PC lokal** (Windows). Cloudflare hanya meneruskan akses
HTTPS dari internet/HP ke PC tersebut. Tidak ada port router yang dibuka dan database tidak pernah
keluar dari PC.

```
HP / laptop ─HTTPS─▶ Worker kabanjawara.anzero.workers.dev ─Workers VPC─▶ Tunnel ─▶ cloudflared (PC) ─▶ http://127.0.0.1:8000 ─▶ MySQL lokal
```

> Data Excel asli (pegawai, simpanan, penjualan) simpan di folder `data-private/` di dalam project.
> Folder itu, `backups/`, `.env`, dan `storage/` tidak pernah ikut ke GitHub.

## 1. Kebutuhan di Windows (sekali)

- [Laragon Full](https://laragon.org/) — sudah berisi PHP, MySQL, Git, Composer, Node. Pastikan PHP **8.3+**
  dan aktifkan *Menu → Tools → Path → Add Laragon to Path*.
- Akun Cloudflare (yang sama dengan `atlas.anzero.workers.dev`).

## 2. Pasang aplikasi (sekali, otomatis)

Nyalakan Laragon (**Start All**), buka **Terminal Laragon**, lalu tempel:

```bash
git clone https://github.com/vreyz/pos_koperasi.git C:\laragon\www\pos_koperasi
```

Lalu klik dua kali `C:\laragon\www\pos_koperasi\deploy\windows\install.bat`. Installer akan:

- mengecek PHP 8.3+, MySQL, Composer, Node, Git;
- mengisi `.env` (alamat online, zona waktu, bahasa, database `pos_koperasi`);
- membuat database, memasang paket, membangun tampilan, menjalankan migrasi;
- membuat folder `data-private\` dan `backups\`;
- menjalankan aplikasi otomatis setiap login Windows dan backup database tiap hari jam 21:00.

Aman dijalankan ulang: `.env` dan database yang sudah ada tidak dihapus (database lama dibackup dulu).
Di Laragon aktifkan juga *Preferences → Run Laragon when Windows starts* dan *Start All automatically*.

> Folder di dalam OneDrive (`D:\OneDrive\...`) **tidak disarankan** untuk aplikasi: OneDrive ikut
> menyinkronkan ribuan file `vendor/` dan `node_modules/` dan bisa mengunci file. Simpan aplikasi di
> `C:\laragon\www\pos_koperasi`; OneDrive cukup untuk menyimpan salinan folder `backups/`.

## 3. Menjalankan

Klik dua kali `deploy\windows\start.bat` (biarkan jendelanya terbuka), lalu buka `http://127.0.0.1:8000`.
Pertama kali akan muncul wizard `/setup` untuk membuat akun admin.

Dari HP di WiFi yang sama: `http://<IP-PC>:8000` (tanpa kamera/PWA). Untuk akses online lihat langkah 4.

## 4. Online di kabanjawara.anzero.workers.dev (sekali, ±15 menit)

Alamat `*.workers.dev` tidak bisa langsung dipasang ke tunnel, jadi dipakai **Worker kecil** yang
meneruskan request ke tunnel (fitur **Workers VPC**). Worker-nya sudah ada di `deploy/cloudflare-worker/`.

**a. Buat tunnel** (dashboard)
1. [dash.cloudflare.com](https://dash.cloudflare.com/) → **Zero Trust → Networks → Tunnels → Create a tunnel** → *Cloudflared* → nama `pos-koperasi`.
2. Pilih **Windows** dan salin perintah berisi token (cukup disalin, belum perlu dijalankan). Lewati langkah *Public hostname*.

**b. Buat VPC Service** (dashboard)
1. **Workers VPC → VPC Services → Create**.
2. Tunnel: `pos-koperasi`; Host: `127.0.0.1`; HTTP port: `8000`.
3. Salin **Service ID**.

**c. Jalankan `deploy\windows\setup-cloudflare.bat`** (klik dua kali, izinkan Administrator)
- tempel token tunnel dan Service ID saat diminta;
- browser terbuka untuk login Cloudflare → klik **Allow**;
- skrip memasang `cloudflared` sebagai Windows Service dan men-deploy Worker `kabanjawara` →
  tayang di `https://kabanjawara.anzero.workers.dev`.

Service ID disimpan di `deploy/cloudflare-worker/wrangler.local.jsonc` (tidak ikut Git), jadi update
aplikasi tidak bentrok.

Laravel sudah dikonfigurasi mempercayai header `X-Forwarded-*` dari `127.0.0.1`, sehingga link, aset, dan
cookie memakai `https://kabanjawara.anzero.workers.dev`.

**Disarankan — batasi akses:** Zero Trust → **Access → Applications → Add → Self-hosted**, domain
`kabanjawara.anzero.workers.dev`, policy *Allow* untuk email pengurus. Pengunjung lain tidak bisa
membuka halaman login sama sekali.

**Syarat:** PC harus menyala, `start.bat` berjalan, dan internet tersambung agar alamat online bisa dibuka.

> Bila nanti punya domain sendiri di Cloudflare, Worker tidak diperlukan: di tunnel tambahkan
> *Public hostname* → `http://127.0.0.1:8000`, lalu ganti `APP_URL` dan `SANCTUM_STATEFUL_DOMAINS`.

## 5. HP

Buka `https://kabanjawara.anzero.workers.dev` di Chrome Android → ⋮ → **Install app**. Scanner kamera
dan mode aplikasi (PWA) hanya berfungsi lewat alamat HTTPS ini.

## 6. Backup

`deploy\windows\backup.bat` menyimpan database ke `backups\pos_koperasi-YYYYMMDD-HHMM.sql`. Installer sudah
menjadwalkannya tiap hari jam 21:00 (Task Scheduler: *POS Koperasi - Backup*). Salin folder `backups` ke
OneDrive/flashdisk secara berkala.

## 7. Update aplikasi (tanpa kehilangan data & tambahan koperasi)

Semua tambahan koperasi tersimpan di repository **vreyz/pos_koperasi** (fork), terpisah dari repository
developer asli. Alurnya:

1. **Ambil update developer ke fork** — buka `https://github.com/vreyz/pos_koperasi`, klik **Sync fork → Update branch**.
   Bila GitHub menyatakan ada *conflict*, minta bantuan Claude/developer untuk menggabungkan; jangan klik *Discard*.
2. **Pasang di PC** — klik dua kali `deploy\windows\update.bat`. Skrip ini:
   - mem-backup database lebih dulu,
   - mengambil kode terbaru (`git pull`),
   - memasang paket & membangun tampilan,
   - menjalankan migrasi (hanya menambah tabel/kolom, data lama tetap).

Yang **tidak pernah tersentuh** oleh update: isi database, `.env`, file upload (`storage/`),
`data-private/`, `backups/`. Bila update gagal, skrip berhenti dan backup tetap ada.

Jangan mengedit file kode langsung di PC; perubahan kode dilakukan lewat repository supaya update
berikutnya tidak bentrok.
