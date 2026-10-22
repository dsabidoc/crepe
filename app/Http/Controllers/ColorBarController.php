<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ColorFormula;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Services\ColorFormulaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ColorBarController extends Controller
{
    public function index(Request $request, ColorFormulaService $formulas): View
    {
        $request->session()->put('crepe.mode', 'color-bar');

        $location = InventoryLocation::query()->where('code', 'CB')->firstOrFail();
        $ticketSearch = trim((string) $request->string('ticket_search'));
        $requestedTicketId = $request->integer('ticket');
        $tickets = Ticket::query()
            ->with(['customer', 'appointment.services'])
            ->withCount([
                'colorFormulas as confirmed_color_formulas_count' => fn ($query) => $query->where('status', 'confirmed')->whereHas('items'),
            ])
            ->whereIn('status', ['open', 'in_service'])
            ->when(
                $ticketSearch === '',
                function ($query) use ($requestedTicketId): void {
                    $query->where(function ($tickets) use ($requestedTicketId): void {
                        $tickets->whereHas('appointment', fn ($appointments) => $appointments->whereDate('starts_at', today()));

                        if ($requestedTicketId > 0) {
                            $tickets->orWhereKey($requestedTicketId);
                        }
                    });
                },
            )
            ->where(function ($query): void {
                $query->whereHas('appointment.services.service', fn ($services) => $services->where('requires_color_bar', true))
                    ->orWhereHas('items', fn ($items) => $items->where('type', 'service')->where('status', 'active')
                        ->whereIn('metadata->salon_service_id', SalonService::query()->where('requires_color_bar', true)->select('id')))
                    ->orWhereHas('items', fn ($items) => $items->where('type', 'color_bar')->where('status', 'active'));
            })
            ->when($ticketSearch !== '', function ($query) use ($ticketSearch): void {
                $query->where(function ($tickets): void {
                    $tickets->whereHas('appointment', fn ($appointments) => $appointments->whereDate('starts_at', '<=', today()))
                        ->orWhere(fn ($walkIns) => $walkIns->whereDoesntHave('appointment')->whereDate('opened_at', '<=', today()));
                });
                foreach (preg_split('/\s+/u', $ticketSearch, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
                    $query->where(fn ($searchQuery) => $searchQuery
                        ->where('code', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($customers) => $customers
                            ->where(fn ($names) => $names
                                ->where('first_name', 'like', "%{$term}%")
                                ->orWhere('last_name', 'like', "%{$term}%"))));
                }
            })
            ->orderBy(Appointment::query()->select('starts_at')->whereColumn('appointments.id', 'tickets.appointment_id'))
            ->get();
        $variants = ProductVariant::query()
            ->with([
                'product.category',
                'product.preferredSupplier',
            ])
            ->whereHas('product', fn ($query) => $query
                ->where('status', 'active')
                ->where('is_color_bar_usable', true))
            ->where(function ($query): void {
                $query->where(fn ($fixedPrice) => $fixedPrice
                    ->where('color_bar_pricing_type', 'fixed'))
                    ->orWhere(fn ($multiplier) => $multiplier
                        ->where('color_bar_pricing_type', 'multiplier')
                        ->whereHas('product.category', fn ($categories) => $categories->whereNotNull('color_bar_multiplier')));
            })
            ->orderBy('product_id')
            ->orderBy('name')
            ->get();
        $recentFormulas = ColorFormula::query()
            ->with(['ticket.customer', 'items.variant.product.preferredSupplier'])
            ->where('inventory_location_id', $location->id)
            ->where('status', 'confirmed')
            ->whereIn('ticket_id', $tickets->pluck('id'))
            ->latest()
            ->get();
        $editingFormula = $recentFormulas->firstWhere('id', $request->integer('formula'));
        $selectedTicketId = $editingFormula?->ticket_id ?? $requestedTicketId;
        $selectedTicket = $tickets->firstWhere('id', $selectedTicketId);

        if ($selectedTicket === null) {
            $selectedTicketId = null;
        }

        $withFormulaCount = $tickets->where('confirmed_color_formulas_count', '>', 0)->count();
        $ticketCounts = ['pending' => $tickets->count() - $withFormulaCount, 'with_formula' => $withFormulaCount, 'all' => $tickets->count()];
        $defaultTicketTab = $ticketSearch !== '' ? 'all' : ($selectedTicket?->confirmed_color_formulas_count > 0 ? 'with_formula' : 'pending');
        $requestedTicketTab = $request->old('ticket_tab', $request->query('ticket_tab'));
        $ticketTab = in_array($requestedTicketTab, ['pending', 'with_formula', 'all'], true) ? $requestedTicketTab : $defaultTicketTab;

        $serviceOptions = $selectedTicket === null ? collect() : $formulas->availableServices($selectedTicket);
        $selectedServiceId = ($editingFormula?->ticket_item_id ?? $request->integer('service')) ?: null;
        if ($selectedTicket !== null && (int) $request->old('ticket_id') === $selectedTicket->id && $request->old('ticket_item_id') !== null) {
            $selectedServiceId = (int) $request->old('ticket_item_id');
        }
        if (! $serviceOptions->contains('id', $selectedServiceId)) {
            $selectedServiceId = $serviceOptions->count() === 1 ? $serviceOptions->first()['id'] : null;
        }

        $initialFormulaItems = $editingFormula?->items->map(fn ($item): array => [
            'variantId' => $item->product_variant_id,
            'name' => $item->variant->product->name,
            'brand' => $item->variant->product->brand,
            'supplier' => $item->variant->product->preferredSupplier?->name,
            'pricingType' => $item->unit === 'g' ? 'multiplier' : $item->variant->color_bar_pricing_type,
            'unitPrice' => (float) ($item->sale_price ?? 0),
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'notes' => $item->notes,
        ])->values() ?? collect();
        $oldItems = $request->old('items');
        if ($selectedTicket !== null && (int) $request->old('ticket_id') === $selectedTicket->id && is_array($oldItems)) {
            $draftItems = collect($oldItems)->filter(fn ($item): bool => is_array($item));
            $draftVariants = ProductVariant::query()->with(['product.category', 'product.preferredSupplier'])
                ->whereIn('id', $draftItems->pluck('product_variant_id')->filter(fn ($id): bool => is_scalar($id) && ctype_digit((string) $id)))
                ->get()->keyBy('id');
            $initialFormulaItems = $draftItems->map(function (array $item) use ($draftVariants): ?array {
                $variant = $draftVariants->get(is_scalar($item['product_variant_id'] ?? null) ? (int) $item['product_variant_id'] : 0);
                if ($variant === null || $variant->product === null) {
                    return null;
                }
                $usesMultiplier = $variant->color_bar_pricing_type === 'multiplier';

                return [
                    'variantId' => $variant->id,
                    'name' => $variant->product->name,
                    'brand' => $variant->product->brand,
                    'supplier' => $variant->product->preferredSupplier?->name,
                    'pricingType' => $variant->color_bar_pricing_type,
                    'unitPrice' => (float) ($usesMultiplier ? $variant->product->category?->color_bar_multiplier : $variant->color_bar_sale_price),
                    'quantity' => is_scalar($item['quantity'] ?? null) ? (float) $item['quantity'] : 0,
                    'unit' => $usesMultiplier ? 'g' : 'unidad',
                    'notes' => is_string($item['notes'] ?? null) ? $item['notes'] : '',
                ];
            })->filter()->values();
        }

        $canViewPrices = $request->user()->can('tickets.charge');
        if (! $canViewPrices) {
            $initialFormulaItems = $initialFormulaItems->map(function (array $item): array {
                unset($item['unitPrice']);

                return $item;
            });
        }

        return view('color-bar.index', [
            'canViewPrices' => $canViewPrices,
            'tickets' => $tickets,
            'variants' => $variants,
            'categories' => $variants->pluck('product.category')->filter()->unique('id')->values(),
            'location' => $location,
            'selectedTicketId' => $selectedTicketId,
            'selectedTicketFormulas' => $selectedTicket === null
                ? collect()
                : $recentFormulas->where('ticket_id', $selectedTicket->id)->values(),
            'editingFormula' => $editingFormula,
            'recentFormulas' => $recentFormulas,
            'ticketSearch' => $ticketSearch,
            'ticketTab' => $ticketTab,
            'ticketCounts' => $ticketCounts,
            'initialFormulaItems' => $initialFormulaItems,
            'serviceOptions' => $serviceOptions,
            'selectedServiceId' => $selectedServiceId,
        ]);
    }

    public function history(Ticket $ticket): View
    {
        abort_if($ticket->customer_id === null, 404);

        $visits = Ticket::query()
            ->where('customer_id', $ticket->customer_id)
            ->whereKeyNot($ticket->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query): void {
                $query->whereHas('appointment', fn ($appointments) => $appointments
                    ->where('status', '!=', 'cancelled')->where('starts_at', '<=', now()))
                    ->orWhere(fn ($walkIns) => $walkIns->whereDoesntHave('appointment')->where('opened_at', '<=', now()));
            })
            ->where(function ($query): void {
                $query->whereHas('appointment.services.service', fn ($services) => $services->where('requires_color_bar', true))
                    ->orWhereHas('colorFormulas', fn ($formulas) => $formulas->where('status', 'confirmed'));
            })
            ->with([
                'appointment.services',
                'appointment.employee',
                'colorFormulas' => fn ($formulas) => $formulas->where('status', 'confirmed')->orderBy('id'),
                'colorFormulas.items.variant.product.preferredSupplier',
            ])
            ->orderByRaw('COALESCE((SELECT starts_at FROM appointments WHERE appointments.id = tickets.appointment_id), tickets.opened_at) DESC')
            ->orderByDesc('id')
            ->paginate(8);

        return view('color-bar.history', compact('visits'));
    }

    public function store(Request $request, ColorFormulaService $formulas): RedirectResponse|JsonResponse
    {
        $data = $this->validatedFormula($request);
        $ticket = Ticket::query()->findOrFail($data['ticket_id']);
        $this->ensureColorBarTicket($ticket);
        $location = InventoryLocation::query()->where('code', 'CB')->firstOrFail();
        $formulas->record($ticket, $location, $data['items'], $data['notes'] ?? null, $request->user()->id, $data['ticket_item_id'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['saved' => true]);
        }

        return redirect()->route('color-bar.index', [
            'ticket' => $ticket->id,
            'ticket_search' => $data['ticket_search'] ?? null,
            'ticket_tab' => ($data['ticket_tab'] ?? null) === 'all' ? 'all' : null,
        ])->with('success', 'Fórmula confirmada y consumos registrados en el servicio.');
    }

    public function update(Request $request, ColorFormula $formula, ColorFormulaService $formulas): RedirectResponse|JsonResponse
    {
        $data = $this->validatedFormula($request, allowEmpty: true);
        if ((int) $data['ticket_id'] !== $formula->ticket_id) {
            throw ValidationException::withMessages(['ticket_id' => 'La fórmula pertenece a otro ticket.']);
        }
        $this->ensureColorBarTicket($formula->ticket);
        $location = InventoryLocation::query()->where('code', 'CB')->firstOrFail();
        $formulas->correct($formula, $location, $data['items'], $data['notes'] ?? null, $request->user()->id, $data['ticket_item_id'] ?? null);

        if ($request->expectsJson()) {
            return response()->json(['saved' => true]);
        }

        return redirect()->route('color-bar.index', [
            'ticket' => $formula->ticket_id,
            'ticket_search' => $data['ticket_search'] ?? null,
            'ticket_tab' => ($data['ticket_tab'] ?? null) === 'all' ? 'all' : null,
        ])->with('success', $data['items'] === []
            ? 'Corrección confirmada sin productos. Se retiraron sus cargos y MC del ticket.'
            : 'Fórmula corregida; el consumo y los cargos del servicio quedaron actualizados.');
    }

    private function validatedFormula(Request $request, bool $allowEmpty = false): array
    {
        if ($allowEmpty && ! $request->exists('items')) {
            $request->merge(['items' => []]);
        }

        return $request->validate([
            'ticket_id' => ['required', Rule::exists('tickets', 'id')],
            'ticket_item_id' => ['nullable', 'integer', Rule::exists('ticket_items', 'id')],
            'ticket_search' => ['nullable', 'string', 'max:120'],
            'ticket_tab' => ['nullable', Rule::in(['pending', 'with_formula', 'all'])],
            'notes' => ['nullable', 'string', 'max:600'],
            'items' => $allowEmpty ? ['present', 'array'] : ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'min:.001'],
            'items.*.unit' => ['required', Rule::in(['g', 'unidad'])],
            'items.*.notes' => ['nullable', 'string', 'max:300'],
        ]);
    }

    private function ensureColorBarTicket(Ticket $ticket): void
    {
        if ($ticket->status === 'paid') {
            throw ValidationException::withMessages([
                'ticket' => 'Recepción ya cobró este ticket. Su fórmula queda guardada y no puede modificarse.',
            ]);
        }

        $eligible = in_array($ticket->status, ['open', 'in_service'], true) && (
            $ticket->appointment()->whereHas('services.service', fn ($services) => $services->where('requires_color_bar', true))->exists()
            || $ticket->items()->where('type', 'service')->where('status', 'active')
                ->whereIn('metadata->salon_service_id', SalonService::query()->where('requires_color_bar', true)->select('id'))->exists()
            || $ticket->items()->where('type', 'color_bar')->where('status', 'active')->exists()
        );

        throw_unless($eligible, ValidationException::withMessages([
            'ticket_id' => 'Este ticket no tiene servicios que requieran Color Bar.',
        ]));
    }
}
