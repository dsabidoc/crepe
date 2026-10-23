<?php

namespace App\Services;

use App\Models\ColorBarDepletionBatch;
use App\Models\InventoryBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ColorBarDepletionService
{
    public function __construct(private InventoryService $inventory, private InventoryRequestService $requests) {}

    /** @param array{request_key: string, notes?: ?string, items: array<int, array{balance_id: int, expected_quantity: float, units?: ?int, empty_stock?: bool, requested_units?: ?int}>} $data */
    public function record(array $data, User $actor): ColorBarDepletionBatch
    {
        $items = collect($data['items'])->map(fn (array $item): array => [
            'balance_id' => (int) $item['balance_id'],
            'expected_quantity' => round((float) $item['expected_quantity'], 3),
            'units' => (int) ($item['units'] ?? 0),
            'empty_stock' => (bool) ($item['empty_stock'] ?? false),
            ...((int) ($item['requested_units'] ?? 0) > 0 ? ['requested_units' => (int) $item['requested_units']] : []),
        ])->sortBy('balance_id')->values();
        $notes = trim($data['notes'] ?? '');
        $hash = hash('sha256', json_encode([$items->all(), $notes], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $actor, $items, $notes, $hash): ColorBarDepletionBatch {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = ColorBarDepletionBatch::query()->where('created_by', $actor->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages(['items' => 'Este lote ya fue registrado. Abre una nueva baja para registrar otros insumos.']);
                }

                return $existing;
            }

            $balances = InventoryBalance::query()->with(['variant.product', 'location'])
                ->whereIn('id', $items->pluck('balance_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $quantities = [];
            foreach ($items as $item) {
                $balance = $balances->get($item['balance_id']);
                if (! $balance || $balance->location->code !== 'CB' || ! $balance->variant->product->isColorBarSupply() || $balance->variant->product->status !== 'active') {
                    throw ValidationException::withMessages(['items' => 'Selecciona únicamente insumos activos del inventario de Color Bar.']);
                }
                if (round((float) $balance->available_quantity, 3) !== $item['expected_quantity']) {
                    throw ValidationException::withMessages(['items' => 'La existencia de '.$balance->variant->product->name.' cambió. Revisa el saldo actualizado y confirma nuevamente; no se aplicó ninguna baja.']);
                }
                $content = (float) $balance->variant->content_quantity;
                $quantity = $item['empty_stock'] ? (float) $balance->available_quantity : round($item['units'] * $content, 3);
                if ($quantity <= 0 || (! $item['empty_stock'] && ($item['units'] < 1 || $content <= 0)) || $quantity > (float) $balance->available_quantity) {
                    throw ValidationException::withMessages(['items' => 'No hay suficientes existencias de '.$balance->variant->product->name.'. Revisa las cantidades; no se aplicó ninguna baja.']);
                }
                if (($item['requested_units'] ?? 0) > 0 && ($content <= 0 || round($item['requested_units'] * $content, 3) > 99999999999.999)) {
                    throw ValidationException::withMessages(['items' => 'Revisa la cantidad solicitada y el contenido por envase de '.$balance->variant->product->name.'. No se aplicó ninguna baja.']);
                }
                $quantities[$balance->id] = $quantity;
            }

            $batch = ColorBarDepletionBatch::query()->create([
                'created_by' => $actor->id, 'request_key' => $data['request_key'], 'request_hash' => $hash, 'notes' => $notes ?: null,
            ]);
            foreach ($items as $item) {
                $balance = $balances->get($item['balance_id']);
                $reason = 'Baja #'.$batch->id.': '.($item['empty_stock'] ? 'existencia agotada' : $item['units'].' envases agotados').'.';
                if ($notes !== '') {
                    $reason .= ' '.$notes;
                }
                $this->inventory->move($balance->product_variant_id, $balance->inventory_location_id, -$quantities[$balance->id], 'color_bar_depletion', $actor->id, ColorBarDepletionBatch::class, $batch->id, $reason);
            }

            $requestedItems = $items->filter(fn (array $item): bool => ($item['requested_units'] ?? 0) > 0)
                ->map(fn (array $item): array => [
                    'product_variant_id' => $balances->get($item['balance_id'])->product_variant_id,
                    'requested_units' => $item['requested_units'],
                    'requested_quantity' => null,
                ])->values()->all();
            if ($requestedItems !== []) {
                $request = $this->requests->create($balances->first()->location, $actor, $balances->pluck('variant')->keyBy('id'), $requestedItems, 'Reposición de baja #'.$batch->id.($notes !== '' ? '. '.$notes : ''));
                $batch->update(['inventory_request_id' => $request->id]);
                $batch->setRelation('inventoryRequest', $request);
            }

            return $batch;
        }, 3);
    }
}
