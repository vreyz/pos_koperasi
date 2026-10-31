# Menyambungkan aplikasi lokal ke https://kabanjawara.anzero.workers.dev.
# Instalasi ini memakai tunnel 'kabanjawara-pos' yang dijalankan oleh ..\.local-pos\start.ps1
# (token di .local-pos\tunnel.token) dan Worker 'kabanjawara' dari folder ..\cloudflare.
# Jalankan lewat setup-cloudflare.bat bila token tunnel perlu diganti.

$ErrorActionPreference = "Stop"
$Dir = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$Root = Split-Path -Parent $Dir
$Runtime = Join-Path $Root ".local-pos"
$tokenFile = Join-Path $Runtime "tunnel.token"

function Step($text) { Write-Host "`n== $text ==" -ForegroundColor Cyan }

Step "1/3 Token tunnel"
if (Test-Path $tokenFile) {
    Write-Host "Token tunnel sudah ada di $tokenFile."
    $answer = Read-Host "Ganti token? (y/N)"
} else {
    $answer = "y"
}
if ($answer -match '^[yY]') {
    Write-Host "Ambil token di dashboard Cloudflare: Zero Trust > Networks > Tunnels > kabanjawara-pos > Configure."
    $token = Read-Host "Tempel token tunnel (teks panjang setelah 'service install')"
    $token = ($token -replace '^.*service install\s+', '').Trim()
    [System.IO.File]::WriteAllText($tokenFile, $token)
    Write-Host "Token disimpan."
}

Step "2/3 Nyalakan ulang tunnel"
& (Join-Path $Runtime "stop.ps1")
& (Join-Path $Runtime "start.ps1") -NoBrowser

Step "3/3 Worker"
Write-Host "Worker 'kabanjawara' sudah terpasang. Bila perlu dipasang ulang:"
Write-Host "  cd `"$Root\cloudflare`""
Write-Host "  npx wrangler login"
Write-Host "  npx wrangler deploy --config wrangler.json"
Write-Host "`nCek: buka https://kabanjawara.anzero.workers.dev (PC dan internet harus menyala)." -ForegroundColor Green
Read-Host "Tekan Enter untuk menutup"
