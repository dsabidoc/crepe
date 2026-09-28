<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ColorFormula;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ColorFormulaService
{
    public function __construct(private InventoryService $inventory) {}

    public function record(Ticket $ticket, InventoryLocation $location, array $items, ?string $notes, int $actorId): ColorFormula
    {
        return DB::transaction(function () use ($ticket, $location, $items, $notes, $actorId): ColorFormula {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->ensureTicketCanReceiveFormula($ticket);

            return $this->createFormula($ticket, $location, $items, $notes, $actorId);
        });
    }

    public function correct(ColorFormula $formula, InventoryLocation $location, array $items, ?string $notes, int $actorId): ColorFormula
    {
        return DB::transaction(function () use ($formula, $location, $items, $notes, $actorId): ColorFormula {
            $formula = ColorFormula::query()->lockForUpdate()->findOrFail($formula->id);
            if ($formula->inventory_location_id !== $location->id) {
                throw ValidationException::withMessages(['formula' => 'Esta fórmula no pertenece a Color Bar.']);
            }
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($formula->ticket_id);
            $this->ensureTicketCanReceiveFormula($ticket);

            if ($formula->status !== 'confirmed') {
                throw ValidationException::withMessages(['formula' => 'Esta fórmula ya fue corregida y no puede editarse otra vez.']);
            }

            $formulaItems = $formula->items()->with('variant.product')->get();
            foreach ($formulaItems as $formulaItem) {
                $this->inventory->move(
                    $formulaItem->product_variant_id,
                    $location->id,
                    (float) $formulaItem->quantity,
                    'color_correction_reversal',
                    $actorId,
                    ColorFormula::class,
                    $formula->id,
                    'Reverso por corrección de fórmula'
                );
            }

            $this->reverseTicketItems($ticket, $formula, $formulaItems);
            $formula->update(['status' => 'corrected', 'corrected_by' => $actorId, 'corrected_at' => now()]);

            $correctedFormula = $this->createFormula($ticket, $location, $items, $notes, $actorId, $formula->id);

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

    private function createFormula(Ticket $ticket, InventoryLocation $location, array $items, ?string $notes, int $actorId, ?int $supersedesFormulaId = null): ColorFormula
    {
        $preparedItems = $this->prepareItems($items);
        $formula = ColorFormula::create([
            'ticket_id' => $ticket->id,
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
                'unit' => $item['variant']->base_unit,
                'notes' => $item['notes'],
                'cost_snapshot' => $item['variant']->cost,
                'sale_price' => $item['variant']->sale_price,
            ]);

            $this->inventory->move(
                $item['variant']->id,
                $location->id,
                -$item['quantity'],
                'color_consumption',
                $actorId,
                ColorFormula::class,
                $formula->id,
                'Fórmula confirmada'
            );

            $ticket->items()->create([
                'type' => 'color_bar',
                'name_snapshot' => $item['variant']->product->name,
                'quantity' => $item['quantity'],
                'unit' => $item['variant']->base_unit,
                'unit_price' => $item['variant']->sale_price,
                'line_total' => $item['variant']->sale_price * $item['quantity'],
                'cost_snapshot' => $item['variant']->cost,
                'status' => 'active',
                'metadata' => [
                    'color_formula_id' => $formula->id,
                    'color_formula_item_id' => $formulaItem->id,
                    'captured_quantity' => $item['display_quantity'],
                    'captured_unit' => $item['display_unit'],
                ],
                'added_by' => $actorId,
            ]);
        }

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'color_formula.confirmed',
            'subject_type' => ColorFormula::class,
            'subject_id' => $formula->id,
            'after' => ['ticket_id' => $ticket->id, 'items' => collect($preparedItems)->map(fn ($item) => ['variant_id' => $item['variant']->id, 'quantity' => $item['quantity'], 'unit' => $item['variant']->base_unit])->all()],
            'reason' => 'Fórmula confirmada desde Color Bar',
        ]);

        return $formula;
    }

    private function prepareItems(array $items): array
    {
        $preparedItems = [];

        foreach ($items as $item) {
            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($item['product_variant_id']);
            if ($variant->product->status !== 'active' || ! $variant->product->is_color_bar_usable) {
                throw ValidationException::withMessages(['items' => 'Sólo puedes usar productos activos de Color Bar.']);
            }

            $displayUnit = mb_strtolower(trim($item['unit']));
            $displayQuantity = (float) $item['quantity'];
            $preparedItems[] = [
                'variant' => $variant,
                'quantity' => $this->convertToBaseUnit($variant->base_unit, $displayUnit, $displayQuantity),
                'display_quantity' => $displayQuantity,
                'display_unit' => $displayUnit,
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $preparedItems;
    }

    private function convertToBaseUnit(string $baseUnit, string $displayUnit, float $quantity): float
    {
        $baseUnit = mb_strtolower(trim($baseUnit));
        $unit = $displayUnit === 'lt' ? 'l' : $displayUnit;

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor a cero.']);
        }

        if ($baseUnit === 'g' && $unit === 'g') {
            return $quantity;
        }

        if ($baseUnit === 'ml' && $unit === 'ml') {
            return $quantity;
        }

        if ($baseUnit === 'ml' && $unit === 'l') {
            return $quantity * 1000;
        }

        if ($baseUnit === 'l' && $unit === 'l') {
            return $quantity;
        }

        if ($baseUnit === 'l' && $unit === 'ml') {
            return $quantity / 1000;
        }

        throw ValidationException::withMessages(['items' => 'La unidad seleccionada no corresponde con la presentación del producto.']);
    }

    private function reverseTicketItems(Ticket $ticket, ColorFormula $formula, Collection $formulaItems): void
    {
        $reversed = $ticket->items()
            ->where('type', 'color_bar')
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
        if (! in_array($ticket->status, ['open', 'in_service'], true)) {
            throw ValidationException::withMessages(['ticket' => 'El ticket ya no admite cambios de Color Bar.']);
        }
    }
}
