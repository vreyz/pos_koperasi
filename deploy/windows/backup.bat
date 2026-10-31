@echo off
rem Backup database ke folder backups\ (dijadwalkan harian 21:00 oleh install.bat).
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\..\.local-pos\backup.ps1"
if errorlevel 1 pause
