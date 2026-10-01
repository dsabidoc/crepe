<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModeAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) $request->route('mode');
        $permissions = [
            'administracion' => 'mode.administration.access',
            'recepcion' => 'mode.reception.access',
            'color-bar' => 'mode.color-bar.access',
            'almacen' => 'mode.almacen.access',
            'finanzas' => 'finance.view',
            'promos' => 'settings.manage',
            'configuracion' => 'settings.manage',
        ];

        abort_unless($request->user() && isset($permissions[$mode]) && $request->user()->can($permissions[$mode]), 403);

        $request->session()->put('crepe.mode', $mode);

        return $next($request);
    }
}
