<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\SalonService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedColorBarDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_an_idempotent_color_bar_demo_without_changing_existing_catalogs(): void
    {
        $this->seed(CrepeSeeder::class);
        $existingProductCount = Product::query()->count();
        $existingServiceCount = SalonService::query()->count();

        $this->artisan('crepe:seed-color-bar-demo')
            ->expectsOutput('Demo Color Bar disponible: 5 servicios y 10 productos con existencias iniciales en Color Bar.')
            ->assertSuccessful();

        $colorBarId = InventoryLocation::query()->where('code', 'CB')->value('id');
        $this->assertSame($existingProductCount + 10, Product::query()->count());
        $this->assertSame($existingServiceCount + 5, SalonService::query()->count());
        $this->assertSame(10, Product::query()->where('sku', 'like', 'DEMO-CB-%')->where('is_color_bar_usable', true)->count());
        $this->assertSame(5, SalonService::query()->where('name', 'like', 'Demo CB ·%')->where('requires_color_bar', true)->count());
        $this->assertSame(10, InventoryBalance::query()->where('inventory_location_id', $colorBarId)->whereHas('variant.product', fn ($query) => $query->where('sku', 'like', 'DEMO-CB-%'))->count());
        $this->assertSame(10, InventoryMovement::query()->where('inventory_location_id', $colorBarId)->where('reason', 'Existencia inicial demo Color Bar')->count());

        $this->artisan('crepe:seed-color-bar-demo')->assertSuccessful();

        $this->assertSame($existingProductCount + 10, Product::query()->count());
        $this->assertSame($existingServiceCount + 5, SalonService::query()->count());
        $this->assertSame(10, InventoryMovement::query()->where('inventory_location_id', $colorBarId)->where('reason', 'Existencia inicial demo Color Bar')->count());
    }
}
