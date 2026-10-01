<?php

namespace App\Http\Controllers;

use App\Models\ColorFormula;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Services\ColorFormulaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ColorBarController extends Controller
{
    public function index(Request $request): View
    {
        $location = InventoryLocation::query()->where('code', 'CB')->firstOrFail();
        $ticketSearch = trim((string) $request->string('ticket_search'));
        $tickets = Ticket::query()
            ->with(['customer', 'appointment.services'])
            ->whereIn('status', ['open', 'in_service'])
            ->where(function ($query): void {
                $query->whereHas('appointment.services.service', fn ($services) => $services->where('requires_color_bar', true))
                    ->orWhereHas('items', fn ($items) => $items->where('type', 'color_bar')->where('status', 'active'));
            })
            ->when($ticketSearch, fn ($query) => $query->where(fn ($searchQuery) => $searchQuery
                ->where('code', 'like', "%{$ticketSearch}%")
                ->orWhereHas('customer', fn ($customers) => $customers
                    ->where('first_name', 'like', "%{$ticketSearch}%")
                    ->orWhere('last_name', 'like', "%{$ticketSearch}%"))))
            ->latest('opened_at')
            ->get();
        $variants = ProductVariant::query()
            ->with([
                'product.category',
                'balances' => fn ($query) => $query->where('inventory_location_id', $location->id),
            ])
            ->whereHas('product', fn ($query) => $query->where('status', 'active')->where('is_color_bar_usable', true))
            ->whereHas('balances', fn ($query) => $query->where('inventory_location_id', $location->id)->where('available_quantity', '>', 0))
            ->orderBy('product_id')
            ->orderBy('name')
            ->get();
        $recentFormulas = ColorFormula::query()
            ->with(['ticket.customer', 'items.variant.product'])
            ->where('inventory_location_id', $location->id)
            ->where('status', 'confirmed')
            ->whereHas('ticket', fn ($query) => $query->whereIn('status', ['open', 'in_service']))
            ->latest()
            ->get();
        $editingFormula = $recentFormulas->firstWhere('id', $request->integer('formula'));
        $selectedTicketId = $editingFormula?->ticket_id ?? $request->integer('ticket');

        if (! $tickets->contains('id', $selectedTicketId)) {
            $selectedTicketId = null;
        }

        return view('color-bar.index', [
            'tickets' => $tickets,
            'variants' => $variants,
            'categories' => $variants->pluck('product.category')->filter()->unique('id')->values(),
            'location' => $location,
            'selectedTicketId' => $selectedTicketId,
            'editingFormula' => $editingFormula,
            'recentFormulas' => $recentFormulas,
            'ticketSearch' => $ticketSearch,
        ]);
    }

    public function store(Request $request, ColorFormulaService $formulas): RedirectResponse
    {
        $data = $this->validatedFormula($request);
        $ticket = Ticket::query()->findOrFail($data['ticket_id']);
        $this->ensureColorBarTicket($ticket);
        $location = InventoryLocation::query()->where('code', 'CB')->firstOrFail();
        $formulas->record($ticket, $location, $data['items'], $data['notes'] ?? null, $request->user()->id);

        return redirect()->route('color-bar.index', ['ticket' => $ticket->id])->with('success', 'Fórmula confirmada y consumos descontados.');
    }

    public function update(Request $request, ColorFormula $formula, ColorFormulaService $formulas): RedirectResponse
    {
        $data = $this->validatedFormula($request);
        if ((int) $data['ticket_id'] !== $formula->ticket_id) {
            throw ValidationException::withMessages(['ticket_id' => 'La fórmula pertenece a otro ticket.']);
        }
        $location = InventoryLocation::query()->where('code', 'CB')->firstOrFail();
        $formulas->correct($formula, $location, $data['items'], $data['notes'] ?? null, $request->user()->id);

        return redirect()->route('color-bar.index', ['ticket' => $formula->ticket_id])->with('success', 'Fórmula corregida; el reverso y el nuevo consumo quedaron registrados.');
    }

    private function validatedFormula(Request $request): array
    {
        return $request->validate([
            'ticket_id' => ['required', Rule::exists('tickets', 'id')],
            'notes' => ['nullable', 'string', 'max:600'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', Rule::exists('product_variants', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'min:.001'],
            'items.*.unit' => ['required', Rule::in(['g', 'ml', 'l', 'lt'])],
            'items.*.notes' => ['nullable', 'string', 'max:300'],
        ]);
    }

    private function ensureColorBarTicket(Ticket $ticket): void
    {
        $eligible = $ticket->appointment()
            ->whereHas('services.service', fn ($services) => $services->where('requires_color_bar', true))
            ->exists()
            || $ticket->items()->where('type', 'color_bar')->where('status', 'active')->exists();

        throw_unless($eligible, ValidationException::withMessages([
            'ticket_id' => 'Este ticket no tiene servicios que requieran Color Bar.',
        ]));
    }
}
