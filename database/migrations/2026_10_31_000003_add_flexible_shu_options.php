<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Masa aktif pegawai: syarat SHU = pegawai aktif di tahun buku.
        Schema::table('customers', function (Blueprint $table) {
            $table->date('active_from')->nullable()->after('employment_status');
            $table->date('active_until')->nullable()->after('active_from');
        });

        // Nama asli di file simpanan, supaya pencocokan bisa dikoreksi setelah import.
        Schema::table('member_contributions', function (Blueprint $table) {
            $table->string('source_name')->nullable()->after('source');
            $table->index('source_name');
        });

        Schema::table('shu_periods', function (Blueprint $table) {
            $table->boolean('base_requires_savings')->default(true)->after('base_per_person');
            $table->boolean('base_for_ppnpn')->default(true)->after('base_requires_savings');
            $table->string('base_mode', 20)->default('full')->after('base_for_ppnpn');
            $table->unsignedInteger('rounding_unit')->default(1000)->after('rounding');
        });

        // Koreksi manual per orang (base pengganti / tambahan, mis. jasa pengurus), tetap ada saat dihitung ulang.
        Schema::create('shu_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shu_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('base_override')->nullable();
            $table->bigInteger('amount')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['shu_period_id', 'customer_id']);
        });

        Schema::table('shu_allocations', function (Blueprint $table) {
            $table->unsignedTinyInteger('months_paid')->default(0)->after('loan_amount');
            $table->bigInteger('adjustment_amount')->default(0)->after('shu_loans');
            $table->string('adjustment_note')->nullable()->after('adjustment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shu_allocations', function (Blueprint $table) {
            $table->dropColumn(['months_paid', 'adjustment_amount', 'adjustment_note']);
        });
        Schema::dropIfExists('shu_adjustments');
        Schema::table('shu_periods', function (Blueprint $table) {
            $table->dropColumn(['base_requires_savings', 'base_for_ppnpn', 'base_mode', 'rounding_unit']);
        });
        Schema::table('member_contributions', function (Blueprint $table) {
            $table->dropIndex(['source_name']);
            $table->dropColumn('source_name');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['active_from', 'active_until']);
        });
    }
};
