<?php

use App\Services\Accounting\DefaultChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jurnal otomatis: penanda sumber (auto_key) dan sidik isi (fingerprint) supaya jurnal bisa
 * diperbarui saat data asalnya berubah. Sekaligus: biaya formulir pembiayaan diberi nama
 * "Pendapatan ..." (memang akun pendapatan) dan akun baru yang dibutuhkan jurnal otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('auto_key', 80)->nullable()->after('event_code');
            $table->string('fingerprint', 40)->nullable()->after('auto_key');
            $table->index('auto_key');
        });

        $renames = [
            '46101' => ['Biaya Administrasi Pembiayaan Dana', 'Pendapatan Biaya Formulir Pembiayaan Dana'],
            '46103' => ['Biaya Administrasi Pembiayaan Barang', 'Pendapatan Biaya Formulir Pembiayaan Barang'],
        ];
        foreach ($renames as $code => [$old, $new]) {
            DB::table('accounts')->where('code', $code)->where('name', $old)->update(['name' => $new]);
        }
        DB::table('accounts')->whereIn('code', ['46101', '46103'])->where('report_line', 'Biaya administrasi pembiayaan')
            ->update(['report_line' => 'Pendapatan biaya formulir pembiayaan', 'description' => 'Dibayar anggota di depan, tidak memotong pencairan']);

        DefaultChart::install();
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['auto_key']);
            $table->dropColumn(['auto_key', 'fingerprint']);
        });
    }
};
