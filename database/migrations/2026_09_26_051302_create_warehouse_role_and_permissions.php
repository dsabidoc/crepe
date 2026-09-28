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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            'mode.almacen.access',
            'products.manage',
            'inventory.requests.manage',
        ])->mapWithKeys(fn (string $name): array => [$name => Permission::findOrCreate($name, 'web')]);

        $warehouse = Role::findOrCreate('Almacén', 'web');
        $warehouse->syncPermissions([
            $permissions['mode.almacen.access'],
            $permissions['products.manage'],
            Permission::findOrCreate('inventory.view', 'web'),
            Permission::findOrCreate('inventory.move', 'web'),
            $permissions['inventory.requests.manage'],
        ]);

        $administrator = Role::findOrCreate('Administrador', 'web');
        $administrator->givePermissionTo($permissions->values()->all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->where('name', 'Almacén')->where('guard_name', 'web')->delete();

        Permission::query()
            ->whereIn('name', ['mode.almacen.access', 'products.manage', 'inventory.requests.manage'])
            ->where('guard_name', 'web')
            ->get()
            ->each(function (Permission $permission): void {
                if ($permission->roles()->count() === 0) {
                    $permission->delete();
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
