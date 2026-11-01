<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Semua pembiayaan bawaan 12 bulan: jangka waktu dana yang masih bernilai lama (10)
 * diubah ke 12. Pengaturan yang sudah diubah ke nilai lain dibiarkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Setting::get('financing_tenor_dana_max') === '10') {
            Setting::set('financing_tenor_dana_max', '12');
        }
    }

    public function down(): void {}
};
