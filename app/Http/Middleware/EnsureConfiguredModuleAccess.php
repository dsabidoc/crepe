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
