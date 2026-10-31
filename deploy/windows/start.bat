@echo off
rem Jalankan aplikasi di http://127.0.0.1:8000 (dipakai juga oleh Cloudflare Tunnel).
cd /d "%~dp0\..\.."
php artisan serve --host=0.0.0.0 --port=8000
