<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Grant reception access to its own inventory location.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate('inventory.view', 'web');
        Role::findOrCreate('Recepción', 'web')->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Revoke the additional permission when rolling the migration back.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findByName('Recepción', 'web');
        $role->revokePermissionTo('inventory.view');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
