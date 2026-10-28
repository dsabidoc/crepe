<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Services\InventoryLimitService;
use App\Services\InventoryWorkflowService;
use App\Services\ProductSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryWorkflowController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $this->ensureWarehouse($request);

        return redirect()->route('inventory.index', $request->only(['search', 'brand', 'category', 'low_stock', 'page']) + ['location' => 'ALM']);
    }

    public function warehouse(Request $request): View
    {
        $this->ensureWarehouse($request);
        $search = trim($request->string('search')->toString());
        $brand = $request->string('brand')->toString();
        $categoryId = $request->integer('category');
        $lowStock = $request->boolean('low_stock');
        $locations = InventoryLocation::query()->whereIn('code', ['ALM', 'REC', 'CB'])->get()->keyBy('code');
        $warehouseId = $locations->get('ALM')?->id;
        $variants = ProductVariant::query()
            ->with(['product.preferredSupplier', 'balances' => fn ($query) => $query->whereIn('inventory_location_id', $locations->pluck('id'))])
            ->whereHas('product', fn ($query) => $query->where('status', 'active')
                ->when($brand !== '', fn ($query) => $query->where('brand', $brand))
                ->when($categoryId, fn ($query) => $query->where('product_category_id', $categoryId)))
            ->when($search, fn ($query) => ProductSearch::variants($query, $search))
            ->when($lowStock, fn ($query) => $query->whereHas('balances', fn ($balances) => $balances->where('inventory_location_id', $warehouseId)
                ->whereRaw('inventory_balances.available_quantity <= CASE WHEN inventory_balances.limits_configured = 1 THEN inventory_balances.minimum_stock ELSE product_variants.minimum_stock END')))
            ->orderBy(Product::query()->select('name')->whereColumn('products.id', 'product_variants.product_id'))
            ->orderBy('id')->paginate(30)->withQueryString();
        $brands = Product::query()->where('status', 'active')->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand');
        $categories = ProductCategory::query()->orderBy('name')->get();

        return view('inventory.matrix', compact('variants', 'locations', 'search', 'brand', 'brands', 'categoryId', 'categories', 'lowStock'));
    }

    private function ensureWarehouse(Request $request): void
    {
        abort_unless($request->session()->get('crepe.mode') === 'almacen' && $request->user()->can('mode.almacen.access'), 403, 'La vista de las tres ubicaciones está disponible dentro del módulo Almacén.');
    }

    public function review(Request $request): View
    {
        abort_unless($request->user()->hasRole('Administrador'), 403);
        $this->ensureWarehouse($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255'], 'items' => ['required', 'array', 'max:150'], 'items.*.id' => ['required', 'integer', 'distinct'], 'items.*.quantity' => ['required', 'integer', 'min:0', 'max:999999999'], 'items.*.before' => ['required', 'numeric'], 'items.*.minimum_stock' => ['required', 'integer', 'min:0', 'max:999999999'], 'items.*.maximum_stock' => ['nullable', 'integer', 'min:0', 'max:999999999'], 'items.*.limits_control' => ['required', 'string']]);
        $balances = InventoryBalance::query()->with(['variant.product', 'location'])->whereIn('id', array_column($data['items'], 'id'))->get()->keyBy('id');
        $changes = [];
        foreach ($data['items'] as $item) {
            $balance = $balances->get($item['id']);
            abort_unless($balance && in_array($balance->location->code, ['ALM', 'REC', 'CB'], true), 422);
            $limits = InventoryLimitService::values($balance, $balance->variant);
            $control = hash_hmac('sha256', json_encode([$balance->id, $limits], JSON_THROW_ON_ERROR), config('app.key'));
            if (! hash_equals($control, $item['limits_control']) || (float) $item['before'] !== (float) $balance->available_quantity) {
                throw ValidationException::withMessages(['items' => 'Los datos cambiaron. Actualiza la pantalla y revisa de nuevo.']);
            }
            $max = $item['maximum_stock'] ?? null;
            $limitsChanged = (float) $item['minimum_stock'] !== (float) $limits['minimum_stock'] || ($max === null) !== ($limits['maximum_stock'] === null) || ($max !== null && (float) $max !== (float) $limits['maximum_stock']);
            if ($limitsChanged && $balance->location->code !== 'ALM') {
                throw ValidationException::withMessages(['items' => 'Los mínimos y máximos sólo se modifican en Almacén.']);
            }
            if ($limitsChanged && $max !== null && $max < $item['minimum_stock']) {
                throw ValidationException::withMessages(['items' => 'El máximo debe ser mayor o igual al mínimo.']);
            }
            if ((float) $item['quantity'] === (float) $balance->available_quantity && ! $limitsChanged) {
                continue;
            }
            $changes[] = ['id' => $balance->id, 'before' => $balance->available_quantity, 'after' => (int) $item['quantity'], 'minimum_stock' => (int) $item['minimum_stock'], 'maximum_stock' => $max === null ? null : (int) $max, 'limits_before' => $limits, 'name' => $balance->variant->product->name, 'location' => $balance->location->name];
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['items' => 'No hay cambios por aplicar.']);
        }
        $key = (string) str()->uuid();
        $request->session()->put('inventory.workflow.'.$key, ['changes' => $changes, 'reason' => $data['reason']]);

        return view('inventory.adjustment-review', ['changes' => $changes, 'key' => $key, 'reason' => $data['reason']]);
    }

    public function store(Request $request, InventoryWorkflowService $workflow): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Administrador'), 403);
        $this->ensureWarehouse($request);
        $data = $request->validate(['key' => ['required', 'uuid']]);
        $review = $request->session()->get('inventory.workflow.'.$data['key']);
        abort_unless(is_array($review), 419, 'La revisión venció. Revisa nuevamente.');
        $workflow->adjust($review['changes'], $request->user(), $review['reason'], $data['key']);

        return redirect()->route('inventory.index', ['location' => 'ALM'])->with('success', 'Ajustes aplicados una sola vez y registrados en la bitácora.');
    }

    public function reverse(Request $request, InventoryRequest $inventoryRequest, InventoryWorkflowService $workflow): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Administrador'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255'], 'key' => ['required', 'uuid']]);
        $workflow->reverse($inventoryRequest, $request->user(), $data['reason'], $data['key']);

        return back()->with('success', 'Entrega revertida. Se conservaron los movimientos originales y se registró la devolución a Almacén.');
    }
}
