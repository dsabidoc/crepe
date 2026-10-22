<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ColorFormula;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ColorFormulaService
{
    private const McRate = 0.10;

    public function __construct(
        private TicketService $tickets,
        private OperationalNotificationService $notifications,
    ) {}

    public function syncOpenFormulaChargesForService(SalonService $service, int $actorId): int
    {
        if (! $service->requires_color_bar) {
            return 0;
        }

        $included = (bool) $service->color_bar_included;
        $matchingService = fn ($items) => $items->where('type', 'service')->where('status', 'active')
            ->where(fn ($items) => $items->where('metadata->salon_service_id', $service->id)
                ->orWhereHas('appointmentService', fn ($appointments) => $appointments->where('salon_service_id', $service->id)));
        $changed = 0;
        $tickets = Ticket::query()->whereIn('status', ['open', 'in_service'])->whereNull('paid_at')
            ->whereDoesntHave('appointment', fn ($appointments) => $appointments->whereIn('status', ['completed', 'cancelled']))
            ->whereHas('items', $matchingService)
            ->whereHas('colorFormulas', fn ($formulas) => $formulas->where('status', 'confirmed')->where('color_bar_included', ! $included));

        foreach ($tickets->lazyById(100) as $ticket) {
            $changed += DB::transaction(function () use ($ticket, $matchingService, $service, $actorId, $included): int {
                $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
                $appointment = $ticket->appointment()->lockForUpdate()->first();
                if (! in_array($ticket->status, ['open', 'in_service'], true) || $ticket->paid_at !== null
                    || in_array($appointment?->status, ['completed', 'cancelled'], true)) {
                    return 0;
                }
                $items = $matchingService($ticket->items())->lockForUpdate()->get();
                $options = $this->availableServices($ticket);
                $legacyItemId = $options->count() === 1 && $items->contains('id', $options->first()['id']) ? $options->first()['id'] : null;
                $formulas = $ticket->colorFormulas()->where('status', 'confirmed')->where('color_bar_included', ! $included)
                    ->where(function ($formulas) use ($items, $legacyItemId): void {
                        $formulas->whereIn('ticket_item_id', $items->modelKeys());
                        if ($legacyItemId !== null) {
                            $formulas->orWhereNull('ticket_item_id');
                        }
                    })->lockForUpdate()->get();

                foreach ($formulas as $formula) {
                    $item = $items->firstWhere('id', $formula->ticket_item_id ?? $legacyItemId);
                    $formulaItems = $formula->items()->lockForUpdate()->get()->keyBy('id');
                    $colorBarSubtotal = $included ? 0.0 : round($formulaItems->sum(fn ($formulaItem): float => (float) $formulaItem->sale_price * (float) $formulaItem->quantity), 2);
                    $rows = $ticket->items()->whereIn('type', ['color_bar', 'color_bar_mc'])->where('status', 'active')
                        ->where('metadata->color_formula_id', $formula->id)->lockForUpdate()->get();
                    $beforeAmount = (float) $rows->sum('line_total');
                    foreach ($rows as $row) {
                        $metadata = [...($row->metadata ?? []), 'color_bar_included' => $included, 'service_ticket_item_id' => $item->id, 'service_name' => $item->name_snapshot];
                        if ($row->type === 'color_bar_mc') {
                            $metadata['color_bar_subtotal'] = $colorBarSubtotal;
                            $unitPrice = round($colorBarSubtotal * (float) ($metadata['mc_rate'] ?? self::McRate * 100) / 100, 2);
                            $lineTotal = $unitPrice;
                        } else {
                            $savedPrice = (float) ($formulaItems->get($metadata['color_formula_item_id'] ?? null)?->sale_price ?? $metadata['catalog_unit_price'] ?? $row->unit_price);
                            $metadata['catalog_unit_price'] ??= $savedPrice;
                            $unitPrice = $included ? 0 : $savedPrice;
                            $lineTotal = round($unitPrice * (float) $row->quantity, 2);
                        }
                        $row->update(['unit_price' => $unitPrice, 'line_total' => $lineTotal, 'metadata' => $metadata]);
                    }
                    foreach ($formulaItems as $formulaItem) {
                        $formulaItem->update(['charged_unit_price' => $included ? 0 : $formulaItem->sale_price]);
                    }
                    $formula->update(['color_bar_included' => $included, 'ticket_item_id' => $item->id, 'service_name_snapshot' => $item->name_snapshot]);
                    AuditLog::create([
                        'user_id' => $actorId, 'action' => $included ? 'color_formula.included' : 'color_formula.charge_restored', 'subject_type' => ColorFormula::class, 'subject_id' => $formula->id,
                        'before' => ['color_bar_included' => ! $included, 'charged_total' => $beforeAmount],
                        'after' => ['color_bar_included' => $included, 'charged_total' => (float) $rows->sum('line_total'), 'salon_service_id' => $service->id, 'ticket_id' => $ticket->id],
                        'reason' => $included ? 'Color Bar gratis activado en el servicio; ticket abierto.' : 'Color Bar gratis desactivado; cobro restaurado con los precios de la fórmula.',
                    ]);
                }
                if ($formulas->isNotEmpty()) {
                    $this->tickets->syncPricingTotals($ticket);
                }

                return $formulas->count();
            }, attempts: 3);
        }

        return $changed;
    }

    public function record(Ticket $ticket, InventoryLocation $location, array $items, ?string $notes, int $actorId, ?int $serviceItemId = null): ColorFormula
    {
        return DB::transaction(function () use ($ticket, $location, $items, $notes, $actorId, $serviceItemId): ColorFormula {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->ensureTicketCanReceiveFormula($ticket);

            return $this->createFormula($ticket, $location, $items, $notes, $actorId, $this->resolveService($ticket, $serviceItemId));
        });
    }

    public function correct(ColorFormula $formula, InventoryLocation $location, array $items, ?string $notes, int $actorId, ?int $serviceItemId = null): ColorFormula
    {
        return DB::transaction(function () use ($formula, $location, $items, $notes, $actorId, $serviceItemId): ColorFormula {
            $formula = ColorFormula::query()->lockForUpdate()->findOrFail($formula->id);
            if ($formula->inventory_location_id !== $location->id) {
                throw ValidationException::withMessages(['formula' => 'Esta fórmula no pertenece a Color Bar.']);
            }
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($formula->ticket_id);
            $this->ensureTicketCanReceiveFormula($ticket);

            if ($formula->status !== 'confirmed') {
                throw ValidationException::withMessages(['formula' => 'Esta fórmula ya fue corregida y no puede editarse otra vez.']);
            }

            $service = $this->resolveService($ticket, $serviceItemId ?? $formula->ticket_item_id, $formula);

            $formulaItems = $formula->items()->with('variant.product')->get();
            $this->reverseTicketItems($ticket, $formula, $formulaItems);
            $formula->update(['status' => 'corrected', 'corrected_by' => $actorId, 'corrected_at' => now()]);

            $correctedFormula = $this->createFormula($ticket, $location, $items, $notes, $actorId, $service, $formula->id);

            AuditLog::create([
                'user_id' => $actorId,
                'action' => 'color_formula.corrected',
                'subject_type' => ColorFormula::class,
                'subject_id' => $formula->id,
                'before' => ['status' => 'confirmed', 'items' => $formulaItems->map(fn ($item) => ['variant_id' => $item->product_variant_id, 'quantity' => $item->quantity, 'unit' => $item->unit])->all()],
                'after' => ['status' => 'corrected', 'replacement_formula_id' => $correctedFormula->id],
                'reason' => 'Corrección desde Color Bar',
            ]);

            return $correctedFormula;
        });
    }

    /** @return Collection<int, array{id:int, name:string, included:bool}> */
    public function availableServices(Ticket $ticket): Collection
    {
        $items = $ticket->items()->where('type', 'service')->where('status', 'active')->with('appointmentService.service')->get();
        $serviceIds = $items->map(fn ($item) => $item->metadata['salon_service_id'] ?? $item->appointmentService?->salon_service_id)->filter();
        $catalog = SalonService::query()->whereIn('id', $serviceIds)->get()->keyBy('id');

        return $items->map(function ($item) use ($catalog): ?array {
            $service = $catalog->get($item->metadata['salon_service_id'] ?? $item->appointmentService?->salon_service_id);
            if (! $service?->requires_color_bar) {
                return null;
            }

            return ['id' => $item->id, 'name' => $item->name_snapshot, 'included' => (bool) $service->color_bar_included];
        })->filter()->values();
    }

    /** @return array{id:?int, name:?string, included:bool} */
    private function resolveService(Ticket $ticket, ?int $serviceItemId, ?ColorFormula $original = null): array
    {
        $services = $this->availableServices($ticket);
        if ($serviceItemId === null && $services->count() === 1) {
            $serviceItemId = $services->first()['id'];
        }
        if ($serviceItemId === null && $services->isEmpty() && $original !== null && $original->ticket_item_id === null) {
            return ['id' => null, 'name' => $original->service_name_snapshot, 'included' => (bool) $original->color_bar_included];
        }
        $service = $services->firstWhere('id', $serviceItemId);
        if ($service === null) {
            throw ValidationException::withMessages(['ticket_item_id' => 'Selecciona un servicio activo de este ticket que requiera Color Bar.']);
        }

        return $service;
    }

    /** @param array{id:?int, name:?string, included:bool} $service */
    private function createFormula(Ticket $ticket, InventoryLocation $location, array $items, ?string $notes, int $actorId, array $service, ?int $supersedesFormulaId = null): ColorFormula
    {
        $preparedItems = $this->prepareItems($items);
        $catalogSubtotal = round(collect($preparedItems)->sum(fn (array $item): float => $item['unit_price'] * $item['quantity']), 2);
        $colorBarSubtotal = $service['included'] ? 0.0 : $catalogSubtotal;
        $mcAmount = round($colorBarSubtotal * self::McRate, 2);
        $formula = ColorFormula::create([
            'ticket_id' => $ticket->id,
            'ticket_item_id' => $service['id'],
            'service_name_snapshot' => $service['name'],
            'color_bar_included' => $service['included'],
            'customer_id' => $ticket->customer_id,
            'inventory_location_id' => $location->id,
            'supersedes_color_formula_id' => $supersedesFormulaId,
            'status' => 'confirmed',
            'notes' => $notes,
            'created_by' => $actorId,
        ]);

        foreach ($preparedItems as $item) {
            $formulaItem = $formula->items()->create([
                'product_variant_id' => $item['variant']->id,
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'notes' => $item['notes'],
                'cost_snapshot' => $item['variant']->cost,
                'sale_price' => $item['unit_price'],
                'charged_unit_price' => $service['included'] ? 0 : $item['unit_price'],
            ]);

            $ticket->items()->create([
                'type' => 'color_bar',
                'name_snapshot' => $item['variant']->product->name,
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $service['included'] ? 0 : $item['unit_price'],
                'line_total' => $service['included'] ? 0 : round($item['unit_price'] * $item['quantity'], 2),
                'cost_snapshot' => $item['variant']->cost,
                'status' => 'active',
                'metadata' => [
                    'color_formula_id' => $formula->id,
                    'color_formula_item_id' => $formulaItem->id,
                    'service_ticket_item_id' => $service['id'],
                    'service_name' => $service['name'],
                    'color_bar_included' => $service['included'],
                    'catalog_unit_price' => $item['unit_price'],
                    'captured_quantity' => $item['display_quantity'],
                    'captured_unit' => $item['display_unit'],
                    'color_bar_pricing_type' => $item['pricing_type'],
                    ...($item['pricing_type'] === 'multiplier' ? [
                        'color_bar_multiplier' => $item['unit_price'],
                        'color_bar_category_id' => $item['category']->id,
                        'color_bar_category' => $item['category']->name,
                    ] : [
                        'color_bar_fixed_price' => $item['unit_price'],
                    ]),
                ],
                'added_by' => $actorId,
            ]);
        }

        if ($preparedItems !== []) {
            $ticket->items()->create([
                'type' => 'color_bar_mc',
                'name_snapshot' => 'MC Color Bar (10%)',
                'quantity' => 1,
                'unit' => 'factor',
                'unit_price' => $mcAmount,
                'line_total' => $mcAmount,
                'cost_snapshot' => null,
                'status' => 'active',
                'metadata' => [
                    'color_formula_id' => $formula->id,
                    'service_ticket_item_id' => $service['id'],
                    'service_name' => $service['name'],
                    'color_bar_included' => $service['included'],
                    'color_bar_subtotal' => $colorBarSubtotal,
                    'mc_rate' => self::McRate * 100,
                ],
                'added_by' => $actorId,
            ]);
        }

        $this->tickets->syncPricingTotals($ticket);

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'color_formula.confirmed',
            'subject_type' => ColorFormula::class,
            'subject_id' => $formula->id,
            'after' => [
                'ticket_id' => $ticket->id,
                'service_ticket_item_id' => $service['id'],
                'color_bar_included' => $service['included'],
                'catalog_subtotal' => $catalogSubtotal,
                'items' => collect($preparedItems)->map(fn ($item) => ['variant_id' => $item['variant']->id, 'quantity' => $item['quantity'], 'unit' => $item['unit'], 'pricing_type' => $item['pricing_type']])->all(),
                'color_bar_subtotal' => $colorBarSubtotal,
                'mc_rate' => self::McRate * 100,
                'mc_amount' => $mcAmount,
            ],
            'reason' => 'Fórmula confirmada desde Color Bar',
        ]);

        $this->notifications->formulaRecorded($formula);

        return $formula;
    }

    private function prepareItems(array $items): array
    {
        $preparedItems = [];

        foreach ($items as $item) {
            $variant = ProductVariant::query()->with('product.category')->lockForUpdate()->findOrFail($item['product_variant_id']);
            if ($variant->product->status !== 'active' || ! $variant->product->is_color_bar_usable) {
                throw ValidationException::withMessages(['items' => 'Sólo puedes usar productos activos de Color Bar.']);
            }

            if ($variant->color_bar_pricing_type === 'fixed' || ($variant->color_bar_pricing_type === 'both' && $item['unit'] === 'unidad')) {
                $fixedPrice = (float) $variant->color_bar_sale_price;

                $preparedItems[] = [
                    'variant' => $variant,
                    'quantity' => 1,
                    'display_quantity' => 1,
                    'display_unit' => 'unidad',
                    'unit' => 'unidad',
                    'notes' => $item['notes'] ?? null,
                    'category' => null,
                    'pricing_type' => 'fixed',
                    'unit_price' => $fixedPrice,
                ];

                continue;
            }

            $category = $variant->product->category;
            if ($category === null || $category->color_bar_multiplier === null) {
                throw ValidationException::withMessages(['items' => "El producto {$variant->product->name} no tiene una categoría Color Bar con multiplicador."]);
            }
            $multiplier = (float) $category->color_bar_multiplier;

            $displayQuantity = (float) $item['quantity'];
            if ($item['unit'] !== 'g') {
                throw ValidationException::withMessages(['items' => "El producto {$variant->product->name} debe registrarse en gramos para Color Bar."]);
            }
            if ($displayQuantity <= 0) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor a cero.']);
            }
            $preparedItems[] = [
                'variant' => $variant,
                'quantity' => $displayQuantity,
                'display_quantity' => $displayQuantity,
                'display_unit' => 'g',
                'unit' => 'g',
                'notes' => $item['notes'] ?? null,
                'category' => $category,
                'pricing_type' => 'multiplier',
                'unit_price' => $multiplier,
            ];
        }

        return $preparedItems;
    }

    private function reverseTicketItems(Ticket $ticket, ColorFormula $formula, Collection $formulaItems): void
    {
        $reversed = $ticket->items()
            ->whereIn('type', ['color_bar', 'color_bar_mc'])
            ->where('status', 'active')
            ->where('metadata->color_formula_id', $formula->id)
            ->update(['status' => 'reversed']);

        if ($reversed > 0) {
            return;
        }

        foreach ($formulaItems as $formulaItem) {
            $ticket->items()
                ->where('type', 'color_bar')
                ->where('status', 'active')
                ->where('name_snapshot', $formulaItem->variant->product->name)
                ->where('quantity', $formulaItem->quantity)
                ->oldest('id')
                ->limit(1)
                ->update(['status' => 'reversed']);
        }
    }

    private function ensureTicketCanReceiveFormula(Ticket $ticket): void
    {
        if ($ticket->status === 'paid') {
            throw ValidationException::withMessages([
                'ticket' => 'Recepción ya cobró este ticket. Su fórmula queda guardada y no puede modificarse.',
            ]);
        }

        if (! in_array($ticket->status, ['open', 'in_service'], true)) {
            throw ValidationException::withMessages(['ticket' => 'El ticket ya no admite cambios de Color Bar.']);
        }
    }
}
