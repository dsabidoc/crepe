<?php

namespace App\Http\Controllers;

use App\Models\ColorBarDepletionBatch;
use App\Models\InventoryBalance;
use App\Services\ColorBarDepletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ColorBarDepletionController extends Controller
{
    public function create(): View
    {
        $balances = InventoryBalance::query()->with(['variant.product.preferredSupplier'])
            ->whereHas('location', fn ($locations) => $locations->where('code', 'CB'))
            ->whereHas('variant.product', fn ($products) => $products->where('status', 'active')->colorBarSupplies())
            ->where(fn ($query) => $query->where('available_quantity', '>', 0)
                ->orWhereIn('id', collect(old('items', []))->pluck('balance_id')))
            ->orderBy('id')->get()
            ->sortBy(fn (InventoryBalance $balance): string => $balance->variant->product->name.' '.$balance->variant->name)->values();

        return view('inventory.color-bar-depletions', [
            'balances' => $balances,
            'requestKey' => (string) Str::uuid(),
            'recentBatches' => ColorBarDepletionBatch::query()->with(['creator', 'movements.variant.product', 'inventoryRequest.items.variant.product'])->latest('id')->limit(10)->get(),
        ]);
    }

    public function store(Request $request, ColorBarDepletionService $depletions): RedirectResponse
    {
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:180'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.balance_id' => ['required', 'integer', 'distinct'],
            'items.*.expected_quantity' => ['required', 'numeric', 'min:0'],
            'items.*.units' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.requested_units' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'items.*.empty_stock' => ['nullable', 'boolean'],
        ], [
            'items.required' => 'Selecciona al menos un insumo para dar de baja.',
            'items.min' => 'Selecciona al menos un insumo para dar de baja.',
            'items.max' => 'Puedes registrar hasta 100 insumos en un lote.',
            'items.*.balance_id.distinct' => 'Cada insumo debe aparecer una sola vez en el lote.',
            'items.*.requested_units.integer' => 'Captura una cantidad entera de envases a solicitar.',
            'items.*.requested_units.min' => 'La cantidad a solicitar no puede ser negativa.',
            'items.*.units.integer' => 'Captura una cantidad entera de envases.',
            'items.*.units.min' => 'La cantidad de envases no puede ser negativa.',
        ]);
        $batch = $depletions->record($data, $request->user());

        return redirect()->route('inventory.color-bar.depletions.create')
            ->with('success', 'Baja #'.$batch->id.' registrada.'.($batch->inventoryRequest ? ' Solicitud '.$batch->inventoryRequest->code.' enviada a Almacén.' : ' Las fórmulas siguen disponibles.'));
    }
}
