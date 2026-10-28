<?php

namespace App\Http\Controllers;

use App\Services\WarehouseEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryInlineController extends Controller
{
    public function store(Request $request, WarehouseEditService $editor): JsonResponse
    {
        abort_unless($request->session()->get('crepe.mode') === 'almacen' && $request->user()->can('mode.almacen.access'), 403);
        $data = $request->validate([
            'key' => ['required', 'uuid'], 'reason' => ['nullable', 'string', 'max:255'],
            'changes' => ['required', 'array', 'min:1', 'max:300'],
            'changes.*' => ['required', 'array:variant_id,field,location_code,before,value'],
            'changes.*.variant_id' => ['required', 'integer'],
            'changes.*.field' => ['required', Rule::in(['quantity', 'cost', 'sale_price', 'minimum_stock', 'maximum_stock'])],
            'changes.*.location_code' => ['nullable', Rule::in(['ALM', 'REC', 'CB'])],
            'changes.*.before' => ['present', 'nullable', 'numeric'],
            'changes.*.value' => ['present', 'nullable', 'numeric'],
        ]);
        $editor->save($data['changes'], $request->user(), $data['key'], $data['reason'] ?? null);

        return response()->json(['message' => 'Cambios guardados y registrados en el historial.']);
    }
}
