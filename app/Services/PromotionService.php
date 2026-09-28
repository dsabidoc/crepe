<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\TicketAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    public function __construct(private InventoryService $inventory) {}

    public function apply(Ticket $ticket, Promotion $promotion, int $actorId): void
    {
        DB::transaction(function () use ($ticket, $promotion, $actorId): void {
            $ticket = Ticket::query()->with(['items', 'adjustments'])->lockForUpdate()->findOrFail($ticket->id);
            $promotion = Promotion::query()->lockForUpdate()->findOrFail($promotion->id);

            if ($ticket->status === 'paid' || ! $promotion->isAvailable()) {
                throw ValidationException::withMessages(['promotion' => 'Esta promoción no está disponible.']);
            }
            if ($ticket->adjustments->contains(fn ($adjustment): bool => ($adjustment->metadata['promotion_id'] ?? null) === $promotion->id)) {
                throw ValidationException::withMessages(['promotion' => 'Esta promoción ya fue aplicada al ticket.']);
            }
            if ($ticket->adjustments->contains(fn ($adjustment): bool => $adjustment->type === 'discount')) {
                throw ValidationException::withMessages(['promotion' => 'Este ticket ya tiene una promoción aplicada.']);
            }
            if ($promotion->one_per_customer && $ticket->customer_id && TicketAdjustment::query()->where('metadata->promotion_id', $promotion->id)->whereHas('ticket', fn ($query) => $query->where('customer_id', $ticket->customer_id))->exists()) {
                throw ValidationException::withMessages(['promotion' => 'Esta promoción ya fue utilizada por esta clienta.']);
            }

            if ($promotion->type === 'buy_x_get_y') {
                $this->addReward($ticket, $promotion, $actorId);

                return;
            }

            $eligibleTotal = $this->eligibleTotal($ticket, $promotion);
            if ($promotion->minimum_amount !== null && $eligibleTotal < (float) $promotion->minimum_amount) {
                throw ValidationException::withMessages(['promotion' => 'El ticket no alcanza el mínimo requerido para esta promoción.']);
            }

            $discount = $promotion->value_type === 'percentage'
                ? $eligibleTotal * ((float) $promotion->value / 100)
                : (float) $promotion->value;
            $discount = min(max(0, round($discount, 2)), max(0, $eligibleTotal));
            if ($discount <= 0) {
                throw ValidationException::withMessages(['promotion' => 'La promoción no aplica a los conceptos de este ticket.']);
            }

            TicketAdjustment::create([
                'ticket_id' => $ticket->id, 'type' => 'discount', 'amount' => -$discount,
                'reason' => $promotion->name, 'created_by' => $actorId,
                'metadata' => ['promotion_id' => $promotion->id, 'promotion_code' => $promotion->code, 'customer_id' => $ticket->customer_id],
            ]);
            $promotion->increment('usage_count');
        });
    }

    private function eligibleTotal(Ticket $ticket, Promotion $promotion): float
    {
        $items = $ticket->items->where('status', 'active');
        if ($promotion->type === 'order') {
            return (float) $items->sum('line_total');
        }

        $target = $promotion->target ?? [];

        return (float) $items->filter(function ($item) use ($target): bool {
            $metadata = $item->metadata ?? [];

            return ($item->type === 'product' && in_array((int) ($metadata['product_id'] ?? 0), array_map('intval', $target['product_ids'] ?? []), true))
                || ($item->type === 'service' && in_array((int) ($metadata['salon_service_id'] ?? 0), array_map('intval', $target['service_ids'] ?? []), true));
        })->sum('line_total');
    }

    private function addReward(Ticket $ticket, Promotion $promotion, int $actorId): void
    {
        $target = $promotion->target ?? [];
        $qualifyingQuantity = (float) $ticket->items->where('status', 'active')->filter(function ($item) use ($target): bool {
            $metadata = $item->metadata ?? [];

            return in_array((int) ($metadata['product_id'] ?? 0), array_map('intval', $target['product_ids'] ?? []), true)
                || in_array((int) ($metadata['salon_service_id'] ?? 0), array_map('intval', $target['service_ids'] ?? []), true);
        })->sum('quantity');
        $buyQuantity = max(1, (int) $promotion->buy_quantity);
        $rewardUnits = intdiv((int) floor($qualifyingQuantity), $buyQuantity) * max(1, (int) $promotion->reward_quantity);
        if ($rewardUnits < 1) {
            throw ValidationException::withMessages(['promotion' => 'Aún no se cumple la cantidad necesaria para esta promoción.']);
        }

        $reward = $promotion->reward ?? [];
        $rewardType = $reward['type'] ?? null;
        $rewardId = (int) ($reward['id'] ?? 0);
        if ($rewardType === 'product') {
            $variant = ProductVariant::query()->with('product')->lockForUpdate()->findOrFail($rewardId);
            $location = InventoryLocation::query()->where('code', 'REC')->firstOrFail();
            $this->inventory->move($variant->id, $location->id, -$rewardUnits, 'promotion_reward', $actorId, Ticket::class, $ticket->id, "Regalo de promoción {$promotion->name}");
            $ticket->items()->create(['type' => 'promotion_reward', 'name_snapshot' => $variant->product->name.' (regalo)', 'quantity' => $rewardUnits, 'unit' => $variant->base_unit, 'unit_price' => 0, 'line_total' => 0, 'cost_snapshot' => $variant->cost, 'status' => 'active', 'metadata' => ['promotion_id' => $promotion->id, 'product_variant_id' => $variant->id], 'added_by' => $actorId]);
        } elseif ($rewardType === 'service') {
            $service = SalonService::query()->findOrFail($rewardId);
            $ticket->items()->create(['type' => 'promotion_reward', 'name_snapshot' => $service->name.' (regalo)', 'quantity' => $rewardUnits, 'unit' => 'servicio', 'unit_price' => 0, 'line_total' => 0, 'cost_snapshot' => $service->base_cost, 'status' => 'active', 'metadata' => ['promotion_id' => $promotion->id, 'salon_service_id' => $service->id], 'added_by' => $actorId]);
        } else {
            throw ValidationException::withMessages(['promotion' => 'La recompensa configurada no es válida.']);
        }
        $promotion->increment('usage_count');
    }
}
