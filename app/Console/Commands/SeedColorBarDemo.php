<?php

namespace App\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SeedColorBarDemo extends Command
{
    protected $signature = 'crepe:seed-color-bar-demo';

    protected $description = 'Agrega servicios y productos demostrativos exclusivos de Color Bar';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $actorId = User::query()->oldest('id')->value('id');

        if ($actorId === null) {
            $this->error('No hay una persona usuaria para registrar la bitácora de inventario.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($actorId): void {
            $colorBar = InventoryLocation::query()->firstOrCreate(
                ['code' => 'CB'],
                ['name' => 'Color Bar', 'is_active' => true],
            );
            $serviceCategory = ServiceCategory::query()->firstOrCreate(
                ['name' => 'Coloración'],
                ['color' => '#9A5EEA', 'sort_order' => 2, 'is_active' => true],
            );

            $this->seedServices($serviceCategory);
            $this->seedProducts($colorBar, $actorId);
        });

        $this->info('Demo Color Bar disponible: 5 servicios y 10 productos con existencias iniciales en Color Bar.');

        return self::SUCCESS;
    }

    private function seedServices(ServiceCategory $category): void
    {
        foreach ([
            ['Demo CB · Coloración global', 'Aplicación completa de color para demostrar el flujo de Color Bar.', 1450, 360, 135, 5],
            ['Demo CB · Balayage', 'Técnica de iluminación para demostración.', 2900, 850, 240, 5],
            ['Demo CB · Iluminación parcial', 'Mechas parciales para demostración.', 1950, 540, 165, 5],
            ['Demo CB · Corrección de color', 'Corrección técnica de color para demostración.', 3200, 1100, 270, 5],
            ['Demo CB · Matiz y baño de color', 'Matiz posterior a coloración para demostración.', 950, 230, 75, 5],
        ] as [$name, $description, $price, $cost, $duration, $commission]) {
            $service = SalonService::query()->updateOrCreate(['name' => $name], [
                'service_category_id' => $category->id,
                'description' => $description,
                'base_price' => $price,
                'base_cost' => $cost,
                'estimated_duration_minutes' => $duration,
                'commission_type' => 'percentage',
                'commission_rate' => $commission,
                'commission_fixed_amount' => null,
                'price_type' => 'fixed',
                'requires_color_bar' => true,
                'status' => 'active',
            ]);

            foreach (['A', 'B', 'C'] as $tier) {
                $service->prices()->updateOrCreate(['tier' => $tier], [
                    'cost' => $cost,
                    'sale_price' => $price,
                ]);
            }
        }
    }

    private function seedProducts(InventoryLocation $colorBar, int $actorId): void
    {
        foreach ([
            ['DEMO-CB-COL-60', 'Color Lab Demo', 'Coloración', 'Color Lab 7.0 Demo', 'Tubo 60 g', 'g', 60, 42, 0, 720, 360, 1440],
            ['DEMO-CB-COL-71', 'Color Lab Demo', 'Coloración', 'Color Lab 7.1 Demo', 'Tubo 60 g', 'g', 60, 42, 0, 720, 360, 1440],
            ['DEMO-CB-COL-80', 'Color Lab Demo', 'Coloración', 'Color Lab 8.0 Demo', 'Tubo 60 g', 'g', 60, 42, 0, 720, 360, 1440],
            ['DEMO-CB-COL-666', 'Color Lab Demo', 'Coloración', 'Color Lab 6.66 Demo', 'Tubo 60 g', 'g', 60, 46, 0, 720, 360, 1440],
            ['DEMO-CB-OX-10', 'Oxidante Pro Demo', 'Oxidantes', 'Oxidante 10 vol Demo', 'Botella 1 L', 'ml', 1000, 85, 0, 2000, 1000, 4000],
            ['DEMO-CB-OX-20', 'Oxidante Pro Demo', 'Oxidantes', 'Oxidante 20 vol Demo', 'Botella 1 L', 'ml', 1000, 85, 0, 2000, 1000, 4000],
            ['DEMO-CB-OX-30', 'Oxidante Pro Demo', 'Oxidantes', 'Oxidante 30 vol Demo', 'Botella 1 L', 'ml', 1000, 89, 0, 2000, 1000, 4000],
            ['DEMO-CB-BLEACH', 'Color Lab Demo', 'Coloración', 'Decolorante violeta Demo', 'Bolsa 500 g', 'g', 500, 180, 0, 1000, 500, 2000],
            ['DEMO-CB-BOND', 'Bond Care Demo', 'Tratamientos Color Bar', 'Protector de enlaces Demo', 'Botella 500 ml', 'ml', 500, 220, 0, 750, 250, 1500],
            ['DEMO-CB-REMOVE', 'Color Lab Demo', 'Tratamientos Color Bar', 'Removedor de color Demo', 'Botella 500 ml', 'ml', 500, 195, 0, 750, 250, 1500],
        ] as [$sku, $brandName, $categoryName, $name, $variantName, $unit, $content, $cost, $salePrice, $stock, $minimum, $maximum]) {
            $brand = ProductBrand::query()->firstOrCreate(['name' => $brandName], ['is_active' => true]);
            $category = ProductCategory::query()->firstOrCreate(['name' => $categoryName], ['color' => '#9A5EEA']);
            $product = Product::query()->firstOrCreate(['sku' => $sku], [
                'product_category_id' => $category->id,
                'product_brand_id' => $brand->id,
                'name' => $name,
                'brand' => $brandName,
                'description' => 'Producto demostrativo exclusivo de Color Bar.',
                'is_color_bar_usable' => true,
                'status' => 'active',
            ]);
            $variant = ProductVariant::query()->firstOrCreate(['sku' => $sku.'-STD'], [
                'product_id' => $product->id,
                'name' => $variantName,
                'base_unit' => $unit,
                'content_quantity' => $content,
                'cost' => $cost,
                'sale_price' => $salePrice,
                'minimum_stock' => $minimum,
                'maximum_stock' => $maximum,
            ]);

            $this->createOpeningBalance($variant, $colorBar, $stock, $actorId);
        }
    }

    private function createOpeningBalance(ProductVariant $variant, InventoryLocation $location, float $quantity, int $actorId): void
    {
        $balance = InventoryBalance::query()->firstOrCreate([
            'inventory_location_id' => $location->id,
            'product_variant_id' => $variant->id,
        ], ['available_quantity' => $quantity]);

        if (! $balance->wasRecentlyCreated) {
            return;
        }

        InventoryMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'product_variant_id' => $variant->id,
            'inventory_location_id' => $location->id,
            'type' => 'opening',
            'quantity' => $quantity,
            'before_quantity' => 0,
            'after_quantity' => $quantity,
            'unit_cost' => $variant->cost,
            'reason' => 'Existencia inicial demo Color Bar',
            'created_by' => $actorId,
        ]);
    }
}
