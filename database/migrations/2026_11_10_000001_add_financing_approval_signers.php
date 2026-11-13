<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persetujuan pembiayaan bisa dicatat atas nama atasan yang tidak login:
 * *_signer = nama pejabat yang menyetujui/menolak, *_at = tanggal persetujuan (bisa diisi mundur),
 * *_by = akun yang mencatat, *_recorded_at = kapan dicatat di aplikasi.
 */
return new class extends Migration
{
    private const STEPS = ['checked', 'reviewed', 'approved', 'rejected'];

    public function up(): void
    {
        Schema::table('financings', function (Blueprint $table) {
            foreach (self::STEPS as $step) {
                if (! Schema::hasColumn('financings', "{$step}_signer")) {
                    $table->string("{$step}_signer", 120)->nullable();
                }
                if (! Schema::hasColumn('financings', "{$step}_recorded_at")) {
                    $table->timestamp("{$step}_recorded_at")->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('financings', function (Blueprint $table) {
            foreach (self::STEPS as $step) {
                $table->dropColumn(["{$step}_signer", "{$step}_recorded_at"]);
            }
        });
    }
};
