<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryRequestReversalTest extends TestCase
{
    use RefreshDatabase;

    private function delivery(string $code = 'SOL-TEST'): InventoryRequest
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen']);
        $warehouse = InventoryLocation::where('code', 'ALM')->firstOrFail();
        $destination = InventoryLocation::where('code', 'CB')->firstOrFail();
        $variant = ProductVariant::where('sku', 'KOL-71-STD')->firstOrFail();
        $request = InventoryRequest::create(['code' => $code, 'source_location_id' => $destination->id, 'status' => 'closed', 'requested_by' => $admin->id, 'processed_by' => $admin->id, 'processed_at' => now()]);
        $request->items()->create(['product_variant_id' => $variant->id, 'requested_units' => 2, 'requested_quantity' => 2, 'delivered_units' => 2, 'delivered_quantity' => 2, 'unit' => 'unidad']);
        app(InventoryService::class)->move($variant->id, $warehouse->id, -2, 'transfer_out', $admin->id, InventoryRequest::class, $request->id);
        app(InventoryService::class)->move($variant->id, $destination->id, 2, 'transfer_in', $admin->id, InventoryRequest::class, $request->id);

        return $request;
    }

    public function test_reversal_returns_original_units_once_and_preserves_delivery_and_movements(): void
    {
        $request = $this->delivery();
        $before = InventoryMovement::query()->where('reference_type', InventoryRequest::class)->where('reference_id', $request->id)->get()->keyBy('id')->toArray();
        $count = InventoryMovement::count();
        $payload = ['reason' => 'Entrega registrada por error', 'key' => (string) str()->uuid()];
        $this->post(route('inventory.requests.reverse', $request), $payload)->assertSessionHasNoErrors();
        $this->post(route('inventory.requests.reverse', $request), $payload)->assertSessionHasNoErrors();
        $this->assertSame($count + 2, InventoryMovement::count());
        $this->assertDatabaseCount('inventory_request_reversals', 1);
        $this->assertSame('closed', $request->fresh()->status);
        $this->assertSame(2, $request->items->first()->delivered_units);
        $this->assertSame($before, InventoryMovement::query()->whereIn('id', array_keys($before))->get()->keyBy('id')->toArray());
        foreach ($before as $original) {
            $inverse = InventoryMovement::query()->where('reverses_movement_id', $original['id'])->sole();
            $this->assertSame(-(float) $original['quantity'], (float) $inverse->quantity);
        }
        $this->get(route('inventory.requests.show', $request))->assertOk()->assertSee('Entrega revertida');
    }

    public function test_log_confirmation_contains_the_entire_delivery_even_when_other_products_are_on_another_page(): void
    {
        $request = $this->delivery();
        $warehouse = InventoryLocation::where('code', 'ALM')->sole();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->sole();
        foreach (range(1, 31) as $unused) {
            app(InventoryService::class)->move($variant->id, $warehouse->id, 1, 'adjustment', auth()->id(), null, null, 'Entrada posterior de prueba');
        }
        $request->items()->create(['product_variant_id' => $variant->id, 'requested_units' => 3, 'requested_quantity' => 3, 'delivered_units' => 3, 'delivered_quantity' => 3, 'unit' => 'unidad']);
        app(InventoryService::class)->move($variant->id, $warehouse->id, -3, 'transfer_out', auth()->id(), InventoryRequest::class, $request->id);
        app(InventoryService::class)->move($variant->id, $request->source_location_id, 3, 'transfer_in', auth()->id(), InventoryRequest::class, $request->id);
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $count = InventoryMovement::count();

        $response = $this->get(route('activity.index', ['location' => 'ALM']));

        $response->assertSee('data-reversal-open="request-reversal-'.$request->id.'"', false)
            ->assertSee('Confirmar reversión')->assertSee('físicamente a Almacén')
            ->assertViewHas('reversalTransfers', fn ($transfers) => $transfers[$request->id]->count() === 2)
            ->assertViewHas('movements', fn ($rows) => $rows->where('reference_type', InventoryRequest::class)->where('reference_id', $request->id)->count() === 1);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//dialog[@id="request-reversal-'.$request->id.'"]')->length);
        $this->assertSame(2, $xpath->query('//dialog[@id="request-reversal-'.$request->id.'"]//li')->length);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $count);
    }

    public function test_reversal_applies_deltas_to_current_stock_preserving_later_movements(): void
    {
        $request = $this->delivery();
        $variantId = $request->items()->sole()->product_variant_id;
        $warehouse = InventoryLocation::where('code', 'ALM')->sole();
        $laterEntry = app(InventoryService::class)->move($variantId, $warehouse->id, 5, 'purchase', auth()->id());
        $laterUse = app(InventoryService::class)->move($variantId, $request->source_location_id, -1, 'color_bar_depletion', auth()->id());
        $warehouseBefore = (float) InventoryBalance::where('product_variant_id', $variantId)->where('inventory_location_id', $warehouse->id)->sole()->available_quantity;
        $destinationBefore = (float) InventoryBalance::where('product_variant_id', $variantId)->where('inventory_location_id', $request->source_location_id)->sole()->available_quantity;

        $this->post(route('inventory.requests.reverse', $request), ['reason' => 'Devolución de entrega errónea', 'key' => (string) str()->uuid()])->assertSessionHasNoErrors();

        $this->assertSame($warehouseBefore + 2, (float) InventoryBalance::where('product_variant_id', $variantId)->where('inventory_location_id', $warehouse->id)->sole()->available_quantity);
        $this->assertSame($destinationBefore - 2, (float) InventoryBalance::where('product_variant_id', $variantId)->where('inventory_location_id', $request->source_location_id)->sole()->available_quantity);
        $this->assertSame('5.000', $laterEntry->fresh()->quantity);
        $this->assertSame('-1.000', $laterUse->fresh()->quantity);
        $this->get(route('activity.index', ['location' => 'ALM']))->assertSee('Entrega revertida')->assertDontSee('data-reversal-open="request-reversal-'.$request->id.'"', false);
    }

    public function test_sol_20_remains_protected_without_any_stock_or_history_change(): void
    {
        $request = $this->delivery('SOL-000020');
        $before = InventoryBalance::query()->pluck('available_quantity', 'id')->all();
        $count = InventoryMovement::count();
        $this->post(route('inventory.requests.reverse', $request), ['reason' => 'Entrega antigua', 'key' => (string) str()->uuid()])->assertSessionHasErrors('reversal');
        $this->assertSame($before, InventoryBalance::query()->pluck('available_quantity', 'id')->all());
        $this->assertSame($count, InventoryMovement::count());
        $this->assertDatabaseCount('inventory_request_reversals', 0);
        $this->get(route('activity.index', ['location' => 'ALM']))->assertSee('Esta solicitud está protegida')->assertDontSee('action="'.route('inventory.requests.reverse', $request).'"', false);
    }

    public function test_insufficient_destination_units_prevent_every_inverse_movement(): void
    {
        $request = $this->delivery();
        InventoryBalance::query()->where('product_variant_id', $request->items->first()->product_variant_id)->where('inventory_location_id', $request->source_location_id)->update(['available_quantity' => 1]);
        $before = InventoryBalance::query()->pluck('available_quantity', 'id')->all();
        $count = InventoryMovement::count();
        $this->post(route('inventory.requests.reverse', $request), ['reason' => 'Devolución completa', 'key' => (string) str()->uuid()])->assertSessionHasErrors('reversal');
        $this->assertSame($before, InventoryBalance::query()->pluck('available_quantity', 'id')->all());
        $this->assertSame($count, InventoryMovement::count());
    }

    public function test_administrator_in_color_bar_has_no_warehouse_reversal_button(): void
    {
        $request = $this->delivery();

        $this->withSession(['crepe.mode' => 'color-bar'])->get(route('activity.index', ['location' => 'CB']))->assertDontSee('data-reversal-open=');
        $this->post(route('inventory.requests.reverse', $request), ['reason' => 'Entrega por error', 'key' => (string) str()->uuid()])->assertForbidden();
    }

    public function test_warehouse_user_cannot_reverse_a_completed_delivery(): void
    {
        $request = $this->delivery();
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $this->actingAs($user)->get(route('activity.index', ['location' => 'ALM']))->assertDontSee('data-reversal-open=')->assertDontSee('Confirmar reversión');
        $this->post(route('inventory.requests.reverse', $request), ['reason' => 'Error de entrega', 'key' => (string) str()->uuid()])->assertForbidden();
    }
}
