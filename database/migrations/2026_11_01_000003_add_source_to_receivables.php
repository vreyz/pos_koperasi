<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sumber piutang, mis. "potong_gaji": utang belanja/kantin pegawai yang dilunasi dari Potong Gaji Koperasi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
