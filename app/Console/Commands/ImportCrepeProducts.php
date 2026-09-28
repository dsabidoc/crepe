<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Services\InventoryService;
use Illuminate\Console\Command;

class ImportCrepeProducts extends Command
{
    protected $signature = 'crepe:import-products {path : Ruta al CSV exportado de CrepeManager} {--stock=5 : Existencia inicial por producto en Recepción}';

    protected $description = 'Importa catálogo de productos de CrepeManager y registra inventario inicial por ubicación';

    public function handle(InventoryService $inventory): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No fue posible leer el archivo CSV indicado.');

            return self::FAILURE;
        }

        $location = InventoryLocation::query()->firstOrCreate(['code' => 'REC'], ['name' => 'Recepción', 'is_active' => true]);
        $stock = max(0, (float) $this->option('stock'));
        $handle = fopen($path, 'r');
        $imported = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $sourceId = trim((string) ($row[9] ?? ''));
            $name = trim((string) ($row[10] ?? ''));
            $brand = trim((string) ($row[11] ?? ''));
            if (! ctype_digit($sourceId) || (int) $sourceId < 1 || $name === '' || $name === '0' || $brand === '') {
                continue;
            }

            $category = ProductCategory::query()->firstOrCreate(['name' => $brand], ['color' => '#2F63F5']);
            $product = Product::query()->updateOrCreate(['sku' => "IMP-{$sourceId}"], [
                'product_category_id' => $category->id, 'name' => $name, 'brand' => $brand,
                'description' => "Importado de CrepeManager (ID {$sourceId})", 'is_color_bar_usable' => false, 'status' => 'active',
            ]);
            [$unit, $content] = $this->inferPresentation($name);
            $variant = ProductVariant::query()->updateOrCreate(['sku' => "IMP-{$sourceId}-STD"], [
                'product_id' => $product->id, 'name' => $content > 1 ? "{$content} {$unit}" : 'Unidad', 'base_unit' => $unit,
                'content_quantity' => $content, 'cost' => $this->amount($row[13] ?? ''), 'sale_price' => $this->amount($row[12] ?? ''), 'minimum_stock' => 1,
            ]);

            if (! InventoryBalance::query()->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->exists()) {
                $inventory->move($variant->id, $location->id, $stock, 'opening', 1, Product::class, $product->id, 'Importación inicial de catálogo');
            }
            $imported++;
        }

        fclose($handle);
        $this->info("Catálogo importado: {$imported} productos. Inventario inicial: {$stock} por producto en Recepción.");

        return self::SUCCESS;
    }

    private function amount(mixed $value): float
    {
        $digits = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($digits) ? (float) $digits : 0.0;
    }

    private function inferPresentation(string $name): array
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(ML|M[L]?\.|LITRO(?:S)?|LT(?:S)?\.?|GR(?:S)?\.?|G\b|OZ\b)/iu', $name, $match)) {
            $quantity = (float) $match[1];
            $rawUnit = mb_strtoupper($match[2]);
            if (str_contains($rawUnit, 'LITRO') || str_starts_with($rawUnit, 'LT')) {
                return ['l', $quantity];
            }
            if (str_contains($rawUnit, 'ML')) {
                return ['ml', $quantity];
            }

            return ['g', $quantity];
        }

        return ['unidad', 1];
    }
}
