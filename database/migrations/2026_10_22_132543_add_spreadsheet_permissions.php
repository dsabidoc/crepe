<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $names = ['products.export', 'products.import', 'products.costs.view', 'products.prices.manage', 'inventory.export', 'inventory.import', 'imports.view'];
        $roles = DB::table('roles')->whereIn('name', ['Administrador', 'Almacén'])->pluck('id')
            ->merge(DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', 'mode.almacen.access')->pluck('role_id'))->unique();
        foreach ($names as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');
            foreach ($roles as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['products.export', 'products.import', 'products.costs.view', 'products.prices.manage', 'inventory.export', 'inventory.import', 'imports.view'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
