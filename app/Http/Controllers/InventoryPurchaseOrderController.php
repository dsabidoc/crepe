<?php

namespace App\Http\Controllers;

use App\Services\InventoryFilter;
use App\Services\InventoryReplenishmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InventoryPurchaseOrderController extends Controller
{
    public function store(Request $request, InventoryReplenishmentService $replenishment): RedirectResponse
    {
        abort_unless($request->session()->get('crepe.mode') === 'almacen' && $request->user()->can('mode.almacen.access'), 403);
        $filters = InventoryFilter::read($request);
        $data = $request->validate(['key' => ['required', 'uuid']]);
        $result = $replenishment->send($filters, $request->user(), $data['key']);
        $destination = count($result['orders']) === 1
            ? redirect()->route('purchase-orders.show', $result['orders'][0]['id'])
            : redirect()->route('purchase-orders.index', ['status' => 'draft']);

        return $destination->with('inventory_replenishment', $result)->with('success', 'Revisión de reabasto terminada. Puedes modificar los borradores en Órdenes de compra.');
    }
}
