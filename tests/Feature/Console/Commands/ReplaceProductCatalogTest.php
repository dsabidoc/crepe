<?php

namespace Tests\Feature\Console\Commands;

use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplaceProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_replaces_active_catalog_and_seeds_opening_units(): void
    {
        $this->seed(CrepeSeeder::class);
        $oldProduct = Product::query()->firstOrFail();
        $path = tempnam(sys_get_temp_dir(), 'crepe-products-');
        file_put_contents($path, implode(PHP_EOL, [
            'PRODUCTO,MARCA,PROVEEDOR,MIN,MAX,COSTO,PRECIO',
            'Producto importado,Marca Uno,Proveedor Uno,2,8,"$ 125.50","$ 250.00"',
        ]));

        try {
            $this->artisan('crepe:replace-product-catalog', ['path' => $path])
                ->assertSuccessful()
                ->expectsOutput('Catálogo activo reemplazado: 1 productos importados.')
                ->expectsOutput('Filas vacías o duplicadas omitidas: 0.');

            $this->assertSame('inactive', $oldProduct->fresh()->status);
            $product = Product::query()->where('name', 'Producto importado')->firstOrFail();
            $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
            $this->assertSame('active', $product->status);
            $this->assertSame(125.5, (float) $variant->cost);
            $this->assertSame(250.0, (float) $variant->sale_price);
            $this->assertSame(2.0, (float) $variant->minimum_stock);
            $this->assertSame(8.0, (float) $variant->maximum_stock);
            $this->assertSame(3.0, (float) InventoryBalance::query()
                ->where('product_variant_id', $variant->id)
                ->whereHas('location', fn ($query) => $query->where('code', 'ALM'))
                ->value('available_quantity'));
            $this->assertDatabaseHas('inventory_movements', [
                'product_variant_id' => $variant->id,
                'type' => 'opening',
                'quantity' => 3,
                'reason' => 'Reemplazo de catálogo CSV',
            ]);
            $this->assertSame(1, Product::query()->where('status', 'active')->count());
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_command_imports_final_catalog_data_and_keeps_all_stock_in_warehouse(): void
    {
        $this->seed(CrepeSeeder::class);
        $path = tempnam(sys_get_temp_dir(), 'crepe-final-products-');
        file_put_contents($path, implode(PHP_EOL, [
            'PRODUCTO,UBICACIÒN,MARCA,PROVEEDOR,ALMACÉN,MIN,MAX, COSTO, PRECIO',
            'Tinte profesional,COLORBAR,Marca Color,Proveedor Uno,17,3,24,"$ 125.50","$ 250.00"',
            'Shampoo venta,RECEPCIÒN,Marca Venta,Proveedor Dos,4,1,9,"$ -","$ -"',
            'Tinte profesional,COLORBAR,Marca Color,Proveedor Uno,17,3,24,"$ 125.50","$ 250.00"',
        ]));

        try {
            $this->artisan('crepe:replace-product-catalog', ['path' => $path])
                ->assertSuccessful()
                ->expectsOutput('Catálogo activo reemplazado: 2 productos importados.')
                ->expectsOutput('Filas vacías o duplicadas omitidas: 1.');

            $colorProduct = Product::query()->where('name', 'Tinte profesional')->firstOrFail();
            $retailProduct = Product::query()->where('name', 'Shampoo venta')->firstOrFail();
            $colorVariant = ProductVariant::query()->where('product_id', $colorProduct->id)->firstOrFail();
            $retailVariant = ProductVariant::query()->where('product_id', $retailProduct->id)->firstOrFail();

            $this->assertTrue($colorProduct->is_color_bar_usable);
            $this->assertFalse($retailProduct->is_color_bar_usable);
            $this->assertSame(17.0, (float) $colorVariant->balances()->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->value('available_quantity'));
            $this->assertSame(4.0, (float) $retailVariant->balances()->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->value('available_quantity'));
            $this->assertSame(0.0, (float) $retailVariant->cost);
            $this->assertSame(0.0, (float) $retailVariant->sale_price);
            $this->assertSame(3.0, (float) $colorVariant->minimum_stock);
            $this->assertSame(24.0, (float) $colorVariant->maximum_stock);
            $this->assertDatabaseHas('product_brands', ['name' => 'Marca Color', 'is_active' => true]);
            $this->assertDatabaseHas('suppliers', ['name' => 'Proveedor Dos', 'is_active' => true]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
