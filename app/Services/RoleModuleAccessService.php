<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RoleModuleAccessService
{
    private const SETTING_PREFIX = 'access.role.';

    private const RECEPTION_ACCESS = [
        'mode.color-bar.access', 'mode.almacen.access', 'products.manage',
        'inventory.view', 'inventory.move', 'inventory.requests.manage',
        'tickets.view', 'tickets.update',
    ];

    /** @var array<string, array<int, string>>|null */
    private ?array $configuredModules = null;

    /** @return array<string, array{title: string, description: string, icon: string, permission: string, permissions: array<int, string>}> */
    public function modules(): array
    {
        return [
            'recepcion' => [
                'title' => 'Recepción', 'description' => 'Agenda, tickets, clientas, cobros e inventario de Recepción.', 'icon' => '◇', 'permission' => 'mode.reception.access',
                'permissions' => ['mode.reception.access', 'appointments.view', 'appointments.create', 'customers.view', 'customers.create', 'tickets.view', 'tickets.update', 'tickets.charge', 'promotions.apply', 'inventory.view', 'cash.open', 'cash.close'],
            ],
            'color-bar' => [
                'title' => 'Color Bar', 'description' => 'Fórmulas, historial, inventario y solicitudes de insumos.', 'icon' => '✦', 'permission' => 'mode.color-bar.access',
                'permissions' => ['mode.color-bar.access', 'tickets.view', 'tickets.update', 'inventory.view'],
            ],
            'almacen' => [
                'title' => 'Almacén', 'description' => 'Productos, existencias, solicitudes y órdenes de compra.', 'icon' => '▦', 'permission' => 'mode.almacen.access',
                'permissions' => ['mode.almacen.access', 'products.manage', 'inventory.view', 'inventory.move', 'inventory.requests.manage'],
            ],
            'finanzas' => [
                'title' => 'Finanzas', 'description' => 'Ingresos, gastos, cuentas, pagos a proveedores y nómina.', 'icon' => '$', 'permission' => 'finance.view',
                'permissions' => ['finance.view', 'finance.manage', 'cash.open'],
            ],
            'promos' => [
                'title' => 'Promos', 'description' => 'Crear y administrar promociones para los tickets.', 'icon' => '%', 'permission' => 'promotions.manage',
                'permissions' => ['promotions.manage', 'promotions.apply', 'tickets.view'],
            ],
            'checks' => [
                'title' => 'Checks', 'description' => 'Entradas, salidas y seguimiento de incidencias del equipo.', 'icon' => '✓', 'permission' => 'mode.checks.access',
                'permissions' => ['mode.checks.access', 'attendance.register', 'attendance.manage'],
            ],
        ];
    }

    public function isProtected(Role $role): bool
    {
        return $role->name === 'Administrador';
    }

    /** @return array<int, string> */
    public function modulesForRole(Role $role): array
    {
        if ($this->isProtected($role)) {
            return array_keys($this->modules());
        }
        $configured = $this->settings()[(string) $role->id] ?? null;
        if ($configured !== null) {
            return $configured;
        }

        return array_keys(array_filter($this->modules(), fn (array $module): bool => $this->legacyRoleAllows($role, $module['permission'])));
    }

    public function revision(Role $role): string
    {
        return hash('sha256', json_encode([$role->id, $this->modulesForRole($role)], JSON_THROW_ON_ERROR));
    }

    public function hasConfiguredRoles(User $user): bool
    {
        return $user->roles->contains(fn (Role $role): bool => array_key_exists((string) $role->id, $this->settings()));
    }

    public function decision(User $user, string $permission): ?bool
    {
        if ($permission === 'roles.manage') {
            return $user->hasRole('Administrador');
        }
        $permissions = array_unique(array_merge(
            ...array_column($this->modules(), 'permissions'),
        ));
        $permissions = [...$permissions, 'mode.administration.access', 'settings.manage', 'reports.view', 'cash.authorize'];
        if (! in_array($permission, $permissions, true)) {
            return null;
        }
        if ($user->hasRole('Administrador')) {
            return true;
        }
        $hasConfiguredRole = false;
        $allowed = false;
        foreach ($user->roles as $role) {
            $configured = $this->settings()[(string) $role->id] ?? null;
            if ($configured === null) {
                $allowed = $allowed || $this->legacyRoleAllows($role, $permission);

                continue;
            }
            $hasConfiguredRole = true;
            foreach ($configured as $module) {
                $allowed = $allowed || in_array($permission, $this->modules()[$module]['permissions'], true);
            }
        }
        if ($hasConfiguredRole) {
            return $allowed;
        }
        if (in_array($permission, ['promotions.manage', 'promotions.apply', 'mode.checks.access', 'attendance.manage', 'attendance.register'], true)) {
            $legacyPermission = match ($permission) {
                'promotions.manage' => 'settings.manage',
                'promotions.apply' => 'tickets.update',
                'mode.checks.access', 'attendance.manage' => 'mode.reception.access',
                default => null,
            };

            return $permission === 'attendance.register' || $allowed || $user->getAllPermissions()->contains('name', $legacyPermission);
        }

        return $allowed ? true : null;
    }

    public function settingKey(Role $role): string
    {
        return self::SETTING_PREFIX.$role->id;
    }

    public function forget(): void
    {
        $this->configuredModules = null;
    }

    private function legacyRoleAllows(Role $role, string $permission): bool
    {
        if ($role->permissions->contains('name', $permission)) {
            return true;
        }
        if ($role->name === 'Recepción' && in_array($permission, self::RECEPTION_ACCESS, true)) {
            return true;
        }

        return match ($permission) {
            'promotions.manage' => $role->permissions->contains('name', 'settings.manage'),
            'promotions.apply' => $role->permissions->contains('name', 'tickets.update'),
            'mode.checks.access', 'attendance.manage' => $role->permissions->contains('name', 'mode.reception.access'),
            'attendance.register' => true,
            default => false,
        };
    }

    /** @return array<string, array<int, string>> */
    private function settings(): array
    {
        if ($this->configuredModules === null) {
            $this->configuredModules = [];
            foreach (AppSetting::query()->where('key', 'like', self::SETTING_PREFIX.'%')->pluck('value', 'key') as $key => $value) {
                $modules = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                $this->configuredModules[substr($key, strlen(self::SETTING_PREFIX))] = array_values(array_intersect(array_keys($this->modules()), $modules));
            }
        }

        return $this->configuredModules;
    }
}
