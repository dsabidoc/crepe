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
    protected $signature = 'crepe:replace-product-catalog {path : Ruta al CSV de productos} {--stock=3 : Existencia inicial por producto en Almacén cuando el archivo no incluye ALMACÉN}';

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
            $this->error('El CSV no contiene productos válidos.');

            return self::FAILURE;
        }

        $stock = max(0, (float) $this->option('stock'));
        $warehouse = InventoryLocation::query()->firstOrCreate(['code' => 'ALM'], ['name' => 'Almacén', 'is_active' => true]);
        $actorId = User::query()->oldest('id')->value('id');
        $category = ProductCategory::query()->firstOrCreate(['name' => 'Catálogo final'], ['color' => '#B95070']);
        $sourceSkus = collect($rows)->pluck('sku')->all();
        $sourceBrandNames = collect($rows)->pluck('brand')->filter()->unique()->values();
        $sourceSupplierNames = collect($rows)->pluck('supplier')->filter()->unique()->values();
        $skipped = $this->countRows($path) - count($rows);

        DB::transaction(function () use ($actorId, $category, $rows, $sourceBrandNames, $sourceSkus, $sourceSupplierNames, $stock, $warehouse): void {
            Product::query()->where('status', 'active')->update(['status' => 'inactive']);

            $brands = $this->resolveBrands($sourceBrandNames->all());
            $suppliers = $this->resolveSuppliers($sourceSupplierNames->all());

            foreach ($rows as $row) {
                $brand = $row['brand'] === '' ? null : $brands[$this->normalizedKey($row['brand'])];
                $supplier = $row['supplier'] === '' ? null : $suppliers[$this->normalizedKey($row['supplier'])];

                $product = Product::query()->updateOrCreate(['sku' => $row['sku']], [
                    'product_category_id' => $category->id,
                    'product_brand_id' => $brand?->id,
                    'preferred_supplier_id' => $supplier?->id,
                    'name' => $row['name'],
                    'brand' => $row['brand'] !== '' ? $row['brand'] : null,
                    'description' => 'Catálogo final. Ubicación original: '.$row['source_location'].'.',
                    'is_color_bar_usable' => $row['is_color_bar_usable'],
                    'status' => 'active',
                ]);
                $variant = $product->variants()->firstOrNew(['sku' => $row['sku'].'-STD']);
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

                $this->setOpeningStock($variant, $warehouse, $row['warehouse_stock'] ?? $stock, $actorId);
            }

            Product::query()
                ->where('status', 'inactive')
                ->whereNotIn('sku', $sourceSkus)
                ->update(['product_brand_id' => null, 'preferred_supplier_id' => null]);

            ProductBrand::query()
                ->whereNotIn('name', $sourceBrandNames)
                ->doesntHave('products')
                ->delete();

            Supplier::query()
                ->whereNotIn('name', $sourceSupplierNames)
                ->update(['is_active' => false]);
        });

        $this->info('Catálogo activo reemplazado: '.count($rows).' productos importados.');
        $this->line("Filas vacías o duplicadas omitidas: {$skipped}.");
        $this->line('Existencias iniciales registradas en Almacén según el CSV.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{name:string,brand:string,supplier:string,sku:string,cost:float,price:float,minimum:float,maximum:float,warehouse_stock:float,source_location:string,is_color_bar_usable:bool}>
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
        $headers = array_map(fn ($header): string => $this->normalizedHeader((string) $header), $headers);
        $rows = [];
        $seen = [];

        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $row = array_combine($headers, array_slice(array_pad($values, count($headers), null), 0, count($headers)));
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['PRODUCTO'] ?? ''));
            $cost = $this->amount($row['COSTO'] ?? null);
            if ($name === '') {
                continue;
            }
            $brand = trim((string) ($row['MARCA'] ?? ''));
            $supplier = trim((string) ($row['PROVEEDOR'] ?? ''));
            $key = $this->normalizedKey($name).'|'.$this->normalizedKey($brand);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $sourceLocation = $this->normalizedHeader((string) ($row['UBICACION'] ?? ''));
            $rows[] = [
                'name' => $name,
                'brand' => $brand,
                'supplier' => $supplier,
                'sku' => 'CSV-'.Str::upper(Str::substr(sha1($key), 0, 12)),
                'cost' => $cost,
                'price' => $this->amount($row['PRECIO'] ?? null),
                'minimum' => max(0, $this->amount($row['MIN'] ?? null)),
                'maximum' => max(0, $this->amount($row['MAX'] ?? null)),
                'warehouse_stock' => array_key_exists('ALMACEN', $row) ? max(0, $this->amount($row['ALMACEN'])) : max(0, (float) $this->option('stock')),
                'source_location' => $sourceLocation === '' ? 'SIN UBICACIÓN' : $sourceLocation,
                'is_color_bar_usable' => in_array($sourceLocation, ['COLORBAR', 'AMBOS'], true),
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

    /**
     * @param  list<string>  $names
     * @return array<string, ProductBrand>
     */
    private function resolveBrands(array $names): array
    {
        $brands = [];
        foreach ($names as $name) {
            $brand = ProductBrand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? ProductBrand::query()->create(['name' => $name, 'is_active' => true]);
            if (! $brand->is_active) {
                $brand->update(['is_active' => true]);
            }
            $brands[$this->normalizedKey($name)] = $brand;
        }

        return $brands;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, Supplier>
     */
    private function resolveSuppliers(array $names): array
    {
        $suppliers = [];
        foreach ($names as $name) {
            $supplier = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? Supplier::query()->create(['name' => $name, 'is_active' => true]);
            if (! $supplier->is_active) {
                $supplier->update(['is_active' => true]);
            }
            $suppliers[$this->normalizedKey($name)] = $supplier;
        }

        return $suppliers;
    }

    private function normalizedHeader(string $value): string
    {
        return Str::upper(Str::ascii(trim($value)));
    }

    private function normalizedKey(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
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
