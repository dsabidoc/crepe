<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplaceProductCatalog extends Command
{
    protected $signature = 'crepe:replace-product-catalog {path : Ruta al CSV de productos} {--stock=3 : Existencia inicial por producto en Almacén}';

    protected $description = 'Reemplaza el catálogo activo con un CSV y registra existencia inicial en Almacén';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No fue posible leer el archivo CSV indicado.');

            return self::FAILURE;
        }

        $rows = $this->readRows($path);
        if ($rows === []) {
            $this->error('El CSV no contiene productos con costo válido.');

            return self::FAILURE;
        }

        $stock = max(0, (float) $this->option('stock'));
        $warehouse = InventoryLocation::query()->firstOrCreate(['code' => 'ALM'], ['name' => 'Almacén', 'is_active' => true]);
        $actorId = User::query()->oldest('id')->value('id');
        $category = ProductCategory::query()->firstOrCreate(['name' => 'Importado'], ['color' => '#B95070']);
        $skipped = $this->countRows($path) - count($rows);

        DB::transaction(function () use ($actorId, $category, $rows, $stock, $warehouse): void {
            Product::query()->where('status', 'active')->update(['status' => 'inactive']);

            foreach ($rows as $row) {
                $brand = $this->findOrCreateBrand($row['brand']);
                $supplier = $row['supplier'] === '' ? null : $this->findOrCreateSupplier($row['supplier']);
                $sku = 'CSV-'.Str::upper(Str::substr(sha1($row['name'].'|'.$row['brand'].'|'.$row['supplier']), 0, 12));

                $product = Product::query()->updateOrCreate(['sku' => $sku], [
                    'product_category_id' => $category->id,
                    'product_brand_id' => $brand?->id,
                    'preferred_supplier_id' => $supplier?->id,
                    'name' => $row['name'],
                    'brand' => $row['brand'] !== '' ? $row['brand'] : null,
                    'description' => 'Importado desde catálogo CSV.',
                    'is_color_bar_usable' => false,
                    'status' => 'active',
                ]);
                $variant = $product->variants()->firstOrNew(['sku' => $sku.'-STD']);
                $variant->fill([
                    'name' => 'Unidad',
                    'base_unit' => 'unidad',
                    'content_quantity' => 1,
                    'cost' => $row['cost'],
                    'sale_price' => $row['price'],
                    'minimum_stock' => $row['minimum'],
                    'maximum_stock' => $row['maximum'],
                ]);
                $variant->save();

                $this->setOpeningStock($variant, $warehouse, $stock, $actorId);
            }
        });

        $this->info('Catálogo activo reemplazado: '.count($rows).' productos importados.');
        $this->line("Productos omitidos por costo vacío o inválido: {$skipped}.");
        $this->line("Existencia inicial en Almacén: {$stock} unidades por producto.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{name:string,brand:string,supplier:string,cost:float,price:float,minimum:float,maximum:float}>
     */
    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if ($headers === false) {
            fclose($handle);

            return [];
        }
        $headers = array_map(fn ($header): string => strtoupper(trim((string) $header)), $headers);
        $rows = [];

        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $row = array_combine($headers, array_slice(array_pad($values, count($headers), null), 0, count($headers)));
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['PRODUCTO'] ?? ''));
            $cost = $this->amount($row['COSTO'] ?? null);
            if ($name === '' || $cost <= 0) {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'brand' => trim((string) ($row['MARCA'] ?? '')),
                'supplier' => trim((string) ($row['PROVEEDOR'] ?? '')),
                'cost' => $cost,
                'price' => $this->amount($row['PRECIO'] ?? null),
                'minimum' => max(0, $this->amount($row['MIN'] ?? null)),
                'maximum' => max(0, $this->amount($row['MAX'] ?? null)),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function countRows(string $path): int
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        fgetcsv($handle, 0, ',', '"', '\\');
        while (fgetcsv($handle, 0, ',', '"', '\\') !== false) {
            $count++;
        }
        fclose($handle);

        return $count;
    }

    private function amount(mixed $value): float
    {
        $digits = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($digits) ? (float) $digits : 0.0;
    }

    private function findOrCreateBrand(string $name): ?ProductBrand
    {
        if ($name === '') {
            return null;
        }

        return ProductBrand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? ProductBrand::query()->create(['name' => $name, 'is_active' => true]);
    }

    private function findOrCreateSupplier(string $name): ?Supplier
    {
        return Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? Supplier::query()->create(['name' => $name, 'is_active' => true]);
    }

    private function setOpeningStock(ProductVariant $variant, InventoryLocation $warehouse, float $stock, ?int $actorId): void
    {
        $balance = InventoryBalance::query()->firstOrNew([
            'inventory_location_id' => $warehouse->id,
            'product_variant_id' => $variant->id,
        ]);
        $before = (float) ($balance->available_quantity ?? 0);
        if (abs($before - $stock) < 0.0005 && $balance->exists) {
            return;
        }
        $balance->available_quantity = $stock;
        $balance->save();
        $delta = $stock - $before;
        InventoryMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_variant_id' => $variant->id,
            'inventory_location_id' => $warehouse->id,
            'type' => 'opening',
            'quantity' => $delta,
            'before_quantity' => $before,
            'after_quantity' => $stock,
            'unit_cost' => $variant->cost,
            'reason' => 'Reemplazo de catálogo CSV',
            'created_by' => $actorId,
        ]);
    }
}
