<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Services\AppointmentService;
use App\Services\CashCutService;
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
            'commissionableEmployees' => Employee::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get(),
            'variants' => ProductVariant::query()
                ->with([
                    'product',
                    'balances' => fn ($query) => $query->whereHas('location', fn ($locations) => $locations->where('code', 'REC')),
                ])
                ->whereHas('product', fn ($query) => $query->where('status', 'active')->where('is_color_bar_usable', false))
                ->where('sale_price', '>', 0)
                ->whereHas('balances', fn ($query) => $query
                    ->where('available_quantity', '>', 0)
                    ->whereHas('location', fn ($locations) => $locations->where('code', 'REC')))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeProductSale(Request $request, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')],
            'customer_name' => ['nullable', 'string', 'max:200'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')],
            'products.*.selected' => ['nullable', 'boolean'],
            'products.*.quantity' => ['nullable', 'numeric', 'min:1'],
            'products.*.employee_id' => ['nullable', Rule::exists('employees', 'id')->where('status', 'active')],
        ]);
        $products = collect($data['products'])->filter(fn (array $product): bool => (bool) ($product['selected'] ?? false));
        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['products' => 'Selecciona al menos un producto para crear la venta.']);
        }
        if ($products->contains(fn (array $product): bool => ! isset($product['quantity']))) {
            throw ValidationException::withMessages(['products' => 'Indica la cantidad de cada producto seleccionado.']);
        }

        $ticket = DB::transaction(function () use ($data, $products, $request, $tickets): Ticket {
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

            $ticket = $tickets->createProductSale($customerId);
            foreach ($products as $product) {
                $tickets->addReceptionProduct(
                    $ticket,
                    (int) $product['product_variant_id'],
                    (float) $product['quantity'],
                    $request->user()->id,
                    $product['employee_id'] ?? null,
                );
            }

            return $ticket;
        });

        return redirect()->route('tickets.show', $ticket)->with('success', 'Venta de producto creada con sus productos.');
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
            ->when(session('crepe.mode') === 'color-bar', fn ($query) => $query->where(function ($colorBarTickets): void {
                $colorBarTickets
                    ->whereHas('appointment.services.service', fn ($services) => $services->where('requires_color_bar', true))
                    ->orWhereHas('items', fn ($items) => $items->where('type', 'color_bar')->where('status', 'active'));
            }))
            ->when($from, fn ($query, $from) => $query->whereDate('opened_at', '>=', $from))
            ->when($to, fn ($query, $to) => $query->whereDate('opened_at', '<=', $to))
            ->latest('opened_at')->paginate(24)->withQueryString();

        return view('tickets.index', compact('from', 'tickets', 'search', 'status', 'to'));
    }

    public function show(Ticket $ticket, Request $request, CashCutService $cashCuts): View
    {
        abort_if($ticket->ticket_type === 'product_sale' && ! $request->user()->can('mode.reception.access'), 403);
        $isCashAdministrator = $request->user()->can('cash.authorize');
        if (session('crepe.mode') === 'color-bar' && ! $isCashAdministrator) {
            $isColorBarTicket = $ticket->appointment()
                ->whereHas('services.service', fn ($services) => $services->where('requires_color_bar', true))
                ->exists()
                || $ticket->items()->where('type', 'color_bar')->where('status', 'active')->exists();

            abort_unless($isColorBarTicket, 403);
        }
        $ticket->load(['customer', 'appointment.employee', 'appointment.secondaryEmployee', 'appointment.services', 'items', 'adjustments', 'payments']);
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
            ->when(! $isCashAdministrator, fn ($query) => $query->whereHas('sessions', fn ($sessions) => $sessions->where('opened_by', $request->user()->id)->where('status', 'open')->whereDate('business_date', now()->toDateString())))
            ->orderBy('name')->get();
        $cashSession = $cashCuts->sessionForPaymentPreview($request->user()->id);
        $promotions = Promotion::query()->where('status', 'active')->where(function ($query): void {
            $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
        })->where(function ($query): void {
            $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
        })->orderBy('name')->get();

        $financeAccounts = $isCashAdministrator
            ? FinanceAccount::query()->where('is_active', true)->orderBy('name')->get()
            : collect();
        $responsibleEmployees = Employee::query()->where('is_bookable', true)->where('status', 'active')->orderBy('first_name')->get();
        $commissionableEmployees = Employee::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get();

        return view('tickets.show', compact('ticket', 'services', 'variants', 'cashRegisters', 'cashSession', 'financeAccounts', 'isCashAdministrator', 'promotions', 'responsibleEmployees', 'commissionableEmployees'));
    }

    public function addService(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        abort_if($ticket->ticket_type === 'product_sale' && ! $request->user()->can('mode.reception.access'), 403);
        abort_if($ticket->status === 'paid', 422);

        $data = $request->validate([
            'salon_service_id' => ['required', Rule::exists('salon_services', 'id')->where('status', 'active')],
            'price_tier' => ['nullable', 'string', 'max:80'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $request, $ticket, $tickets): void {
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
                    'employee_commission_rate' => $employee?->commission_rate,
                ],
                'added_by' => $request->user()->id,
            ]);
            $tickets->syncPricingTotals($ticket);
        });

        return back()->with('success', 'Servicio agregado al ticket.');
    }

    public function confirmService(Request $request, Ticket $ticket, TicketItem $item, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'price_tier' => ['nullable', 'string', 'max:80'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $request, $ticket, $item, $tickets): void {
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
            } elseif ($service?->price_type === 'variable') {
                $unitPrice = $data['unit_price'] ?? $ticketItem->unit_price;

                if ((float) $unitPrice < (float) $service->base_price) {
                    throw ValidationException::withMessages([
                        'unit_price' => 'El precio final no puede ser menor al mínimo configurado de $'.number_format((float) $service->base_price, 2).'.',
                    ]);
                }

                $ticketItem->unit_price = $unitPrice;
                $ticketItem->line_total = $unitPrice;
                $metadata['catalog_price'] = (float) $service->base_price;
            }

            $metadata['price_confirmed'] = true;
            $metadata['price_confirmed_by'] = $request->user()->id;
            $metadata['price_confirmed_at'] = now()->toIso8601String();
            $ticketItem->metadata = $metadata;
            $ticketItem->save();
            $tickets->syncPricingTotals($ticket);
        });

        return back()->with('success', 'Tipo de precio confirmado.');
    }

    public function addSecondaryStylist(Request $request, Ticket $ticket, AppointmentService $appointments): RedirectResponse
    {
        abort_unless($ticket->appointment !== null, 404);
        abort_if($ticket->status === 'paid', 422);
        $data = $request->validate([
            'secondary_employee_id' => ['required', Rule::exists('employees', 'id')],
        ]);

        $appointments->assignSecondaryEmployee($ticket->appointment, (int) $data['secondary_employee_id']);

        return back()->with('success', 'Estilista responsable agregada al ticket.');
    }

    public function removeSecondaryStylist(Ticket $ticket, AppointmentService $appointments): RedirectResponse
    {
        abort_unless($ticket->appointment !== null, 404);
        abort_if($ticket->status === 'paid', 422);

        $appointments->removeSecondaryEmployee($ticket->appointment);

        return back()->with('success', 'Estilista responsable eliminada del ticket.');
    }

    public function addProduct(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        abort_if($ticket->ticket_type === 'product_sale' && ! $request->user()->can('mode.reception.access'), 403);
        abort_if($ticket->status === 'paid', 422);
        $data = $request->validate(['product_variant_id' => ['required', Rule::exists('product_variants', 'id')], 'quantity' => ['required', 'numeric', 'min:1'], 'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('status', 'active')]]);
        $variant = ProductVariant::query()->with('product')->findOrFail($data['product_variant_id']);
        abort_if(
            $variant->product->status !== 'active' || $variant->product->is_color_bar_usable || (float) $variant->sale_price <= 0,
            422,
            'Este producto sólo puede descontarse desde Color Bar.',
        );
        $tickets->addReceptionProduct($ticket, (int) $data['product_variant_id'], (float) $data['quantity'], $request->user()->id, $data['employee_id'] ?? null);

        return back()->with('success', 'Producto agregado al ticket.');
    }

    public function payment(Request $request, Ticket $ticket, TicketService $tickets, CashCutService $cashCuts): RedirectResponse
    {
        $isCashAdministrator = $request->user()->can('cash.authorize');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:.01'],
            'method' => ['required', Rule::in(['cash', 'card', 'transfer', 'gift_card', 'other'])],
        ]);
        if ($isCashAdministrator) {
            $data = array_merge($data, $request->validate([
                'cash_register_id' => ['required', Rule::exists('cash_registers', 'id')->where('is_active', true)],
                'finance_account_id' => ['nullable', Rule::exists('finance_accounts', 'id')->where('is_active', true)],
            ]));
            $cashRegisterId = (int) $data['cash_register_id'];
            $financeAccountId = $data['finance_account_id'] ?? CashRegister::query()->whereKey($cashRegisterId)->value('finance_account_id');
        } else {
            $cashSession = $cashCuts->sessionForPaymentPreview($request->user()->id);
            if ($cashSession === null) {
                throw ValidationException::withMessages([
                    'cash_register_id' => 'No tienes una caja abierta. Abre tu caja para comenzar a cobrar.',
                ]);
            }
            $cashSession->loadMissing('register');
            $cashRegisterId = $cashSession->cash_register_id;
            $financeAccountId = $cashSession->register?->finance_account_id;
        }
        if ($data['method'] === 'gift_card') {
            $financeAccountId = null;
        } else {
            $financeAccountId ??= FinanceAccount::query()
                ->where('is_active', true)
                ->where('type', match ($data['method']) {
                    'cash' => 'cash',
                    'card', 'transfer' => 'bank',
                    default => 'other',
                })->orderByDesc('is_primary')->orderBy('id')->value('id');
        }
        $tickets->registerPayment($ticket, (float) $data['amount'], $data['method'], $cashRegisterId, $request->user()->id, $financeAccountId);

        return back()->with('success', 'Pago registrado correctamente.');
    }

    public function updateDiscount(Request $request, Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'discount_amount' => ['required', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $tickets->setManualDiscount(
            $ticket,
            (float) $data['discount_amount'],
            filled($data['discount_reason'] ?? null) ? trim($data['discount_reason']) : null,
            $request->user()->id,
        );

        return back()->with('success', (float) $data['discount_amount'] > 0 ? 'Descuento manual actualizado.' : 'Descuento manual eliminado.');
    }

    public function close(Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $tickets->close($ticket);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket cerrado y cita completada.');
    }

    public function reopen(Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $tickets->reopen($ticket);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket reabierto. Los pagos registrados se conservaron para mantener la trazabilidad de caja.');
    }

    public function cancel(Ticket $ticket, TicketService $tickets): RedirectResponse
    {
        $tickets->cancel($ticket);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Ticket cancelado. El registro permanece disponible para auditoría.');
    }
}
