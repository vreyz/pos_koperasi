<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SHU: porsi pembiayaan dana & barang digabung jadi satu "Pendapatan Pembiayaan"
 * (dibagi sebanding biaya formulir + bagi hasil + biaya lain per pegawai).
 * Kolom lama tetap ada (migrasi aditif); nilainya dipindah ke kolom gabungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shu_periods', function (Blueprint $table) {
            $table->decimal('portion_financing', 5, 2)->default(0)->after('portion_financing_barang');
        });
        DB::table('shu_periods')->update([
            'portion_financing' => DB::raw('portion_financing_dana + portion_financing_barang'),
            'portion_financing_dana' => 0,
            'portion_financing_barang' => 0,
        ]);

        Schema::table('shu_allocations', function (Blueprint $table) {
            $table->bigInteger('financing_amount')->default(0)->after('financing_barang_amount');
            $table->decimal('financing_share', 12, 10)->default(0)->after('financing_barang_share');
            $table->decimal('shu_financing', 15, 2)->default(0)->after('shu_financing_barang');
        });
        DB::table('shu_allocations')->update([
            'financing_amount' => DB::raw('financing_dana_amount + financing_barang_amount'),
            'shu_financing' => DB::raw('shu_financing_dana + shu_financing_barang'),
        ]);
        foreach (DB::table('shu_allocations')->groupBy('shu_period_id')->selectRaw('shu_period_id, SUM(financing_amount) as total')->get() as $period) {
            if ($period->total > 0) {
                DB::table('shu_allocations')->where('shu_period_id', $period->shu_period_id)
                    ->update(['financing_share' => DB::raw('financing_amount / '.(int) $period->total)]);
            }
        }
    }

    public function down(): void
    {
        DB::table('shu_periods')->update(['portion_financing_dana' => DB::raw('portion_financing')]);
        Schema::table('shu_allocations', fn (Blueprint $table) => $table->dropColumn(['financing_amount', 'financing_share', 'shu_financing']));
        Schema::table('shu_periods', fn (Blueprint $table) => $table->dropColumn('portion_financing'));
    }
};
