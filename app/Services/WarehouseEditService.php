<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseEditService
{
    public function __construct(private InventoryWorkflowService $workflow, private InventoryLimitService $limits) {}

    public static function canEdit(User $user, ProductVariant $variant, string $field): bool
    {
        return match ($field) {
            'quantity', 'minimum_stock', 'maximum_stock' => $user->hasRole('Administrador'),
            'cost' => $user->can('products.costs.view') && $user->can('products.catalog.manage'),
            'sale_price' => $user->can('products.prices.manage') || ($variant->product->is_reception_usable && $user->can('products.reception-prices.manage')),
            default => false,
        };
    }

    /** @param list<array{variant_id: int, field: string, location_code?: ?string, before: mixed, value: mixed}> $changes */
    public function save(array $changes, User $actor, string $key, ?string $reason): void
    {
        DB::transaction(function () use ($changes, $actor, $key, $reason): void {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$changes, $reason], JSON_THROW_ON_ERROR));
            $existing = AuditLog::query()->where('user_id', $actor->id)->where('action', 'inventory.inline.saved')->where('correlation_id', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->after['payload_hash'], $hash), 409, 'Esta confirmación ya se utilizó para otros cambios.');

                return;
            }
            $ids = array_column($changes, 'variant_id');
            $balanceVariantIds = array_column(array_filter($changes, fn (array $change): bool => ! in_array($change['field'], ['cost', 'sale_price'], true)), 'variant_id');
            $locations = InventoryLocation::query()->whereIn('code', ['ALM', 'REC', 'CB'])->get()->keyBy('code');
            $balances = InventoryBalance::query()->whereIn('product_variant_id', $balanceVariantIds)->whereIn('inventory_location_id', $locations->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            $variants = ProductVariant::query()->with('product')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $seen = [];
            $stockChanges = [];
            $priceUpdates = [];
            $limitUpdates = [];
            $auditChanges = [];
            foreach ($changes as $change) {
                $variant = $variants->get($change['variant_id']);
                abort_unless($variant && $variant->product->status === 'active', 422, 'El producto ya no está disponible.');
                $field = $change['field'];
                abort_unless(self::canEdit($actor, $variant, $field), 403, 'No tienes permiso para editar este campo.');
                $code = $change['location_code'] ?? null;
                $isPrice = in_array($field, ['cost', 'sale_price'], true);
                abort_unless($isPrice ? $code === null : ($field === 'quantity' ? in_array($code, ['ALM', 'REC', 'CB'], true) : $code === 'ALM'), 422, 'Los mínimos y máximos sólo se modifican en Almacén.');
                $identity = $variant->id.':'.$field.':'.$code;
                if (isset($seen[$identity])) {
                    throw ValidationException::withMessages(['changes' => 'Hay campos repetidos en los cambios pendientes.']);
                }
                $seen[$identity] = true;
                $balance = $isPrice ? null : $balances->first(fn ($balance): bool => $balance->product_variant_id === $variant->id && $balance->inventory_location_id === $locations->get($code)?->id);
                abort_unless($isPrice || $balance, 422, 'Actualiza la pantalla para cargar la existencia.');
                $limits = $balance ? InventoryLimitService::values($balance, $variant) : [];
                $current = $isPrice ? $variant->$field : ($field === 'quantity' ? $balance->available_quantity : $limits[$field]);
                if (($current === null) !== ($change['before'] === null) || ($current !== null && (float) $current !== (float) $change['before'])) {
                    throw ValidationException::withMessages(['changes' => $variant->product->name.': el dato que editaste cambió mientras trabajabas. No se guardó ningún cambio. Actualiza la pantalla y revisa de nuevo.']);
                }
                $after = $change['value'];
                $nullable = $field === 'maximum_stock';
                if ($after === null && ! $nullable || $after !== null && (! is_numeric($after) || (float) $after < 0 || (float) $after > ($isPrice ? 99999999.99 : 999999999) || (! $isPrice && (float) $after !== floor((float) $after)) || ($isPrice && ! preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $after)))) {
                    throw ValidationException::withMessages(['changes' => 'Usa unidades enteras desde cero e importes con hasta dos decimales.']);
                }
                if (($current === null && $after === null) || ($current !== null && $after !== null && (float) $current === (float) $after)) {
                    continue;
                }
                $auditChanges[] = ['variant_id' => $variant->id, 'field' => $field, 'location_code' => $code, 'before' => $current, 'after' => $after];
                if ($isPrice) {
                    $priceUpdates[$variant->id][$field] = $after;
                } elseif ($field === 'quantity') {
                    $stockChanges[$balance->id] = ['id' => $balance->id, 'before' => $balance->available_quantity, 'after' => (int) $after, 'minimum_stock' => (int) $limits['minimum_stock'], 'maximum_stock' => $limits['maximum_stock'] === null ? null : (int) $limits['maximum_stock'], 'limits_before' => $limits, 'name' => $variant->product->name, 'location' => $locations[$code]->name];
                } else {
                    $limitUpdates[$variant->id][$field] = $after;
                }
            }
            if ($auditChanges === []) {
                throw ValidationException::withMessages(['changes' => 'No hay cambios pendientes por guardar.']);
            }
            if ($stockChanges !== [] && mb_strlen(trim($reason ?? '')) < 5) {
                throw ValidationException::withMessages(['reason' => 'Escribe un motivo de al menos 5 caracteres para ajustar existencias.']);
            }
            foreach ($limitUpdates as $variantId => $updates) {
                $balance = $balances->first(fn ($balance): bool => $balance->product_variant_id === $variantId && $balance->inventory_location_id === $locations['ALM']->id);
                $values = [...InventoryLimitService::values($balance, $variants[$variantId]), ...$updates];
                if ($values['maximum_stock'] !== null && (float) $values['maximum_stock'] < (float) $values['minimum_stock']) {
                    throw ValidationException::withMessages(['changes' => $variants[$variantId]->product->name.': el máximo debe ser mayor o igual al mínimo de Almacén.']);
                }
            }
            if ($stockChanges !== []) {
                $this->workflow->adjust(array_values($stockChanges), $actor, trim($reason), $key);
            }
            foreach ($limitUpdates as $variantId => $updates) {
                $balance = $balances->first(fn ($balance): bool => $balance->product_variant_id === $variantId && $balance->inventory_location_id === $locations['ALM']->id);
                $this->limits->update($variants[$variantId], $locations['ALM'], [...InventoryLimitService::values($balance, $variants[$variantId]), ...$updates]);
            }
            foreach ($priceUpdates as $variantId => $updates) {
                $variants[$variantId]->update($updates);
            }
            AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'inventory.inline.saved', 'subject_type' => InventoryLocation::class, 'subject_id' => $locations['ALM']->id, 'before' => ['changes' => $auditChanges], 'after' => ['payload_hash' => $hash, 'changes' => $auditChanges], 'reason' => $reason ?: 'Edición directa de catálogo y límites de Almacén.', 'correlation_id' => $key]);
        }, 3);
    }
}
