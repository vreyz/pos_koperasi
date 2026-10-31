@echo off
rem Nyalakan POS (database, web, PHP, queue, scheduler, tunnel) lewat runtime lokal di ..\.local-pos.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0..\..\..\.local-pos\start.ps1"
if errorlevel 1 pause
