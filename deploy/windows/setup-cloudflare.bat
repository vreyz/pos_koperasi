@echo off
rem Klik dua kali; Windows akan meminta izin Administrator (dibutuhkan untuk memasang tunnel).
powershell -NoProfile -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile -ExecutionPolicy Bypass -File \"%~dp0setup-cloudflare.ps1\"'"
