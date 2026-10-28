<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\PayableInvoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\InventoryService;
use App\Services\PurchaseOrderPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150', 'unique:suppliers,name'], 'contact_name' => ['nullable', 'string', 'max:120'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'], 'payment_grace_days' => ['nullable', 'integer', 'min:0', 'max:365']]);
        Supplier::query()->create([...$data, 'payment_grace_days' => $data['payment_grace_days'] ?? 0]);

        return back()->with('success', 'Proveedor agregado al catálogo.');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $supplierId = $request->integer('supplier');
        $suppliers = Supplier::query()->where('is_active', true)->orderBy('name')->get();
        $orders = PurchaseOrder::query()
            ->with(['supplier', 'items', 'payableInvoice', 'receivedBy'])
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('payableInvoice', fn ($invoices) => $invoices->where('invoice_reference', 'like', "%{$search}%"))
                ->orWhereHas('supplier', fn ($suppliers) => $suppliers->where('name', 'like', "%{$search}%"))))
            ->when(in_array($status, ['draft', 'received'], true), fn ($query) => $query->where('status', $status))
            ->when($supplierId > 0, fn ($query) => $query->where('supplier_id', $supplierId))
            ->latest()->latest('id')
            ->get();

        $summaries = $orders->mapWithKeys(fn (PurchaseOrder $order): array => [$order->id => [
            'products' => $order->items->count(),
            'ordered_units' => (float) $order->items->sum('ordered_quantity'),
            'received_units' => (float) $order->items->sum('received_quantity'),
            'total' => $order->payableInvoice !== null ? (float) $order->payableInvoice->amount : round($order->items->sum(fn (PurchaseOrderItem $item): float => (float) $item->ordered_quantity * (float) $item->cost_snapshot), 2),
            'paid' => $order->payableInvoice !== null ? (float) $order->payableInvoice->paid_amount : null,
            'balance' => $order->payableInvoice !== null ? round((float) $order->payableInvoice->amount - (float) $order->payableInvoice->paid_amount, 2) : null,
        ]]);

        return view('purchase-orders.index', compact('orders', 'search', 'status', 'supplierId', 'suppliers', 'summaries'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('purchase-orders.create', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'variants' => ProductVariant::query()
                ->with('product')
                ->whereHas('product', fn ($query) => $query->where('status', 'active'))
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('is_active', true)], 'notes' => ['nullable', 'string', 'max:1000'], 'items' => ['required', 'array', 'min:1'], 'items.*.product_variant_id' => ['required', 'distinct', Rule::exists('product_variants', 'id')->whereIn('product_id', Product::query()->where('preferred_supplier_id', $request->input('supplier_id'))->where('status', 'active')->select('id'))], 'items.*.ordered_quantity' => ['required', 'integer', 'min:1']]);
        $order = DB::transaction(function () use ($data): PurchaseOrder {
            $order = PurchaseOrder::query()->create(['code' => 'OC-TEMP-'.str()->uuid(), 'supplier_id' => $data['supplier_id'], 'notes' => $data['notes'] ?? null]);
            $order->update(['code' => 'ODC-'.str_pad((string) $order->id, 2, '0', STR_PAD_LEFT)]);
            $variants = ProductVariant::query()->whereIn('id', collect($data['items'])->pluck('product_variant_id'))->get()->keyBy('id');
            foreach ($data['items'] as $item) {
                $variant = $variants[$item['product_variant_id']];
                $order->items()->create(['product_variant_id' => $variant->id, 'ordered_quantity' => $item['ordered_quantity'], 'cost_snapshot' => $variant->cost]);
            }

            return $order;
        });

        return redirect()->route('purchase-orders.show', $order)->with('success', 'Orden de compra creada.');
    }

    /**
     * Display the specified resource.
     */
    public function show(PurchaseOrder $purchaseOrder): View
    {
        return view('purchase-orders.show', [
            'purchaseOrder' => $purchaseOrder->load(['supplier', 'items.variant.product', 'payableInvoice']),
            'variants' => ProductVariant::query()
                ->with('product')
                ->whereHas('product', fn ($query) => $query->where('status', 'active')->where('preferred_supplier_id', $purchaseOrder->supplier_id))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function addItem(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->ensureDraft($purchaseOrder);
        $data = $request->validate([
            'product_variant_id' => ['required', Rule::exists('product_variants', 'id')->whereIn('product_id', Product::query()->where('preferred_supplier_id', $purchaseOrder->supplier_id)->where('status', 'active')->select('id'))],
            'ordered_quantity' => ['required', 'integer', 'min:1'],
        ]);
        $alreadyAdded = $purchaseOrder->items()
            ->where('product_variant_id', $data['product_variant_id'])
            ->exists();

        if ($alreadyAdded) {
            return back()->withErrors(['product_variant_id' => 'Ese producto ya forma parte de la orden. Puedes ajustar su cantidad.']);
        }

        $variant = ProductVariant::query()->findOrFail($data['product_variant_id']);
        $purchaseOrder->items()->create([
            'product_variant_id' => $variant->id,
            'ordered_quantity' => $data['ordered_quantity'],
            'cost_snapshot' => $variant->cost,
        ]);

        return back()->with('success', 'Producto agregado a la orden.');
    }

    public function updateItem(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $purchaseOrderItem): RedirectResponse
    {
        $this->ensureDraft($purchaseOrder);
        abort_unless($purchaseOrderItem->purchase_order_id === $purchaseOrder->id, 404);
        $data = $request->validate(['ordered_quantity' => ['required', 'integer', 'min:1']]);
        $purchaseOrderItem->update(['ordered_quantity' => $data['ordered_quantity']]);

        return back()->with('success', 'Cantidad de la orden actualizada.');
    }

    public function removeItem(PurchaseOrder $purchaseOrder, PurchaseOrderItem $purchaseOrderItem): RedirectResponse
    {
        $this->ensureDraft($purchaseOrder);
        abort_unless($purchaseOrderItem->purchase_order_id === $purchaseOrder->id, 404);

        if ($purchaseOrder->items()->count() === 1) {
            return back()->withErrors(['order' => 'Una orden debe conservar al menos un producto.']);
        }

        $purchaseOrderItem->delete();

        return back()->with('success', 'Producto eliminado de la orden.');
    }

    public function download(PurchaseOrder $purchaseOrder, PurchaseOrderPdfService $pdf): Response
    {
        return response($pdf->render($purchaseOrder), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$purchaseOrder->code.'.pdf"',
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function receive(Request $request, PurchaseOrder $purchaseOrder, InventoryService $inventory): RedirectResponse
    {
        abort_unless($purchaseOrder->status === 'draft', 422, 'Esta orden ya fue recibida.');
        $data = $request->validate(['invoice' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'pdf'])->max('10mb')], 'invoice_reference' => ['nullable', 'string', 'max:120'], 'amount' => ['required', 'numeric', 'min:.01'], 'items' => ['required', 'array'], 'items.*' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($data, $purchaseOrder, $inventory, $request): void {
            $order = PurchaseOrder::query()->with(['supplier', 'items.variant'])->lockForUpdate()->findOrFail($purchaseOrder->id);
            $warehouse = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();
            foreach ($order->items as $item) {
                $quantity = (float) ($data['items'][$item->id] ?? 0);
                if ($quantity > (float) $item->ordered_quantity) {
                    abort(422, 'No puedes recibir más de lo solicitado.');
                } $item->update(['received_quantity' => $quantity]);
                if ($quantity > 0) {
                    $inventory->move($item->product_variant_id, $warehouse->id, $quantity, 'purchase_receipt', $request->user()->id, PurchaseOrder::class, $order->id, 'Recepción '.$order->code);
                }
            }
            $order->update(['status' => 'received', 'received_at' => now(), 'received_by' => $request->user()->id]);
            $path = $request->hasFile('invoice') ? $request->file('invoice')->store('supplier-invoices', 'public') : null;
            PayableInvoice::query()->create(['purchase_order_id' => $order->id, 'invoice_reference' => $data['invoice_reference'] ?? null, 'invoice_path' => $path, 'amount' => $data['amount'], 'due_on' => now()->addDays($order->supplier->payment_grace_days), 'status' => 'pending']);
        });

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', 'Recepción registrada y cuenta por pagar creada.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    private function ensureDraft(PurchaseOrder $purchaseOrder): void
    {
        abort_unless($purchaseOrder->status === 'draft', 422, 'Esta orden ya fue recibida y no se puede modificar.');
    }
}
