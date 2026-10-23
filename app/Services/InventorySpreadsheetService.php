<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventorySpreadsheetService
{
    public function __construct(private InventoryWorkbook $workbook, private InventoryService $inventory, private SpreadsheetFile $files) {}

    public function export(string $format = 'xlsx', string $locationCode = 'ALM'): string
    {
        $balances = $this->balances($locationCode)->with(['variant.product.category', 'variant.product.preferredSupplier'])->limit(10001)->get();
        if ($balances->count() > 10000) {
            throw ValidationException::withMessages(['file' => 'La exportación admite hasta 10,000 existencias.']);
        }
        $rows = $balances->sortBy(fn (InventoryBalance $balance): string => mb_strtolower(implode('|', [
            $balance->variant->product->category?->name ?? '', $balance->variant->product->name, $balance->variant->name, str_pad((string) $balance->id, 20, '0', STR_PAD_LEFT),
        ])))->map(function (InventoryBalance $balance) use ($locationCode): array {
            $variant = $balance->variant;
            $product = $variant->product;
            $quantity = $this->decimal($balance->available_quantity);

            return [$balance->id, $variant->sku ?? '', $product->name, $variant->name, $product->brand ?? '', $product->category?->name ?? '', $product->preferredSupplier?->name ?? '', 'unidad', $quantity, $quantity, $variant->minimum_stock, $variant->maximum_stock ?? '0', $this->signature($balance, $quantity, $locationCode)];
        })->values()->all();

        if ($format === 'csv') {
            return $this->files->writeCsv([InventoryWorkbook::Headers, ...$rows]);
        }

        return $this->workbook->write($rows, instructions: [
            ['INVENTARIO · '.$this->locationName($locationCode).' · CREPÉ'], ['Descargado: '.now()->format('d/m/Y H:i')],
            ['Incluye todas las existencias activas de esta ubicación, sin filtros ni paginación.'],
            ['Edita únicamente Existencia nueva. Es el conteo final, no una entrada de mercancía.'],
            ['Unidad indica productos o envases completos. Almacén, Recepción e insumos de Color Bar se cuentan en unidades.'],
            ['Puedes quitar filas; solo se actualizan las incluidas. No se agregan ni eliminan productos.'],
            ['Conserva encabezados, ID, Existencia original, Control y la hoja Inventario. No uses fórmulas.'],
            ['Cantidades enteras desde 0 hasta 99999999999. No captures gramos ni fracciones. 0 vacía la existencia.'],
            ['Selecciona la misma ubicación al subir. Si hubo movimientos, descarga de nuevo.'],
            ['Los datos de catálogo y mínimos/máximos son informativos. Se editan desde Productos.'],
            ['Revisa y confirma los ajustes con un motivo. Cada movimiento queda en la bitácora.'],
        ], precisions: [8 => 0, 9 => 0, 10 => 0, 11 => 0]);
    }

    /** @return array{changes: list<array{id: int, variant_id: int, row: int, name: string, sku: string, unit: string, variant_control: string, before: string, after: string}>, unchanged: int, total: int, errors?: list<string>} */
    public function preview(string $path, string $format = 'xlsx', string $locationCode = 'ALM'): array
    {
        $rows = $this->files->read($path, $format, 'Inventario', count(InventoryWorkbook::Headers));
        $headers = $rows[1] ?? [];
        foreach (InventoryWorkbook::Headers as $index => $header) {
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
            $original = $row['I'] ?? '';
            if (! is_numeric($original) || ! is_finite((float) $original) || ! hash_equals($this->signature($balance, $this->decimal($original), $locationCode), $row['M'] ?? '')) {
                $errors[] = "Fila {$number}: cambió el ID, la existencia original o el control. Descarga un Excel nuevo.";

                continue;
            }
            $target = $row['J'] ?? '';
            if (! preg_match('/^[0-9]{1,11}(?:\\.0{1,3})?$/D', $target)) {
                $errors[] = "Fila {$number}: Existencia nueva debe ser un número entero entre 0 y 99999999999 unidades.";

                continue;
            }
            $before = $this->decimal($original);
            $after = $this->decimal($target);
            if ($before === $after) {
                $unchanged++;

                continue;
            }
            if ($this->decimal($balance->available_quantity) !== $before) {
                $errors[] = "Fila {$number}: la existencia cambió desde la descarga. Descarga un Excel nuevo y actualiza el conteo.";

                continue;
            }
            $changes[] = [
                'id' => $balance->id, 'variant_id' => $balance->product_variant_id, 'row' => $number,
                'name' => $balance->variant->product->name.' · '.$balance->variant->name,
                'sku' => $balance->variant->sku ?? '', 'unit' => 'unidad',
                'variant_control' => $this->variantControl($balance),
                'before' => $before, 'after' => $after,
            ];
        }
        if ($errors !== []) {
            return ['changes' => $changes, 'unchanged' => $unchanged, 'total' => count($rows), 'errors' => $errors];
        }

        return ['changes' => $changes, 'unchanged' => $unchanged, 'total' => count($rows)];
    }

    /** @param list<array{id: int, variant_id: int, row: int, name: string, sku: string, unit: string, variant_control: string, before: string, after: string}> $changes */
    public function apply(array $changes, int $actorId, string $reason, string $locationCode = 'ALM', string $format = 'xlsx'): int
    {
        return DB::transaction(function () use ($changes, $actorId, $reason, $locationCode, $format): int {
            $balances = $this->balances($locationCode)->with('variant.product')->whereIn('id', array_column($changes, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($changes as $change) {
                $balance = $balances->get($change['id']);
                if ($balance === null || $balance->product_variant_id !== $change['variant_id'] || $this->decimal($balance->available_quantity) !== $change['before'] || $this->variantControl($balance) !== $change['variant_control']) {
                    throw ValidationException::withMessages(['file' => "Fila {$change['row']}: la existencia o el producto cambió después de la revisión. Descarga un Excel nuevo y actualiza el conteo. No se aplicó ningún ajuste."]);
                }
            }
            $warehouse = InventoryLocation::query()->where('code', $locationCode)->firstOrFail();
            $audit = AuditLog::create([
                'user_id' => $actorId, 'action' => 'inventory.imported',
                'subject_type' => InventoryLocation::class, 'subject_id' => $warehouse->id,
                'before' => ['items' => array_map(fn (array $change): array => ['balance_id' => $change['id'], 'quantity' => $change['before']], $changes)],
                'after' => ['items' => array_map(fn (array $change): array => ['balance_id' => $change['id'], 'quantity' => $change['after']], $changes)],
                'reason' => mb_substr($reason, 0, 255), 'correlation_id' => (string) Str::uuid(),
            ]);
            foreach ($changes as $change) {
                $this->inventory->move($change['variant_id'], $warehouse->id, round((float) $change['after'] - (float) $change['before'], 3), 'adjustment', $actorId, AuditLog::class, $audit->id, mb_substr('Importación '.($format === 'csv' ? 'CSV' : 'Excel').': '.$reason, 0, 255));
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

    private function signature(InventoryBalance $balance, string $quantity, string $locationCode): string
    {
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
