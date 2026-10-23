<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryRequestService
{
    public function __construct(private WorkflowNotificationService $notifications) {}

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, array{product_variant_id: int, requested_units: ?int, requested_quantity: ?float}>  $items
     */
    public function create(InventoryLocation $source, User $actor, Collection $variants, array $items, ?string $notes): InventoryRequest
    {
        return DB::transaction(function () use ($items, $notes, $actor, $source, $variants): InventoryRequest {
            $inventoryRequest = InventoryRequest::create([
                'code' => 'TEMP-'.str()->uuid(),
                'source_location_id' => $source->id,
                'status' => 'pending',
                'requested_by' => $actor->id,
                'notes' => $notes,
            ]);
            $inventoryRequest->update(['code' => 'SOL-'.str_pad((string) $inventoryRequest->id, 6, '0', STR_PAD_LEFT)]);

            foreach ($items as $item) {
                $variant = $variants[(int) $item['product_variant_id']];
                $inventoryRequest->items()->create([
                    'product_variant_id' => $variant->id,
                    'requested_quantity' => $item['requested_units'] ?? $item['requested_quantity'],
                    'requested_units' => $item['requested_units'] ?? (int) $item['requested_quantity'],
                    'unit' => 'unidad',
                ]);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'inventory.request.created',
                'subject_type' => InventoryRequest::class,
                'subject_id' => $inventoryRequest->id,
                'after' => ['code' => $inventoryRequest->code, 'source' => $source->code, 'status' => 'pending'],
                'reason' => 'Solicitud de inventario',
            ]);

            $this->notifications->inventoryRequested($inventoryRequest);

            return $inventoryRequest;
        });
    }
}
