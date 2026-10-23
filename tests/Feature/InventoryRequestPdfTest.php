<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryRequestPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_downloads_all_products_across_pages_without_closing_or_moving_inventory(): void
    {
        $this->freezeTime();
        $this->seed(CrepeSeeder::class);
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');
        $request = $this->inventoryRequest($warehouse);
        $location = InventoryLocation::query()->where('code', 'ALM')->firstOrFail();
        foreach (range(1, 35) as $number) {
            $product = Product::query()->create(['name' => 'Producto '.str_pad((string) $number, 3, '0', STR_PAD_LEFT).' tratamiento para cabello con nombre extenso', 'brand' => 'Marca de prueba']);
            $variant = $product->variants()->create(['name' => 'Envase de mil gramos', 'base_unit' => 'gr', 'content_quantity' => 1000]);
            $request->items()->create(['product_variant_id' => $variant->id, 'requested_units' => 7, 'requested_quantity' => 7000, 'unit' => 'gr']);
            InventoryBalance::query()->create(['inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'available_quantity' => 2500]);
        }
        $legacyVariant = ProductVariant::query()->where('sku', 'KOL-71-STD')->firstOrFail();
        $request->items()->create(['product_variant_id' => $legacyVariant->id, 'requested_quantity' => 12.5, 'unit' => 'gr']);
        $beforeRequest = $request->fresh()->getAttributes();
        $beforeBalances = InventoryBalance::query()->get()->toArray();
        $beforeMovements = InventoryMovement::query()->count();

        $response = $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.requests.download', $request));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('SOL-PDF-TEST.pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        foreach (range(1, 35) as $number) {
            $this->assertStringContainsString('Producto '.str_pad((string) $number, 3, '0', STR_PAD_LEFT), $pdf);
        }
        $this->assertGreaterThan(1, preg_match_all('~/Type /Page\s~', $pdf));
        $this->assertStringContainsString('(7) Tj', $pdf);
        $this->assertStringContainsString('(2) Tj', $pdf);
        $this->assertStringContainsString('(unidades) Tj', $pdf);
        $this->assertStringContainsString('(12.5) Tj', $pdf);
        $this->assertStringContainsString('(gr) Tj', $pdf);
        $this->assertStringNotContainsString('(7,000) Tj', $pdf);
        $this->assertSame($beforeRequest, $request->fresh()->getAttributes());
        $this->assertSame($beforeBalances, InventoryBalance::query()->get()->toArray());
        $this->assertSame($beforeMovements, InventoryMovement::query()->count());
        $this->assertDatabaseHas('inventory_requests', ['id' => $request->id, 'status' => 'pending', 'processed_at' => null]);
    }

    public function test_color_bar_cannot_download_a_warehouse_preparation_pdf(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Color Bar');
        $request = $this->inventoryRequest($receptionist);

        $this->actingAs($receptionist)->withSession(['crepe.mode' => 'color-bar'])
            ->get(route('inventory.requests.download', $request))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login_for_pdf_download(): void
    {
        $this->get(route('inventory.requests.download', 999))->assertRedirect(route('login'));
    }

    public function test_unknown_request_returns_not_found_for_warehouse(): void
    {
        $this->seed(CrepeSeeder::class);
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');

        $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.requests.download', 999999))->assertNotFound();
    }

    private function inventoryRequest(User $requester): InventoryRequest
    {
        return InventoryRequest::query()->create([
            'code' => 'SOL-PDF-TEST',
            'source_location_id' => InventoryLocation::query()->where('code', 'REC')->value('id'),
            'requested_by' => $requester->id,
            'status' => 'pending',
        ]);
    }
}
