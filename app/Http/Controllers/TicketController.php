<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\FinanceAccount;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\CashCutService;
use App\Services\InventoryService;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function createProductSale(): View
    {
        return view('tickets.create-sale', [
            'customers' => Customer::query()->where('status', 'active')->orderBy('first_name')->get(),
        ]);
    }

    public function storeProductSale(Request $request, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $ticket = DB::transaction(function () use ($data, $tickets): Ticket {
            $customerId = $data['customer_id'] ?? null;
            $customerName = trim((string) ($data['customer_name'] ?? ''));

            if (! $customerId && $customerName !== '') {
                $parts = preg_split('/\s+/', $customerName, 2) ?: [$customerName];
                $customer = Customer::create([
                    'first_name' => $parts[0],
                    'last_name' => $parts[1] ?? '',
                    'phone' => $data['customer_phone'] ?? null,
                    'status' => 'active',
                ]);
                $customerId = $customer->id;
            }

            return $tickets->createProductSale($customerId);
        });

        return redirect()->route('tickets.show', $ticket)->with('success', 'Venta de producto creada. Agrega los productos para continuar.');
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();
        $tickets = Ticket::query()->with('customer')
            ->when($search, fn ($query) => $query->where(fn ($query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customers) => $customers
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%"))))
            ->when(in_array($status, ['open', 'in_service', 'paid'], true), fn ($query) => $query->where('status', $status))
            ->when(session('crepe.mode') === 'color-bar', fn ($query) => $query->where('ticket_type', '!=', 'product_sale'))
            ->when($from, fn ($query, $from) => $query->whereDate('opened_at', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('opened_at', '<=', $to))
            ->latest('opened_at')->paginate(24)->withQueryString();

        return view('tickets.index', compact('from', 'tickets', 'search', 'status', 'to'));
    }

    public function show(Ticket $ticket, Request $request, CashCutService $cashCuts): View
    {
        abort_if($ticket->ticket_type === 'product_sale' && ! $request->user()->can('mode.reception.access'), 403);
        $ticket->load(['customer', 'appointment.services', 'items', 'adjustments', 'payments']);
        $services = SalonService::query()
            ->with(['category', 'prices'])
            ->where('status', 'active')
            ->orderBy('service_category_id')
            ->orderBy('name')
            ->get();
        $variants = ProductVariant::query()->with([
            'product',
            'balances' => fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', 'REC')),
        ])->whereHas('product', fn ($query) => $query->where('status', 'active')->where('is_color_bar_usable', false))
            ->where('sale_price', '>', 0)->orderBy('name')->get();

        $cashRegisters = CashRegister::query()->where('is_active', true)
            ->whereHas('sessions', fn ($query) => $query->where('status', 'open')->whereDate('business_date', now()->toDateString()))
            ->when(! $request->user()->can('cash.authorize'), fn ($query) => $query->whereHas('sessions', fn ($sessions) => $sessions->where('opened_by', $request->user()->id)->where('status', 'open')->whereDate('business_date', now()->toDateString())))
            ->orderBy('name')->get();
        $cashSession = $cashCuts->sessionForPaymentPreview($request->user()->id);
        $promotions = Promotion::query()->where('status', 'active')->where(function ($query): void {
            $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
        })->where(function ($query): void {
            $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
        })->orderBy('name')->get();

        $financeAccounts = FinanceAccount::query()->where('is_active', true)->orderBy('name')->get();

        return view('tickets.show', compact('ticket', 'services', 'variants', 'cashRegisters', 'cashSession', 'financeAccounts', 'promotions'));
    }

    public function addService(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_if($ticket->ticket_type === 'product_sale' && ! $request->user()->can('mode.reception.access'), 403);
        abort_if($ticket->status === 'paid', 422);

        $data = $request->validate([
            'salon_service_id' => ['required', Rule::exists('salon_services', 'id')->where('status', 'active')],
            'price_tier' => ['nullable', 'string', 'max:80'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $request, $ticket): void {
            $ticket = Ticket::query()->with('appointment.employee')->lockForUpdate()->findOrFail($ticket->id);
            $service = SalonService::query()->with('prices')->where('status', 'active')->lockForUpdate()->findOrFail($data['salon_service_id']);
            $employee = $ticket->appointment?->employee;
            $priceTier = null;
            $unitPrice = null;
            $cost = $service->base_cost;

            if ($service->price_type === 'fixed') {
                $priceTier = $data['price_tier'] ?? $service->prices->first()?->tier;
                $tierPrice = $service->prices->firstWhere('tier', $priceTier);
                if ($tierPrice === null) {
                    throw ValidationException::withMessages([
                        'price_tier' => 'Selecciona una tarifa válida para este servicio.',
                    ]);
                }
                $unitPrice = $tierPrice->sale_price;
                $cost = $tierPrice->cost;
            } elseif (! isset($data['unit_price'])) {
                throw ValidationException::withMessages([
                    'unit_price' => 'Indica el precio final del servicio variable.',
                ]);
            } else {
                $unitPrice = $data['unit_price'];
            }

            $ticket->items()->create([
                'type' => 'service',
                'name_snapshot' => $service->name,
                'quantity' => 1,
                'unit' => 'servicio',
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice,
                'cost_snapshot' => $cost,
                'status' => 'active',
                'metadata' => [
                    'salon_service_id' => $service->id,
                    'catalog_price' => $unitPrice,
                    'price_type' => $service->price_type,
                    'price_tier' => $priceTier,
                    'price_confirmed' => false,
                    'employee_id' => $employee?->id,
                    'commission_rate' => $employee?->commission_rate,
                ],
                'added_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Servicio agregado al ticket.');
    }

    public function confirmService(Request $request, Ticket $ticket, TicketItem $item): RedirectResponse
    {
        $data = $request->validate(['price_tier' => ['nullable', 'string', 'max:80']]);

        DB::transaction(function () use ($data, $request, $ticket, $item): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $ticketItem = TicketItem::query()->where('ticket_id', $ticket->id)->lockForUpdate()->findOrFail($item->id);

            abort_if($ticket->status === 'paid' || $ticketItem->type !== 'service' || $ticketItem->status !== 'active', 422);

            $metadata = $ticketItem->metadata ?? [];
            $service = SalonService::query()->with('prices')->find($metadata['salon_service_id'] ?? null)
                ?? SalonService::query()->with('prices')->where('name', $ticketItem->name_snapshot)->first();

            if ($service?->price_type === 'fixed') {
                $priceTier = $data['price_tier'] ?? $metadata['price_tier'] ?? $service->prices->first()?->tier;
                $tierPrice = $service->prices->firstWhere('tier', $priceTier);

                if ($tierPrice === null) {
                    throw ValidationException::withMessages(['price_tier' => 'Selecciona una tarifa válida para confirmar el servicio.']);
                }

                $ticketItem->unit_price = $tierPrice->sale_price;
                $ticketItem->line_total = $tierPrice->sale_price;
                $ticketItem->cost_snapshot = $tierPrice->cost;
                $metadata['price_tier'] = $tierPrice->tier;
            }

            $metadata['price_confirmed'] = true;
            $metadata['price_confirmed_by'] = $request->user()->id;
            $metadata['price_confirmed_at'] = now()->toIso8601String();
            $ticketItem->metadata = $metadata;
            $ticketItem->save();
        });

        return back()->with('success', 'Tipo de precio confirmado.');
    }

    public function addProduct(Request $request, Ticket $ticket, InventoryService $inventory): RedirectResponse
    {
        abort_if($ticket->ticket_type === 'product_sale' && ! $request->user()->can('mode.reception.access'), 403);
        abort_if($ticket->status === 'paid', 422);
        $data = $request->validate(['product_variant_id' => ['required', Rule::exists('product_variants', 'id')], 'quantity' => ['required', 'numeric', 'min:1']]);
        DB::transaction(function () use ($data, $ticket, $request, $inventory): void {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $variant = ProductVariant::query()->with('product')->findOrFail($data['product_variant_id']);
            if ($variant->product->is_color_bar_usable) {
                abort(422, 'Este producto sólo puede descontarse desde Color Bar.');
            }
            $reception = InventoryLocation::query()->where('code', 'REC')->firstOrFail();
            $item = $ticket->items()->create(['type' => 'product', 'name_snapshot' => $variant->product->name, 'quantity' => $data['quantity'], 'unit' => $variant->base_unit, 'unit_price' => $variant->sale_price, 'line_total' => $variant->sale_price * $data['quantity'], 'cost_snapshot' => $variant->cost, 'status' => 'active', 'metadata' => ['product_id' => $variant->product_id, 'product_variant_id' => $variant->id], 'added_by' => $request->user()->id]);
            $inventory->move($variant->id, $reception->id, -(float) $data['quantity'], 'sale', $request->user()->id, $item::class, $item->id, "Venta en {$ticket->code}");
        });

        return back()->with('success', 'Producto agregado al ticket.');
    }

    public function payment(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:.01'],
            'cash_register_id' => ['required', Rule::exists('cash_registers', 'id')->where('is_active', true)],
            'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'other'])],
            'finance_account_id' => ['nullable', Rule::exists('finance_accounts', 'id')->where('is_active', true)],
        ]);
        $financeAccountId = $data['finance_account_id'] ?? CashRegister::query()->whereKey($data['cash_register_id'])->value('finance_account_id');
        $financeAccountId ??= FinanceAccount::query()
            ->where('is_active', true)
            ->where('type', match ($data['method']) {
                'cash' => 'cash',
                'card', 'transfer' => 'bank',
                default => 'other',
            })->orderByDesc('is_primary')->orderBy('id')->value('id');
        $tickets->registerPayment($ticket, (float) $data['amount'], $data['method'], (int) $data['cash_register_id'], $request->user()->id, $financeAccountId);

        return back()->with('success', 'Pago registrado correctamente.');
    }

    public function close(Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $tickets->close($ticket);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket cerrado y cita completada.');
    }
}
