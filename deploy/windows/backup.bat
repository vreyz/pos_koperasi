@echo off
rem Backup database ke folder backups\ (jadwalkan harian lewat Task Scheduler).
setlocal
cd /d "%~dp0\..\.."
for /f "tokens=1,* delims==" %%a in ('findstr /b "DB_DATABASE= DB_USERNAME= DB_PASSWORD=" .env') do set "%%a=%%b"
for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmm"') do set STAMP=%%i
if not exist backups mkdir backups
mysqldump -u %DB_USERNAME% --password=%DB_PASSWORD% %DB_DATABASE% > "backups\%DB_DATABASE%-%STAMP%.sql" && echo Backup: backups\%DB_DATABASE%-%STAMP%.sql
