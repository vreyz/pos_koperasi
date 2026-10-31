@echo off
rem Update aplikasi dari GitHub tanpa menyentuh .env, file upload, dan isi database.
rem Database selalu di-backup dulu ke folder backups\.
setlocal
cd /d "%~dp0\..\.."

for /f "tokens=1,* delims==" %%a in ('findstr /b "DB_DATABASE= DB_USERNAME= DB_PASSWORD=" .env') do set "%%a=%%b"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmm"') do set STAMP=%%i
if not exist backups mkdir backups

echo == 1/5 Backup database %DB_DATABASE% ==
mysqldump -u %DB_USERNAME% --password=%DB_PASSWORD% %DB_DATABASE% > "backups\%DB_DATABASE%-%STAMP%.sql" || goto :fail

echo == 2/5 Ambil update dari GitHub ==
git pull --ff-only origin main || goto :fail

echo == 3/5 Paket PHP ==
call composer install --no-dev --optimize-autoloader --no-interaction || goto :fail

echo == 4/5 Tampilan (build) ==
set PUPPETEER_SKIP_DOWNLOAD=true
call npm ci --no-audit --no-fund || goto :fail
call npm run build || goto :fail

echo == 5/5 Struktur database (migrasi, data lama tetap) ==
php artisan migrate --force || goto :fail
php artisan optimize:clear

echo.
echo Update selesai. Backup: backups\%DB_DATABASE%-%STAMP%.sql
pause
exit /b 0

:fail
echo.
echo UPDATE GAGAL. Aplikasi/database tidak diubah lebih jauh; backup ada di folder backups\.
echo Kirim pesan error di atas ke developer.
pause
exit /b 1
