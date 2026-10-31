<?php

use App\Services\Cooperative\ShuCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SHU Pengurus Koperasi kini per orang per bulan bertugas (bukan per tahun),
 * dan bulan bertugas disimpan sebagai daftar bulan. Nilai lama dikonversi
 * supaya hasil perhitungan tetap sama: per tahun ÷ 12.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shu_periods')->orderBy('id')->get(['id', 'board_amount', 'board_members'])->each(function ($period) {
            $members = json_decode($period->board_members ?? '[]', true) ?: [];
            DB::table('shu_periods')->where('id', $period->id)->update([
                'board_amount' => (int) round(((int) $period->board_amount) / 12),
                'board_members' => json_encode(array_map(fn ($m) => [
                    'customer_id' => (int) ($m['customer_id'] ?? 0),
                    'months' => ShuCalculator::boardMonths($m),
                ], $members)),
            ]);
        });
    }

    public function down(): void
    {
        // Tidak dikembalikan: data tetap valid (jumlah bulan terbaca dari daftar bulan).
    }
};
