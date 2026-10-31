# Installer / perbaikan POS Koperasi untuk runtime lokal di ..\.local-pos
# (PHP portable, Caddy, MySQL port 33307, tunnel Cloudflare). Tidak memakai Laragon Start All.
# Aman dijalankan ulang: .env, database, dan data yang sudah ada tidak dihapus.
# Jalankan lewat install.bat (klik dua kali).

$ErrorActionPreference = "Stop"

function Step($text) { Write-Host "`n== $text ==" -ForegroundColor Cyan }
function Run($exe, [string[]]$arguments) {
    & $exe @arguments
    if ($LASTEXITCODE -ne 0) { throw "Perintah gagal: $exe $($arguments -join ' ')" }
}

$Dir = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$Runtime = Join-Path (Split-Path -Parent $Dir) ".local-pos"
$php = Join-Path $Runtime "php\php.exe"
$composer = Join-Path $Runtime "composer.phar"
$env:PATH = (Split-Path -Parent $php) + ";" + $env:PATH
Set-Location $Dir

Step "1/7 Cek runtime lokal"
$missing = @()
foreach ($file in "php\php.exe", "php\php-cgi.exe", "composer.phar", "caddy\caddy.exe", "Caddyfile", "mysql.ini", "start.ps1", "backup.ps1") {
    if (-not (Test-Path (Join-Path $Runtime $file))) { $missing += $file }
}
foreach ($tool in "git", "node", "npm") {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { $missing += $tool }
}
if ($missing.Count -gt 0) {
    Write-Host "Belum ditemukan: $($missing -join ', ')" -ForegroundColor Red
    Write-Host "Runtime POS seharusnya ada di $Runtime. Hubungi developer."
    exit 1
}
if (-not (Test-Path ".env")) {
    Write-Host ".env belum ada di $Dir. File ini berisi password database; hubungi developer." -ForegroundColor Red
    exit 1
}
Write-Host "Aplikasi: $Dir"
Write-Host "Runtime : $Runtime"

Step "2/7 Nyalakan database dan server"
& (Join-Path $Runtime "start.ps1") -NoBrowser

Step "3/7 Backup database"
& (Join-Path $Runtime "backup.ps1") -Label "sebelum-install"
New-Item -ItemType Directory -Force -Path "data-private", "backups" | Out-Null

Step "4/7 Paket PHP"
Run $php @($composer, "install", "--no-dev", "--optimize-autoloader", "--no-interaction")
if ((Get-Content ".env" -Raw) -notmatch "(?m)^APP_KEY=base64:") { Run $php @("artisan", "key:generate", "--force") }

Step "5/7 Tampilan (build)"
$env:PUPPETEER_SKIP_DOWNLOAD = "true"
Run npm @("ci", "--no-audit", "--no-fund")
Run npm @("run", "build")

Step "6/7 Struktur database (migrasi, data lama tetap)"
Run $php @("artisan", "migrate", "--force")
if (-not (Test-Path "public\storage")) { Run $php @("artisan", "storage:link") }
Run $php @("artisan", "optimize:clear")

Step "7/7 Jalan otomatis saat login & backup harian 21:00"
& (Join-Path $Runtime "install-autostart.ps1")

Write-Host "`nSelesai." -ForegroundColor Green
Write-Host "- Buka http://127.0.0.1:8000 (di PC ini) atau https://kabanjawara.anzero.workers.dev (online)."
Write-Host "- Cek nyala/mati: STATUS_POS.cmd di folder induk."
Write-Host "- Update di kemudian hari: UPDATE_POS.cmd (atau deploy\windows\update.bat)."
