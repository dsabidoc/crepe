<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['finance.view', 'finance.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('Administrador', 'web')->givePermissionTo(['finance.view', 'finance.manage']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $administrator = Role::findByName('Administrador', 'web');
        $administrator->revokePermissionTo(['finance.view', 'finance.manage']);
        Permission::query()->whereIn('name', ['finance.view', 'finance.manage'])->where('guard_name', 'web')->delete();
    }
};
