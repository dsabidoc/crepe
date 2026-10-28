<?php

namespace App\Http\Middleware;

use App\Services\RoleModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureConfiguredModuleAccess
{
    public function __construct(private RoleModuleAccessService $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $mode = $request->session()->get('crepe.mode');
        if ($user !== null && in_array($mode, ['recepcion', 'color-bar'], true)) {
            if ($request->routeIs('inventory.*', 'activity.*')) {
                abort_unless($user->can($mode === 'recepcion' ? 'mode.reception.access' : 'mode.color-bar.access'), 403);
            }
            abort_if($request->routeIs('purchase-orders.*', 'suppliers.store', 'inventory.requests.edit', 'inventory.requests.close', 'inventory.requests.download', 'inventory.requests.reverse', 'inventory.import.*'), 403, 'Esta operación se realiza desde el módulo Almacén.');
            $location = $mode === 'recepcion' ? 'REC' : 'CB';
            if ($request->routeIs('inventory.export')) {
                $request->query->set('location_code', $location);
            }
        }
        if ($user !== null && $mode !== null && $request->routeIs('inventory.color-bar.*')) {
            abort_unless($mode === 'color-bar', 403, 'Esta operación se realiza desde Color Bar.');
        }
        if ($user === null || $user->hasRole('Administrador') || ! $this->access->hasConfiguredRoles($user)) {
            return $next($request);
        }
        if ($request->routeIs('inventory.index', 'activity.index', 'inventory.requests.index', 'inventory.requests.create', 'inventory.requests.store')) {
            $locations = collect(['REC' => 'mode.reception.access', 'CB' => 'mode.color-bar.access', 'ALM' => 'mode.almacen.access'])
                ->filter(fn (string $permission): bool => $user->can($permission));
            $location = $request->string('location')->upper()->toString();
            if ($location === '') {
                $location = match ($request->session()->get('crepe.mode')) {
                    'recepcion' => 'REC',
                    'color-bar' => 'CB',
                    'almacen' => 'ALM',
                    default => $locations->keys()->first() ?? '',
                };
            }
            abort_unless($locations->has($location), 403);
            if ($request->isMethod('GET')) {
                $request->query->set('location', $location);
            }
        }

        return $next($request);
    }
}
