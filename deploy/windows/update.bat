@echo off
rem Update aplikasi dari GitHub ke branch lokal tanpa menyentuh .env, file upload, dan isi database.
rem Database selalu di-backup dulu ke folder backups\.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\..\.local-pos\update.ps1"
if errorlevel 1 (echo. & echo UPDATE GAGAL. Backup ada di folder backups\. Kirim pesan error di atas ke developer.)
pause
