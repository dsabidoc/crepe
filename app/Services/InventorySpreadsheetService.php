<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventorySpreadsheetService
{
    public const Headers = [...InventoryWorkbook::Headers, 'Costo', 'Precio Recepción', 'Precio Color Bar'];

    private const Fields = [
        'minimum_stock' => ['column' => 'K', 'label' => 'Mínimo'],
        'maximum_stock' => ['column' => 'L', 'label' => 'Máximo'],
        'cost' => ['column' => 'N', 'label' => 'Costo'],
        'sale_price' => ['column' => 'O', 'label' => 'Precio Recepción'],
        'color_bar_sale_price' => ['column' => 'P', 'label' => 'Precio Color Bar'],
    ];

    public function __construct(private InventoryWorkbook $workbook, private InventoryService $inventory, private SpreadsheetFile $files) {}

    public function export(string $format = 'xlsx', string $locationCode = 'ALM', bool $costs = true): string
    {
        $balances = $this->balances($locationCode)->with(['variant.product.category', 'variant.product.preferredSupplier'])->limit(10001)->get();
        if ($balances->count() > 10000) {
            throw ValidationException::withMessages(['file' => 'La exportación admite hasta 10,000 existencias.']);
        }
        $rows = $balances->sortBy(fn (InventoryBalance $balance): string => mb_strtolower(implode('|', [
            $balance->variant->product->category?->name ?? '', $balance->variant->product->name, $balance->variant->name, str_pad((string) $balance->id, 20, '0', STR_PAD_LEFT),
        ])))->map(function (InventoryBalance $balance) use ($locationCode, $costs): array {
            $variant = $balance->variant;
            $product = $variant->product;
            $quantity = $this->decimal($balance->available_quantity);

            $original = $this->catalogValues($variant, $balance);
            if (! $costs) {
                unset($original['cost']);
            }
            $payload = base64_encode(json_encode($original, JSON_THROW_ON_ERROR));
            $control = 'v3:'.$payload.':'.$this->signature($balance, $quantity, $locationCode, $payload);

            return [$balance->id, $variant->sku ?? '', $product->name, $variant->name, $product->brand ?? '', $product->category?->name ?? '', $product->preferredSupplier?->name ?? '', 'unidad', $this->whole($quantity), $this->whole($quantity), $this->whole($original['minimum_stock']), $original['maximum_stock'] === null ? '' : $this->whole($original['maximum_stock']), $control, $costs ? $this->money($variant->cost) : '', $variant->sale_price, $variant->color_bar_sale_price];
        })->values()->all();

        if ($format === 'csv') {
            return $this->files->writeCsv([self::Headers, ...$rows]);
        }

        return $this->workbook->write($rows, headers: self::Headers, instructions: [
            ['INVENTARIO · '.$this->locationName($locationCode).' · CREPÉ'], ['Descargado: '.now()->format('d/m/Y H:i')],
            ['Incluye todas las existencias activas de esta ubicación, sin filtros ni paginación.'],
            ['Edita las columnas verdes: Existencia nueva, Mínimo, Máximo, Costo, Precio Recepción y Precio Color Bar, según tus permisos.'],
            ['Existencia nueva es el conteo final de esta ubicación, no una entrada de mercancía.'],
            ['Mínimo y máximo corresponden solamente a esta ubicación. Precios y costo pertenecen al producto. El cobro por gramos conserva la tarifa de la categoría.'],
            ['Unidad indica productos o envases completos. Almacén, Recepción e insumos de Color Bar se cuentan en unidades.'],
            ['Puedes quitar filas; solo se actualizan las incluidas. No se agregan ni eliminan productos.'],
            ['Conserva encabezados, ID, Existencia original, Control y la hoja Inventario. No uses fórmulas.'],
            ['Cantidades enteras desde 0 hasta 99999999999. No captures gramos ni fracciones. 0 vacía la existencia.'],
            ['Selecciona la misma ubicación al subir. Si hubo movimientos, descarga de nuevo.'],
            ['Mínimo y máximo: unidades enteras. Máximo debe ser mayor o igual al mínimo; [BORRAR] quita el máximo.'],
            ['Dinero: hasta 2 decimales. Deja una celda de catálogo vacía para conservar su valor; 0 establece cero.'],
            ['Revisa y confirma los ajustes con un motivo. Cada movimiento queda en la bitácora.'],
        ], editableColumns: [9, 10, 11, 13, 14, 15], numericColumns: [0, 8, 9, 10, 11, 13, 14, 15], precisions: [8 => 0, 9 => 0, 10 => 0, 11 => 0, 13 => 2, 14 => 2, 15 => 2]);
    }

    /** @return array{changes: list<array<string, mixed>>, unchanged: int, total: int, errors?: list<string>, warnings: list<string>} */
    public function preview(string $path, string $format = 'xlsx', string $locationCode = 'ALM', bool $costs = true, bool $prices = true, bool $catalog = true): array
    {
        $rows = $this->files->read($path, $format, 'Inventario', count(self::Headers));
        $headers = $rows[1] ?? [];
        $legacy = ! isset($headers['N']);
        foreach ($legacy ? InventoryWorkbook::Headers : self::Headers as $index => $header) {
            if (($headers[chr(65 + $index)] ?? '') !== $header) {
                throw ValidationException::withMessages(['file' => 'Los encabezados no coinciden. Usa el Excel descargado sin cambiar sus columnas.']);
            }
        }
        unset($rows[1]);
        $rows = array_filter($rows, fn (array $row): bool => count(array_filter($row, fn (string $value): bool => $value !== '')) > 0);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'El Excel no contiene filas de inventario.']);
        }
        $balances = $this->balances($locationCode)->with('variant.product')->whereIn('id', array_column($rows, 'A'))->get()->keyBy('id');
        $errors = [];
        $seen = [];
        $changes = [];
        $unchanged = 0;
        $warnings = $legacy ? ['Este archivo anterior solo actualiza existencias. Descarga uno nuevo para ajustar precios, mínimos y máximos.'] : [];
        foreach ($rows as $number => $row) {
            $id = $row['A'] ?? '';
            $balance = ctype_digit($id) ? $balances->get((int) $id) : null;
            if ($balance === null) {
                $errors[] = "Fila {$number}: el ID no corresponde a un producto activo de {$this->locationName($locationCode)}.";

                continue;
            }
            if (isset($seen[$balance->id])) {
                $errors[] = "Fila {$number}: el producto está duplicado en el Excel.";

                continue;
            }
            $seen[$balance->id] = true;
            $rowContext = "Fila {$number} · {$balance->variant->product->name} · ".($balance->variant->sku ?? 'Sin SKU');
            $original = $row['I'] ?? '';
            $control = explode(':', $row['M'] ?? '');
            $payload = ! $legacy && count($control) === 3 && $control[0] === 'v3' ? $control[1] : null;
            if (! is_numeric($original) || ! is_finite((float) $original) || (! $legacy && $payload === null) || ! hash_equals($this->signature($balance, $this->decimal($original), $locationCode, $payload), $payload === null ? ($row['M'] ?? '') : $control[2])) {
                $errors[] = "Fila {$number}: cambió el ID, la existencia original o el control. Descarga un Excel nuevo.";

                continue;
            }
            $target = $row['J'] ?? '';
            if (! preg_match('/^[0-9]{1,11}(?:\\.0{1,3})?$/D', $target)) {
                $errors[] = $rowContext.": Existencia nueva debe ser un número entero entre 0 y 99999999999 unidades. Columna J, valor «{$target}».";

                continue;
            }
            $before = $this->decimal($original);
            $after = $this->decimal($target);
            $fields = [];
            try {
                if (! $legacy) {
                    $snapshot = json_decode(base64_decode($payload, true), true, flags: JSON_THROW_ON_ERROR);
                    $fields = $this->catalogChanges($row, $snapshot, $balance, $costs, $prices, $catalog);
                }
            } catch (ValidationException $exception) {
                $errors[] = $rowContext.': '.implode(' ', $exception->validator->errors()->all());

                continue;
            }
            if ($before !== $after && $this->decimal($balance->available_quantity) === $after) {
                $before = $after;
            }
            $values = $this->catalogValues($balance->variant, $balance);
            if (! isset($fields['minimum_stock']) && ! isset($fields['maximum_stock']) && $this->hasInconsistentLimits($values)) {
                $warnings[] = $rowContext.': Mínimo (K): '.$this->whole($values['minimum_stock']).'; Máximo (L): '.$this->whole($values['maximum_stock']).'. Son límites existentes que no modificaste. Se conservan y no bloquean los demás ajustes.';
            }
            if ($before === $after && $fields === []) {
                $unchanged++;

                continue;
            }
            if ($before !== $after && $this->decimal($balance->available_quantity) !== $before) {
                $errors[] = $rowContext.': la existencia cambió desde la descarga. Descarga un Excel nuevo y actualiza el conteo. Original (I): '.$this->whole($before).'; actual: '.$this->whole($balance->available_quantity).'; conteo del archivo (J): '.$this->whole($after).'.';

                continue;
            }
            $changes[] = [
                'id' => $balance->id, 'variant_id' => $balance->product_variant_id, 'row' => $number,
                'name' => $balance->variant->product->name.' · '.$balance->variant->name,
                'sku' => $balance->variant->sku ?? '', 'unit' => 'unidad',
                'variant_control' => $this->variantControl($balance),
                'before' => $before, 'after' => $after,
                'fields' => $fields,
                'diff' => [...($before !== $after ? [['field' => 'quantity', 'label' => 'Existencia (unidades)', 'before' => $this->whole($before), 'after' => $this->whole($after)]] : []), ...array_map(fn (string $field, array $values): array => ['field' => $field, 'label' => self::Fields[$field]['label'], 'before' => $this->display($field, $values['before']), 'after' => $this->display($field, $values['after'])], array_keys($fields), array_values($fields))],
            ];
        }
        if ($errors !== []) {
            return ['changes' => $changes, 'unchanged' => $unchanged, 'total' => count($rows), 'errors' => $errors, 'warnings' => $warnings];
        }

        return ['changes' => $changes, 'unchanged' => $unchanged, 'total' => count($rows), 'warnings' => $warnings];
    }

    /** @param list<array<string, mixed>> $changes */
    public function apply(array $changes, int $actorId, string $reason, string $locationCode = 'ALM', string $format = 'xlsx', bool $costs = true, bool $prices = true, bool $catalog = true): int
    {
        return DB::transaction(function () use ($changes, $actorId, $reason, $locationCode, $format, $costs, $prices, $catalog): int {
            $variantIds = array_column($changes, 'variant_id');
            $products = Product::query()->whereHas('variants', fn (Builder $query) => $query->whereIn('id', $variantIds))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variants = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $balances = $this->balances($locationCode)->whereIn('id', array_column($changes, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($changes as $change) {
                $balance = $balances->get($change['id']);
                $variant = $variants->get($change['variant_id']);
                if ($balance && $variant) {
                    $variant->setRelation('product', $products->get($variant->product_id));
                    $balance->setRelation('variant', $variant);
                }
                if ($balance === null || $variant === null || $variant->product?->status !== 'active' || $balance->product_variant_id !== $change['variant_id'] || ($change['before'] !== $change['after'] && $this->decimal($balance->available_quantity) !== $change['before']) || $this->variantControl($balance) !== $change['variant_control']) {
                    throw ValidationException::withMessages(['file' => "Fila {$change['row']}: la existencia o el producto cambió después de la revisión. Descarga un Excel nuevo y actualiza el conteo. No se aplicó ningún ajuste."]);
                }
                $values = $this->catalogValues($variant, $balance);
                foreach ($change['fields'] ?? [] as $field => $diff) {
                    $this->authorizeField($field, $costs, $prices, $catalog);
                    if ($values[$field] !== $diff['before']) {
                        throw ValidationException::withMessages(['file' => "Fila {$change['row']}: cambió ".self::Fields[$field]['label'].'. No se aplicó ningún ajuste. Descarga un archivo nuevo.']);
                    }
                    $values[$field] = $diff['after'];
                }
                if (isset($change['fields']['minimum_stock']) || isset($change['fields']['maximum_stock'])) {
                    $this->validateLimits($values);
                }
            }
            $warehouse = InventoryLocation::query()->where('code', $locationCode)->firstOrFail();
            $audit = AuditLog::create([
                'user_id' => $actorId, 'action' => 'inventory.imported',
                'subject_type' => InventoryLocation::class, 'subject_id' => $warehouse->id,
                'before' => ['items' => array_map(fn (array $change): array => ['balance_id' => $change['id'], 'quantity' => $balances[$change['id']]->available_quantity, 'catalog' => array_map(fn (array $diff): ?string => $diff['before'], $change['fields'] ?? [])], $changes)],
                'after' => ['items' => array_map(fn (array $change): array => ['balance_id' => $change['id'], 'quantity' => $change['before'] !== $change['after'] ? $change['after'] : $balances[$change['id']]->available_quantity, 'catalog' => array_map(fn (array $diff): ?string => $diff['after'], $change['fields'] ?? [])], $changes)],
                'reason' => mb_substr($reason, 0, 255), 'correlation_id' => (string) Str::uuid(),
            ]);
            foreach ($changes as $change) {
                if (! empty($change['fields'])) {
                    $updates = array_map(fn (array $diff): ?string => $diff['after'], $change['fields']);
                    $limitUpdates = array_intersect_key($updates, array_flip(['minimum_stock', 'maximum_stock']));
                    if ($limitUpdates !== []) {
                        $limits = [...InventoryLimitService::values($balances[$change['id']], $variants[$change['variant_id']]), ...$limitUpdates];
                        app(InventoryLimitService::class)->update($variants[$change['variant_id']], $warehouse, $limits);
                    }
                    $variants[$change['variant_id']]->update(array_diff_key($updates, array_flip(['minimum_stock', 'maximum_stock'])));
                }
                if ($change['before'] !== $change['after']) {
                    $this->inventory->move($change['variant_id'], $warehouse->id, round((float) $change['after'] - (float) $change['before'], 3), 'adjustment', $actorId, AuditLog::class, $audit->id, mb_substr('Importación '.($format === 'csv' ? 'CSV' : 'Excel').': '.$reason, 0, 255));
                }
            }

            return count($changes);
        });
    }

    private function balances(string $locationCode): Builder
    {
        return InventoryBalance::query()->whereHas('location', fn (Builder $query) => $query->where('code', $locationCode))
            ->whereHas('variant.product', fn (Builder $query) => $query->where('status', 'active'));
    }

    private function decimal(string|float|int $quantity): string
    {
        return number_format((float) $quantity, 3, '.', '');
    }

    /** @return array<string, ?string> */
    private function catalogValues(ProductVariant $variant, InventoryBalance $balance): array
    {
        return [...$variant->only(array_keys(self::Fields)), ...InventoryLimitService::values($balance, $variant)];
    }

    /** @param array<string, string> $row
     * @param  array<string, ?string>  $snapshot
     * @return array<string, array{before: ?string, after: ?string}>
     */
    private function catalogChanges(array $row, array $snapshot, InventoryBalance $balance, bool $costs, bool $prices, bool $catalog): array
    {
        $changes = [];
        $values = $this->catalogValues($balance->variant, $balance);
        foreach (self::Fields as $field => $definition) {
            $input = $row[$definition['column']] ?? '';
            if ($input === '') {
                continue;
            }
            $isStock = in_array($field, ['minimum_stock', 'maximum_stock'], true);
            if ($field === 'maximum_stock' && $input === '[BORRAR]') {
                $after = null;
            } else {
                $number = $isStock ? $input : $this->normalizeMoneyInput($input);
                $pattern = $isStock ? '/^[0-9]{1,9}(?:\.0{1,3})?$/D' : ($field === 'cost' ? '/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D' : '/^[0-9]{1,10}(?:\.[0-9]{1,2})?$/D');
                if (! preg_match($pattern, $number)) {
                    throw ValidationException::withMessages(['file' => $definition['label'].($isStock ? ' debe ser un número entero entre 0 y 999999999 unidades.' : ' debe ser un importe positivo o cero, con hasta 2 decimales.')." Columna {$definition['column']}, valor «{$input}»."]);
                }
                $after = number_format((float) $number, $isStock ? 3 : ($field === 'cost' ? 4 : 2), '.', '');
            }
            if (! array_key_exists($field, $snapshot)) {
                throw ValidationException::withMessages(['file' => 'El archivo no incluye autorización para modificar costos. Descarga uno nuevo con los permisos correspondientes.']);
            }
            $before = $snapshot[$field];
            if ($this->display($field, $before) === $this->display($field, $after) || $this->display($field, $values[$field]) === $this->display($field, $after)) {
                continue;
            }
            $this->authorizeField($field, $costs, $prices, $catalog);
            if ($values[$field] !== $before) {
                throw ValidationException::withMessages(['file' => 'Cambió '.$definition['label'].' desde la descarga. Descarga un archivo nuevo.']);
            }
            $changes[$field] = compact('before', 'after');
            $values[$field] = $after;
        }
        if (isset($changes['minimum_stock']) || isset($changes['maximum_stock'])) {
            $this->validateLimits($values);
        }

        return $changes;
    }

    private function authorizeField(string $field, bool $costs, bool $prices, bool $catalog): void
    {
        $allowed = match ($field) {
            'cost' => $costs,
            'sale_price', 'color_bar_sale_price' => $prices,
            default => $catalog,
        };
        if (! $allowed) {
            throw ValidationException::withMessages(['file' => 'No tienes permiso para modificar '.self::Fields[$field]['label'].'. No se aplicó ningún ajuste.']);
        }
    }

    /** @param array<string, ?string> $values */
    private function validateLimits(array $values): void
    {
        if ($this->hasInconsistentLimits($values)) {
            throw ValidationException::withMessages(['file' => 'Máximo debe ser mayor o igual al mínimo. No se aplicó ningún ajuste. Mínimo (K): '.$this->whole($values['minimum_stock']).'; Máximo (L): '.$this->whole($values['maximum_stock']).'.']);
        }
    }

    /** @param array<string, ?string> $values */
    private function hasInconsistentLimits(array $values): bool
    {
        return $values['maximum_stock'] !== null && (float) $values['maximum_stock'] < (float) $values['minimum_stock'];
    }

    /** Normalizes only differences within four floating-point rounding units of a two-decimal amount. */
    private function normalizeMoneyInput(string $input): string
    {
        if (! preg_match('/^[0-9]{1,10}\.[0-9]{3,17}$/D', $input)) {
            return $input;
        }
        $number = (float) $input;
        $rounded = round($number, 2);
        $tolerance = max(abs($number), 1) * PHP_FLOAT_EPSILON * 4;

        return abs($number - $rounded) <= $tolerance ? $this->money($rounded) : $input;
    }

    private function whole(string $value): string
    {
        return number_format((float) $value, 0, '.', '');
    }

    private function money(string|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function display(string $field, ?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return in_array($field, ['minimum_stock', 'maximum_stock'], true) ? $this->whole($value) : $this->money($value);
    }

    private function signature(InventoryBalance $balance, string $quantity, string $locationCode, ?string $payload = null): string
    {
        if ($payload !== null) {
            return hash_hmac('sha256', "inventory-v3|{$locationCode}|{$balance->id}|{$quantity}|{$this->variantControl($balance)}|{$payload}", config('app.key'));
        }

        return hash_hmac('sha256', "inventory-v2|{$locationCode}|{$balance->id}|{$quantity}|{$this->variantControl($balance)}", config('app.key'));
    }

    private function variantControl(InventoryBalance $balance): string
    {
        return implode('|', [$balance->product_variant_id, $balance->variant->base_unit, $balance->variant->content_quantity, (int) $balance->variant->product->isColorBarSupply()]);
    }

    public function locationName(string $code): string
    {
        return ['ALM' => 'Almacén', 'REC' => 'Recepción', 'CB' => 'Color Bar'][$code];
    }
}
