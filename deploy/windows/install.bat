@echo off
rem Klik dua kali untuk memasang / memperbaiki instalasi POS Koperasi.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
pause
