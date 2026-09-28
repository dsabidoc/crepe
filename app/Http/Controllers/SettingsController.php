<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.index', ['openingFloat' => (float) AppSetting::value('cash.opening_float', 2500)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['opening_float' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        AppSetting::put('cash.opening_float', number_format((float) $data['opening_float'], 2, '.', ''));

        return back()->with('success', 'Configuración de cajas actualizada.');
    }
}
