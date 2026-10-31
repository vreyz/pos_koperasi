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

## 2. Pasang aplikasi (sekali)

Buka **Terminal Laragon**:

```bash
cd C:\laragon\www
git clone https://github.com/vreyz/pos_koperasi.git
cd pos_koperasi
copy .env.example .env
composer install
set PUPPETEER_SKIP_DOWNLOAD=true && npm ci
php artisan key:generate
```

Buat database `pos_koperasi` (Laragon → Database), lalu edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kabanjawara.anzero.workers.dev
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id
DB_DATABASE=pos_koperasi
DB_USERNAME=root
DB_PASSWORD=
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=kabanjawara.anzero.workers.dev,localhost,127.0.0.1
```

```bash
php artisan migrate --seed
php artisan storage:link
npm run build
```

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

**a. Buat tunnel**
1. [dash.cloudflare.com](https://dash.cloudflare.com/) → **Zero Trust → Networks → Tunnels → Create a tunnel** → *Cloudflared* → nama `pos-koperasi`.
2. Pilih **Windows**, salin perintah berisi token, jalankan di **Command Prompt (Run as administrator)**:
   ```bash
   cloudflared.exe service install <TOKEN>
   ```
   Tunnel jadi Windows Service dan otomatis menyala bersama PC. Lewati langkah *Public hostname*.

**b. Buat VPC Service**
1. Dashboard → **Workers VPC → VPC Services → Create**.
2. Tunnel: `pos-koperasi`; Host: `127.0.0.1`; HTTP port: `8000`.
3. Salin **Service ID**.

**c. Pasang Worker**
1. Buka `deploy/cloudflare-worker/wrangler.jsonc`, ganti `GANTI-DENGAN-ID-VPC-SERVICE` dengan Service ID.
2. Di Terminal Laragon:
   ```bash
   cd deploy\cloudflare-worker
   npx wrangler login
   npx wrangler deploy
   ```
   Nama Worker `kabanjawara` → otomatis tayang di `https://kabanjawara.anzero.workers.dev`.

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

`deploy\windows\backup.bat` menyimpan database ke `backups\pos_koperasi-YYYYMMDD-HHMM.sql`. Jadwalkan
harian lewat **Task Scheduler** dan salin folder `backups` ke OneDrive/flashdisk.

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
