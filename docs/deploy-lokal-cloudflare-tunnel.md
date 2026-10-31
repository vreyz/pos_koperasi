# Jalankan Lokal + Akses Online via Cloudflare Tunnel

Kembali ke indeks dokumentasi: `docs/README.md`

Aplikasi dan **seluruh data berjalan di PC lokal** (Windows). Cloudflare Tunnel hanya meneruskan
akses HTTPS dari internet/HP ke PC tersebut. Tidak ada port router yang dibuka, dan database tidak
pernah keluar dari PC.

```
HP / laptop ──HTTPS──▶ Cloudflare ──tunnel──▶ cloudflared (PC lokal) ──▶ http://127.0.0.1:8000 (Laravel) ──▶ MySQL lokal
```

> Data Excel asli (pegawai, simpanan, penjualan) simpan di folder `data-private/` di dalam project
> atau di luar project. Folder `data-private/` sudah di-`.gitignore`, jadi tidak ikut ke GitHub.
> Import ke aplikasi dilakukan lewat menu **Koperasi → Data Pegawai / Simpanan / SHU**.

## 1. Kebutuhan di Windows

- [Laragon](https://laragon.org/) (atau XAMPP) dengan **PHP 8.3+** dan MySQL/MariaDB
- Composer, Node.js 22 LTS + npm, Git
- Akun Cloudflare dengan domain yang sudah aktif (zona `anzero…`)

## 2. Instalasi aplikasi (sekali)

Buka terminal di folder project (mis. `D:\OneDrive\09ProjectProgramming\21. POS_Koperasi`):

```bash
copy .env.example .env
composer install
set PUPPETEER_SKIP_DOWNLOAD=true && npm install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
npm run build
```

Isi `.env` (ganti `kabanjawara.anzero.<domain>` dengan hostname tunnel Anda):

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://kabanjawara.anzero.<domain>
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

DB_DATABASE=pos_koperasi
DB_USERNAME=root
DB_PASSWORD=

SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=kabanjawara.anzero.<domain>,localhost,127.0.0.1
```

Lalu `php artisan config:clear`. Buka `http://127.0.0.1:8000` pertama kali → otomatis ke wizard `/setup`
untuk membuat akun admin.

> **OneDrive:** folder project di dalam OneDrive akan ikut menyinkronkan `vendor/`, `node_modules/`,
> dan `storage/`. Bila sinkronisasi terasa berat atau bentrok, pindahkan project ke folder di luar
> OneDrive (mis. `C:\laragon\www\pos_koperasi`) dan biarkan OneDrive hanya untuk backup database.

## 3. Menjalankan aplikasi

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Biarkan jendela ini terbuka (atau pakai server Apache/Nginx Laragon yang mengarah ke folder `public/`).
Untuk pengembangan dengan hot reload gunakan `composer run dev`, tetapi akses lewat tunnel memakai
hasil `npm run build`.

## 4. Membuat Cloudflare Tunnel (sekali)

1. Masuk [Cloudflare Zero Trust](https://one.dash.cloudflare.com/) → **Networks → Tunnels → Create a tunnel**.
2. Pilih **Cloudflared**, beri nama `pos-koperasi`.
3. Pilih **Windows**, salin perintah instalasi yang berisi token, lalu jalankan di **Command Prompt (Administrator)**:

   ```bash
   cloudflared.exe service install <TOKEN-DARI-DASHBOARD>
   ```

   cloudflared terpasang sebagai Windows Service dan ikut menyala saat PC dinyalakan.
4. Tab **Public Hostname → Add a public hostname**:
   - Subdomain: `kabanjawara` (atau sesuai yang dipilih), Domain: domain `anzero…` Anda
   - Service: **HTTP** → `127.0.0.1:8000`
5. Simpan. Buka `https://kabanjawara.anzero.<domain>` dari HP.

Laravel sudah dikonfigurasi mempercayai header `X-Forwarded-*` dari `127.0.0.1`, sehingga link, aset,
dan cookie otomatis memakai HTTPS di balik tunnel.

### Membatasi siapa yang bisa membuka (disarankan)

Karena berisi data pegawai, tambahkan **Zero Trust → Access → Applications → Self-hosted** untuk
hostname tersebut dengan policy *Allow* hanya untuk email pengurus koperasi. Pengunjung harus
memasukkan kode OTP email Cloudflare sebelum melihat halaman login aplikasi.

## 5. Akses dari HP

- Buka `https://kabanjawara.anzero.<domain>` di Chrome Android → menu ⋮ → **Install app / Tambahkan ke layar utama**.
- Scanner barcode kamera dan install PWA hanya jalan lewat HTTPS (tunnel). Lewat `http://192.168.x.x:8000`
  di WiFi kantor aplikasi tetap bisa dipakai, tetapi tanpa kamera/PWA. Untuk akses WiFi, jalankan
  `php artisan serve --host=0.0.0.0 --port=8000`.

## 6. Backup

Database adalah satu-satunya sumber data. Backup rutin, misalnya harian:

```bash
mysqldump -u root pos_koperasi > D:\Backup\pos_koperasi-%date:~-4%%date:~3,2%%date:~0,2%.sql
```

Simpan backup di lokasi terpisah (OneDrive pribadi/eksternal), bukan di repository GitHub.

## 7. Update aplikasi

```bash
git pull
composer install
npm install && npm run build
php artisan migrate --force
php artisan config:clear
```

Migrasi modul koperasi otomatis membuat izin `employees-*`, `savings-*`, `shu-*` dan memberikannya ke
role `super-admin` dan `manager`. Logout lalu login lagi setelah update agar menu baru muncul.
