# Installer POS Koperasi untuk Windows (Laragon).
# Aman dijalankan ulang: .env, database, dan data yang sudah ada tidak dihapus.
# Jalankan lewat install.bat (klik dua kali).

param(
    [string]$Database = "pos_koperasi",
    [string]$PublicUrl = "https://kabanjawara.anzero.workers.dev"
)

$ErrorActionPreference = "Stop"

function Step($text) { Write-Host "`n== $text ==" -ForegroundColor Cyan }
function Run($exe, [string[]]$arguments) {
    & $exe @arguments
    if ($LASTEXITCODE -ne 0) { throw "Perintah gagal: $exe $($arguments -join ' ')" }
}
function Set-EnvValue($file, $key, $value) {
    $path = Join-Path $PWD.Path $file
    $lines = @(Get-Content $path)
    if ($lines -match "^$key=") {
        $lines = $lines -replace "^$key=.*", "$key=$value"
    } else {
        $lines += "$key=$value"
    }
    [System.IO.File]::WriteAllLines($path, [string[]]$lines)  # UTF-8 tanpa BOM
}

Step "1/8 Cek program yang dibutuhkan"
$missing = @()
foreach ($tool in "php", "composer", "git", "node", "npm", "mysql", "mysqldump") {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { $missing += $tool }
}
if ($missing.Count -gt 0) {
    Write-Host "Belum ditemukan: $($missing -join ', ')" -ForegroundColor Red
    Write-Host "Pasang Laragon Full, nyalakan MySQL (Start All), lalu Menu > Tools > Path > Add Laragon to Path. Tutup jendela ini dan jalankan ulang."
    exit 1
}
$phpVersion = (& php -r "echo PHP_VERSION;")
if ([version]($phpVersion -replace '[^0-9.].*$', '') -lt [version]"8.3") {
    Write-Host "PHP $phpVersion terlalu lama. Di Laragon pilih PHP 8.3 atau lebih baru." -ForegroundColor Red
    exit 1
}

Step "2/8 Folder aplikasi"
$Dir = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location $Dir
if (-not (Test-Path ".git")) {
    Write-Host "Folder ini bukan hasil 'git clone', jadi update.bat tidak akan bisa mengambil update." -ForegroundColor Red
    Write-Host "Pasang ulang dengan: git clone https://github.com/vreyz/pos_koperasi.git C:\laragon\www\pos_koperasi"
    exit 1
}
Write-Host $Dir

Step "3/8 Konfigurasi .env"
if (-not (Test-Path ".env")) { Copy-Item ".env.example" ".env" }
$publicHost = ([uri]$PublicUrl).Host
Set-EnvValue ".env" "APP_ENV" "production"
Set-EnvValue ".env" "APP_DEBUG" "false"
Set-EnvValue ".env" "APP_URL" $PublicUrl
Set-EnvValue ".env" "APP_TIMEZONE" "Asia/Jakarta"
Set-EnvValue ".env" "APP_LOCALE" "id"
Set-EnvValue ".env" "DB_CONNECTION" "mysql"
Set-EnvValue ".env" "DB_DATABASE" $Database
# .dev (termasuk workers.dev) selalu HTTPS di browser; false agar login lewat http://127.0.0.1 tetap bisa.
Set-EnvValue ".env" "SESSION_SECURE_COOKIE" "false"
Set-EnvValue ".env" "SANCTUM_STATEFUL_DOMAINS" "$publicHost,localhost,127.0.0.1,127.0.0.1:8000"
New-Item -ItemType Directory -Force -Path "data-private", "backups" | Out-Null

$envText = Get-Content ".env" -Raw
$dbUser = if ($envText -match "(?m)^DB_USERNAME=(.*)$") { $Matches[1].Trim() } else { "root" }
$dbPass = if ($envText -match "(?m)^DB_PASSWORD=(.*)$") { $Matches[1].Trim() } else { "" }

Step "4/8 Database"
$tableCount = (& mysql -u $dbUser "--password=$dbPass" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$Database'")
if ($LASTEXITCODE -ne 0) { throw "Tidak bisa terhubung ke MySQL. Pastikan MySQL di Laragon sudah Start." }
$freshDatabase = ([int]$tableCount -eq 0)
Run mysql @("-u", $dbUser, "--password=$dbPass", "-e", "CREATE DATABASE IF NOT EXISTS ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")
if (-not $freshDatabase) {
    $stamp = Get-Date -Format "yyyyMMdd-HHmm"
    & cmd /c "mysqldump -u $dbUser --password=$dbPass $Database > backups\$Database-$stamp-sebelum-install.sql"
    Write-Host "Database lama sudah ada: dibackup ke backups\$Database-$stamp-sebelum-install.sql"
}

Step "5/8 Paket PHP"
Run composer @("install", "--no-dev", "--optimize-autoloader", "--no-interaction")
if ($envText -notmatch "(?m)^APP_KEY=base64:") { Run php @("artisan", "key:generate", "--force") }

Step "6/8 Tampilan (build)"
$env:PUPPETEER_SKIP_DOWNLOAD = "true"
Run npm @("ci", "--no-audit", "--no-fund")
Run npm @("run", "build")

Step "7/8 Struktur database"
Run php @("artisan", "migrate", "--force")
if ($freshDatabase) { Run php @("artisan", "db:seed", "--force") }
if (-not (Test-Path "public\storage")) { Run php @("artisan", "storage:link") }
Run php @("artisan", "optimize:clear")

Step "8/8 Jalan otomatis saat login & backup harian"
$start = Join-Path $Dir "deploy\windows\start.bat"
$backup = Join-Path $Dir "deploy\windows\backup.bat"
$startup = [Environment]::GetFolderPath("Startup")
$shortcut = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $startup "POS Koperasi.lnk"))
$shortcut.TargetPath = $start
$shortcut.WorkingDirectory = $Dir
$shortcut.WindowStyle = 7  # minimized
$shortcut.Save()
& schtasks /Create /F /SC DAILY /ST 21:00 /TN "POS Koperasi - Backup" /TR "`"$backup`"" | Out-Null

Write-Host "`nSelesai." -ForegroundColor Green
Write-Host "- Jalankan sekarang: klik dua kali deploy\windows\start.bat, lalu buka http://127.0.0.1:8000"
if ($freshDatabase) { Write-Host "- Pertama kali akan muncul halaman /setup untuk membuat akun admin." }
Write-Host "- Server otomatis jalan setiap login Windows (pastikan Laragon: Preferences > Run when Windows starts + Start All automatically)."
Write-Host "- Database dibackup tiap hari jam 21:00 ke folder backups."
Write-Host "- Untuk akses online, jalankan deploy\windows\setup-cloudflare.bat"
