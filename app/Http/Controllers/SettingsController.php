<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\JobPosition;
use App\Models\ProductCommissionRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.index', [
            'openingFloat' => (float) AppSetting::value('cash.opening_float', 1250),
            'jobPositions' => JobPosition::query()->active()->orderBy('sort_order')->orderBy('name')->get(),
            'productCommissionRules' => ProductCommissionRule::query()->where('is_active', true)->orderBy('minimum_sales')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['opening_float' => ['required', 'numeric', 'min:0', 'max:1000000']]);
        AppSetting::put('cash.opening_float', number_format((float) $data['opening_float'], 2, '.', ''));

        return back()->with('success', 'Configuración de cajas actualizada.');
    }

    public function storeJobPosition(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/\\S/', Rule::unique('job_positions', 'name')],
        ]);

        JobPosition::query()->create([
            'name' => trim($data['name']),
            'is_active' => true,
            'sort_order' => (int) JobPosition::query()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Puesto agregado al catálogo.');
    }

    public function storeProductCommissionRule(Request $request): RedirectResponse
    {
        $data = $request->validate(['minimum_sales' => ['required', 'integer', 'min:0'], 'maximum_sales' => ['nullable', 'integer', 'gte:minimum_sales'], 'commission_rate' => ['required', 'numeric', 'between:0,100'], 'positions' => ['required', 'array', 'min:1'], 'positions.*' => [Rule::exists('job_positions', 'name')->where('is_active', true)]]);
        ProductCommissionRule::query()->create([...$data, 'is_active' => true]);

        return back()->with('success', 'Rango de comisión por productos agregado.');
    }
}
