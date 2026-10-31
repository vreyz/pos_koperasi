<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Izin menu Pembiayaan Dana & Barang untuk instalasi yang sudah berjalan; langsung
 * diberikan ke super-admin & manager. Peran lain (divisi pembiayaan, kepala unit usaha,
 * ketua, bendahara) diatur lewat menu Role.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'financing-access', 'financing-create', 'financing-check', 'financing-review',
        'financing-approve', 'financing-disburse', 'financing-import',
    ];

    public function up(): void
    {
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
        Permission::whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
