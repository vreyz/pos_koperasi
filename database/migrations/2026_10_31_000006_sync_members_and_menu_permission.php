<?php

use App\Services\Cooperative\MemberSync;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data yang sudah ada: pembayar simpanan jadi anggota, pembeli di data penjualan jadi customer.
 * Plus izin "menu-settings" (Pengaturan → Tampilan Menu) untuk super-admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'menu-settings', 'guard_name' => 'web']);
        Role::where('name', 'super-admin')->where('guard_name', 'web')->first()?->givePermissionTo('menu-settings');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(MemberSync::class)->run();
    }

    public function down(): void
    {
        Permission::where('name', 'menu-settings')->delete();
    }
};
