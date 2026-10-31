<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jasa pengurus: tambahan SHU untuk pengurus koperasi, sebanding bulan bertugas.
        Schema::table('shu_periods', function (Blueprint $table) {
            $table->bigInteger('board_amount')->default(0)->after('base_mode');
            $table->json('board_members')->nullable()->after('board_amount');
        });
        Schema::table('shu_allocations', function (Blueprint $table) {
            $table->bigInteger('board_amount')->default(0)->after('base_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shu_allocations', fn (Blueprint $table) => $table->dropColumn('board_amount'));
        Schema::table('shu_periods', fn (Blueprint $table) => $table->dropColumn(['board_amount', 'board_members']));
    }
};
