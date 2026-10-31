# Menyambungkan aplikasi lokal ke https://kabanjawara.anzero.workers.dev.
# Butuh 2 kode dari dashboard Cloudflare (lihat docs/deploy-lokal-cloudflare-tunnel.md langkah 4):
#   1. token tunnel (Zero Trust > Networks > Tunnels > Create a tunnel)
#   2. Service ID VPC (Workers VPC > VPC Services > Create, host 127.0.0.1, port 8000)
# Jalankan lewat setup-cloudflare.bat (klik dua kali, izinkan Administrator).

$ErrorActionPreference = "Stop"
Set-Location (Join-Path $PSScriptRoot "..\..")

function Step($text) { Write-Host "`n== $text ==" -ForegroundColor Cyan }

Step "1/4 Pasang cloudflared"
if (-not (Get-Command cloudflared -ErrorAction SilentlyContinue)) {
    winget install --id Cloudflare.cloudflared -e --accept-source-agreements --accept-package-agreements
    $env:Path = [Environment]::GetEnvironmentVariable("Path", "Machine") + ";" + [Environment]::GetEnvironmentVariable("Path", "User")
}

Step "2/4 Sambungkan tunnel (berjalan otomatis sebagai Windows Service)"
$token = Read-Host "Tempel token tunnel (teks panjang setelah 'service install')"
$token = ($token -replace '^.*service install\s+', '').Trim()
cloudflared service install $token
if ($LASTEXITCODE -ne 0) { Write-Host "Bila tunnel sudah pernah dipasang, abaikan pesan di atas." -ForegroundColor Yellow }

Step "3/4 Isi Service ID ke Worker"
$serviceId = (Read-Host "Tempel Service ID VPC").Trim()
# Disimpan di file lokal (di-gitignore) agar update.bat tidak bentrok dengan file asli.
$worker = Join-Path $PWD.Path "deploy\cloudflare-worker"
$config = (Get-Content (Join-Path $worker "wrangler.jsonc") -Raw) -replace '"service_id":\s*"[^"]*"', "`"service_id`": `"$serviceId`""
[System.IO.File]::WriteAllText((Join-Path $worker "wrangler.local.jsonc"), $config)

Step "4/4 Login Cloudflare di browser & pasang Worker"
Set-Location "deploy\cloudflare-worker"
npx --yes wrangler login
npx --yes wrangler deploy --config wrangler.local.jsonc
if ($LASTEXITCODE -ne 0) {
    Write-Host "Deploy Worker gagal. Kirim pesan error di atas ke developer." -ForegroundColor Red
} else {
    Write-Host "`nSelesai. Buka https://kabanjawara.anzero.workers.dev (aplikasi di PC harus sedang berjalan)." -ForegroundColor Green
}
Read-Host "Tekan Enter untuk menutup"
