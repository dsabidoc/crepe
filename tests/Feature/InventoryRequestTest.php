<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_request_can_be_partially_delivered_and_closed_without_pending_balance(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();

        $this->actingAs($receptionist)->withSession(['crepe.mode' => 'recepcion'])->post(route('inventory.requests.store'), [
            'location' => 'REC',
            'items' => [$variant->id => ['product_variant_id' => $variant->id, 'requested_quantity' => 10]],
            'notes' => 'Reponer mostrador',
        ])->assertRedirect(route('inventory.requests.index', ['location' => 'REC']));

        $inventoryRequest = InventoryRequest::query()->with('items')->firstOrFail();
        $item = $inventoryRequest->items->firstOrFail();
        $this->assertSame('pending', $inventoryRequest->status);

        $this->actingAs($warehouse)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.requests.edit', $inventoryRequest))
            ->assertOk()
            ->assertSee($inventoryRequest->code);

        $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])->post(route('inventory.requests.close', $inventoryRequest), [
            'items' => [$item->id => 2],
            'closure_notes' => 'Se entregó lo disponible para esta solicitud.',
        ])->assertRedirect(route('inventory.requests.index'));

        $this->assertDatabaseHas('inventory_requests', ['id' => $inventoryRequest->id, 'status' => 'closed']);
        $this->assertDatabaseHas('inventory_request_items', ['id' => $item->id, 'delivered_quantity' => 2]);
        $this->assertSame(34.0, (float) InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->value('available_quantity'));
        $this->assertSame(17.0, (float) InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'REC'))->value('available_quantity'));
        $this->assertDatabaseHas('inventory_movements', ['type' => 'transfer_out', 'reference_id' => $inventoryRequest->id, 'quantity' => -2]);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'transfer_in', 'reference_id' => $inventoryRequest->id, 'quantity' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.request.closed', 'subject_id' => $inventoryRequest->id]);
    }

    public function test_warehouse_can_view_requests_but_cannot_create_one(): void
    {
        $this->seed(CrepeSeeder::class);
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');

        $this->actingAs($warehouse)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.requests.index'))
            ->assertOk()
            ->assertSee('Solicitudes por atender');

        $this->actingAs($warehouse)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.requests.create', ['location' => 'REC']))
            ->assertForbidden();
    }

    public function test_inventory_requests_use_product_units_and_convert_to_base_stock_on_delivery(): void
    {
        $this->seed(CrepeSeeder::class);
        $colorBar = User::factory()->create();
        $colorBar->assignRole('Color Bar');
        $warehouse = User::factory()->create();
        $warehouse->assignRole('Almacén');
        $variant = ProductVariant::query()->where('sku', 'KOL-71-STD')->firstOrFail();

        $this->actingAs($colorBar)->withSession(['crepe.mode' => 'color-bar'])->post(route('inventory.requests.store'), [
            'location' => 'CB',
            'items' => [$variant->id => ['product_variant_id' => $variant->id, 'requested_units' => 1]],
        ])->assertRedirect();

        $inventoryRequest = InventoryRequest::query()->with('items')->firstOrFail();
        $item = $inventoryRequest->items->firstOrFail();
        $this->assertSame(1, $item->requested_units);
        $this->assertSame(1000.0, (float) $item->requested_quantity);

        $this->actingAs($warehouse)->withSession(['crepe.mode' => 'almacen'])->post(route('inventory.requests.close', $inventoryRequest), [
            'items' => [$item->id => 1],
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_request_items', ['id' => $item->id, 'delivered_units' => 1, 'delivered_quantity' => 1000]);
        $this->assertSame(4000.0, (float) InventoryBalance::query()->where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->value('available_quantity'));
    }
}
