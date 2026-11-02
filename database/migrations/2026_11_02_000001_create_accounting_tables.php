<?php

use App\Services\Accounting\DefaultChart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Buku besar koperasi: bagan akun (COA), jurnal, dan baris jurnal. Setiap baris jurnal boleh
 * menyimpan pegawai (customer_id) supaya jurnal gabungan (mis. rekap potongan gaji) tetap
 * bisa ditelusuri per pegawai.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['accounting-access', 'accounting-create', 'accounting-manage'];

    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('group', 20);
            $table->char('normal_balance', 1);
            $table->string('report_line')->nullable();
            $table->string('division', 20)->nullable();
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->string('division', 20)->nullable();
            $table->string('event_code', 10)->nullable();
            $table->string('description');
            $table->nullableMorphs('source');
            $table->boolean('is_opening')->default(false);
            // Tanpa foreign key ke tabel sendiri (alat reset data demo tidak bisa mengurutkannya).
            $table->unsignedBigInteger('reversal_of_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['date', 'id']);
            $table->index('event_code');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('memo')->nullable();

            $table->index(['account_id', 'journal_entry_id']);
            $table->index('customer_id');
        });

        DefaultChart::install();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['super-admin', 'manager'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSIONS);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
