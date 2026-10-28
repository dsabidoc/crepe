<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\InventoryRequestItem;
use App\Models\ProductVariant;
use App\Services\InventoryRequestPdfService;
use App\Services\InventoryRequestService;
use App\Services\InventoryService;
use App\Services\WorkflowNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryRequestController extends Controller
{
    public function index(Request $request): View
    {
        $source = $this->sourceLocation($request, false);
        $search = trim((string) $request->string('search'));
        $requestSource = $request->string('source')->toString();
        $query = InventoryRequest::query()->with(['sourceLocation', 'requester', 'items.variant.product'])
            ->when(! $request->boolean('history'), fn ($query) => $query->where('status', 'pending'))
            ->when($search, fn ($query) => $query->where(fn ($searchQuery) => $searchQuery
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('requester', fn ($requesters) => $requesters->where('name', 'like', "%{$search}%"))))
            ->when(in_array($requestSource, ['REC', 'CB'], true), fn ($query) => $query->whereHas('sourceLocation', fn ($locations) => $locations->where('code', $requestSource)))
            ->latest();

        if ($source !== null) {
            $query->where('source_location_id', $source->id);
        }

        return view('inventory.requests.index', [
            'requests' => $query->get(),
            'isWarehouse' => $source === null,
            'requestSearch' => $search,
            'requestSource' => $requestSource,
            'source' => $source,
        ]);
    }

    public function create(Request $request): View
    {
        $source = $this->sourceLocation($request);
        $variants = ProductVariant::query()->with(['product.preferredSupplier', 'balances.location'])
            ->whereHas('product', fn ($products) => $products
                ->where('status', 'active')
                ->when($source->code === 'CB', fn ($products) => $products->colorBarSupplies(), fn ($products) => $products->where('is_reception_usable', true)))
            ->orderBy('product_id')->orderBy('name')->get();

        $pendingUnits = InventoryRequestItem::query()->whereHas('request', fn ($query) => $query->where('source_location_id', $source->id)->where('status', 'pending'))->selectRaw('product_variant_id, SUM(COALESCE(requested_units, requested_quantity)) as units')->groupBy('product_variant_id')->pluck('units', 'product_variant_id');

        return view('inventory.requests.create', compact('source', 'variants', 'pendingUnits'));
    }

    public function store(Request $request, InventoryRequestService $requests): RedirectResponse
    {
        $source = $this->sourceLocation($request);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:600'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'items.*.requested_units' => ['nullable', 'integer', 'min:0'],
            'items.*.requested_quantity' => ['nullable', 'integer', 'min:0'],
        ]);
        $data['items'] = collect($data['items'])->map(function (array $item): array {
            $hasUnits = array_key_exists('requested_units', $item) && $item['requested_units'] !== null;

            return [
                'product_variant_id' => (int) $item['product_variant_id'],
                'requested_units' => $hasUnits ? (int) $item['requested_units'] : null,
                'requested_quantity' => $hasUnits ? null : (float) ($item['requested_quantity'] ?? 0),
            ];
        })->filter(fn (array $item): bool => $item['requested_units'] !== null ? $item['requested_units'] > 0 : $item['requested_quantity'] > 0)->values()->all();
        if ($data['items'] === []) {
            throw ValidationException::withMessages(['items' => 'Selecciona al menos un producto y una cantidad.']);
        }
        $variantIds = collect($data['items'])->pluck('product_variant_id')->map(fn ($id): int => (int) $id)->unique();
        $variants = ProductVariant::query()->with('product')->whereIn('id', $variantIds)->get()->keyBy('id');

        foreach ($variantIds as $variantId) {
            $variant = $variants->get($variantId);
            $isAvailableAtSource = $variant !== null && ($source->code === 'CB'
                ? $variant->product->isColorBarSupply()
                : $variant->product->is_reception_usable);
            if (! $isAvailableAtSource) {
                throw ValidationException::withMessages(['items' => 'Selecciona productos de la ubicación solicitante.']);
            }
        }

        $inventoryRequest = $requests->create($source, $request->user(), $variants, $data['items'], $data['notes'] ?? null);

        return redirect()->route('inventory.requests.index', ['location' => $source->code])->with('success', "Solicitud {$inventoryRequest->code} enviada a Almacén.");
    }

    public function edit(InventoryRequest $inventoryRequest): View
    {
        abort_unless($inventoryRequest->status === 'pending', 422, 'Esta solicitud ya fue cerrada.');

        return view('inventory.requests.process', [
            'inventoryRequest' => $inventoryRequest->load(['sourceLocation', 'requester', 'items.variant.product.preferredSupplier', 'items.variant.balances' => fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', 'ALM'))]),
        ]);
    }

    public function download(InventoryRequest $inventoryRequest, InventoryRequestPdfService $pdf): Response
    {
        return response($pdf->render($inventoryRequest), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$inventoryRequest->code.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function show(Request $request, InventoryRequest $inventoryRequest, WorkflowNotificationService $notifications): View
    {
        $inventoryRequest->load(['sourceLocation', 'requester', 'processor', 'items.variant.product', 'items.variant.balances.location', 'reversal']);
        abort_unless($request->user()->can('inventory.requests.manage') || (
            $notifications->canViewLocation($request->user(), $inventoryRequest->sourceLocation->code)
        ), 403);

        return view('inventory.requests.show', compact('inventoryRequest'));
    }

    public function close(Request $request, InventoryRequest $inventoryRequest, InventoryService $inventory, WorkflowNotificationService $notifications): RedirectResponse
    {
        abort_unless($inventoryRequest->status === 'pending', 422, 'Esta solicitud ya fue cerrada.');
        $data = $request->validate(['confirm_old_request' => $inventoryRequest->created_at->lt(now()->subDays(2)) ? ['required', 'accepted'] : ['sometimes', 'accepted'], 'items' => ['required', 'array'], 'items.*' => ['required', 'integer', 'min:0'], 'closure_notes' => ['nullable', 'string', 'max:600']]);
        $before = $inventoryRequest->load('items')->toArray();

        DB::transaction(function () use ($data, $request, $inventoryRequest, $inventory, $notifications): void {
            $lockedRequest = InventoryRequest::query()->with('items.variant')->lockForUpdate()->findOrFail($inventoryRequest->id);
            abort_unless($lockedRequest->status === 'pending', 422, 'Esta solicitud ya fue cerrada.');
            $warehouse = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();
            foreach ($lockedRequest->items as $item) {
                $requested = (float) ($item->requested_units ?? $item->requested_quantity);
                $entered = (int) ($data['items'][$item->id] ?? 0);
                if ($entered > $requested) {
                    throw ValidationException::withMessages(["items.{$item->id}" => 'No puedes entregar más de lo solicitado.']);
                }
                $stock = (float) InventoryBalance::query()->where('inventory_location_id', $warehouse->id)->where('product_variant_id', $item->product_variant_id)->value('available_quantity');
                $delivered = (int) min($entered, $requested, floor($stock));
                if ($delivered > 0) {
                    $inventory->move($item->product_variant_id, $warehouse->id, -$delivered, 'transfer_out', $request->user()->id, InventoryRequest::class, $lockedRequest->id, "Salida {$lockedRequest->code}");
                    $inventory->move($item->product_variant_id, $lockedRequest->source_location_id, $delivered, 'transfer_in', $request->user()->id, InventoryRequest::class, $lockedRequest->id, "Entrega {$lockedRequest->code}");
                }
                $item->update(['delivered_quantity' => $delivered, 'delivered_units' => $delivered]);
            }
            $lockedRequest->update(['status' => 'closed', 'processed_by' => $request->user()->id, 'processed_at' => now(), 'closure_notes' => $data['closure_notes'] ?? null]);
            $notifications->inventoryRequestClosed($lockedRequest);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'inventory.request.closed', 'subject_type' => InventoryRequest::class, 'subject_id' => $lockedRequest->id, 'before' => ['status' => 'pending'], 'after' => ['status' => 'closed', 'items' => $lockedRequest->items->map(fn ($item): array => ['item_id' => $item->id, 'requested' => (float) $item->requested_quantity, 'requested_units' => $item->requested_units, 'delivered' => (float) $item->delivered_quantity, 'delivered_units' => $item->delivered_units])->all()], 'reason' => $data['closure_notes'] ?? 'Solicitud cerrada por Almacén']);
        });

        return redirect()->route('inventory.requests.index')->with('success', "Solicitud {$inventoryRequest->code} cerrada y registrada en la bitácora.");
    }

    private function sourceLocation(Request $request, bool $required = true): ?InventoryLocation
    {
        $code = $request->string('location')->upper()->toString();
        $isAdministrator = $request->user()->can('settings.manage');
        $isWarehouse = $request->user()->can('inventory.requests.manage') && session('crepe.mode') === 'almacen';

        if ($code === '' && ! $isAdministrator && ! $isWarehouse) {
            $code = session('crepe.mode') === 'color-bar' ? 'CB' : 'REC';
        }
        if ($code === '' && ! $required) {
            return null;
        }
        if ($isWarehouse) {
            if (! $required) {
                return null;
            }

            abort(403, 'Almacén sólo puede procesar solicitudes existentes.');
        }
        abort_unless(in_array($code, ['REC', 'CB'], true), 422, 'Selecciona una ubicación solicitante válida.');
        abort_unless($isAdministrator || ($code === 'CB' && session('crepe.mode') === 'color-bar') || ($code === 'REC' && session('crepe.mode') === 'recepcion'), 403);

        return InventoryLocation::query()->where('code', $code)->firstOrFail();
    }
}
