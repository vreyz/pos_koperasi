@echo off
rem Klik dua kali untuk mengganti token tunnel Cloudflare dan menyalakan ulang tunnel.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-cloudflare.ps1"
