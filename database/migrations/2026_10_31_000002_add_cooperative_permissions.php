<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Instalasi yang sudah berjalan tidak menjalankan ulang seeder, jadi izin modul
 * koperasi dibuat di sini dan langsung diberikan ke super-admin & manager.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'employees-access', 'employees-create', 'employees-edit', 'employees-delete', 'employees-import',
        'savings-access', 'savings-create', 'savings-delete', 'savings-import',
        'shu-access', 'shu-manage', 'shu-finalize',
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
