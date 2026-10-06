<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModeController extends Controller
{
    private const MODES = [
        'administracion' => ['permission' => 'mode.administration.access', 'route' => 'workspace', 'title' => 'Administración'],
        'recepcion' => ['permission' => 'mode.reception.access', 'route' => 'workspace', 'title' => 'Recepción'],
        'color-bar' => ['permission' => 'mode.color-bar.access', 'route' => 'color-bar.index', 'title' => 'Color Bar'],
        'finanzas' => ['permission' => 'finance.view', 'route' => 'finance.index', 'title' => 'Finanzas'],
        'almacen' => ['permission' => 'mode.almacen.access', 'route' => 'workspace', 'title' => 'Almacén'],
        'promos' => ['permission' => 'settings.manage', 'route' => 'promotions.index', 'title' => 'Promos'],
        'configuracion' => ['permission' => 'settings.manage', 'route' => 'settings.edit', 'title' => 'Configuración'],
        'checks' => ['permission' => 'mode.reception.access', 'route' => 'attendance.index', 'title' => 'Checks'],
    ];

    public function index(Request $request): View
    {
        $modes = collect(self::MODES)
            ->filter(fn (array $mode) => $request->user()->can($mode['permission']))
            ->all();

        return view('modes.select', compact('modes'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:'.implode(',', array_keys(self::MODES))]]);
        $mode = self::MODES[$data['mode']];

        abort_unless($request->user()->can($mode['permission']), 403);
        $request->session()->put('crepe.mode', $data['mode']);

        return redirect()->route($mode['route'], $mode['route'] === 'workspace' ? ['mode' => $data['mode']] : []);
    }
}
