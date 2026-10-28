<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductCategory;
use App\Services\InventoryService;
use App\Services\ProductSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function depleteColorBar(Request $request, InventoryBalance $balance, InventoryService $inventory): RedirectResponse
    {
        abort_unless($balance->location->code === 'CB', 404);
        $data = $request->validate([
            'empty_stock' => ['nullable', 'boolean'],
            'units' => ['required_unless:empty_stock,1', 'nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        DB::transaction(function () use ($data, $request, $balance, $inventory): void {
            $lockedBalance = InventoryBalance::query()->with('variant.product')->lockForUpdate()->findOrFail($balance->id);
            $variant = $lockedBalance->variant;
            abort_unless($variant->product->isColorBarSupply() && $variant->product->status === 'active', 422, 'Selecciona un insumo activo de Color Bar.');
            $emptyStock = (bool) ($data['empty_stock'] ?? false);
            $quantity = $emptyStock ? (float) $lockedBalance->available_quantity : (int) $data['units'];
            if ($quantity <= 0 || $quantity > (float) $lockedBalance->available_quantity) {
                throw ValidationException::withMessages(['units' => 'No hay suficientes envases registrados para esta baja.']);
            }

            $units = (int) ($data['units'] ?? 0);
            $reason = $emptyStock
                ? 'Existencia registrada agotada en Color Bar.'
                : $units.' '.($units === 1 ? 'envase agotado' : 'envases agotados').' en Color Bar.';
            if (! empty($data['notes'])) {
                $reason .= ' '.$data['notes'];
            }
            $inventory->move($variant->id, $lockedBalance->inventory_location_id, -$quantity, 'color_bar_depletion', $request->user()->id, InventoryBalance::class, $lockedBalance->id, $reason);
        });

        return redirect()->route('inventory.index', ['location' => 'CB'])->with('success', 'Baja de envases agotados registrada en el inventario de Color Bar.');
    }

    public function index(Request $request, InventoryWorkflowController $workflow): View
    {
        if ($request->session()->get('crepe.mode') === 'almacen') {
            return $workflow->warehouse($request);
        }
        $location = match ($request->session()->get('crepe.mode')) {
            'recepcion' => 'REC',
            'color-bar' => 'CB',
            default => $request->string('location')->toString(),
        };
        $search = trim((string) $request->string('search'));
        $categoryId = $request->integer('category');
        $lowStock = $location === 'ALM' && $request->boolean('low_stock');
        $locationModel = $location !== '' ? InventoryLocation::query()->where('code', $location)->first() : null;
        $balances = InventoryBalance::query()->with(['variant.product.preferredSupplier', 'location'])
            ->whereHas('variant.product', fn ($products) => $products->where('status', 'active'))
            ->when($location === 'REC', fn ($query) => $query->where(fn ($visible) => $visible->where('available_quantity', '!=', 0)->orWhereHas('variant.product', fn ($products) => $products->where('is_reception_usable', true))))
            ->when($location === 'CB', fn ($query) => $query->where(fn ($visible) => $visible->where('available_quantity', '>', 0)->orWhereHas('variant.product', fn ($products) => $products->colorBarSupplies())))
            ->when($location, fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', $location)))
            ->when($categoryId, fn ($query) => $query->whereHas('variant.product', fn ($products) => $products->where('product_category_id', $categoryId)))
            ->when($search, fn ($query) => $query->whereHas('variant', fn ($variants) => ProductSearch::variants($variants, $search)))
            ->when($lowStock, fn ($query) => $query->whereHas('variant', fn ($variants) => $variants->whereRaw('inventory_balances.available_quantity <= CASE WHEN inventory_balances.limits_configured = 1 THEN inventory_balances.minimum_stock ELSE product_variants.minimum_stock END')))
            ->orderBy('inventory_location_id')->orderBy('product_variant_id')->paginate(30)->withQueryString();

        return view('inventory.index', [
            'balances' => $balances,
            'categories' => ProductCategory::query()->orderBy('name')->get(),
            'categoryId' => $categoryId,
            'location' => $location,
            'locationModel' => $locationModel,
            'lowStock' => $lowStock,
            'search' => $search,
        ]);
    }
}
