<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\InventorySpreadsheetService;
use App\Services\SpreadsheetFile;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WarehouseInlineEditTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(): array
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::where('sku', 'SH-REST-STD')->firstOrFail();
        $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen']);
        foreach (InventoryLocation::whereIn('code', ['ALM', 'REC', 'CB'])->get() as $location) {
            InventoryBalance::firstOrCreate(['product_variant_id' => $variant->id, 'inventory_location_id' => $location->id], ['available_quantity' => 0]);
        }

        return [$admin, $variant];
    }

    private function change(ProductVariant $variant, string $field, mixed $before, mixed $value, ?string $code = null): array
    {
        return ['variant_id' => $variant->id, 'field' => $field, 'before' => $before, 'value' => $value, 'location_code' => $code];
    }

    private function payload(array $changes, ?string $reason = null): array
    {
        return ['key' => (string) Str::uuid(), 'changes' => $changes, 'reason' => $reason];
    }

    public function test_inline_prices_and_warehouse_limits_save_together_without_inventory_movements_or_changing_other_limits(): void
    {
        [$admin, $variant] = $this->warehouse();
        $variant->update(['cost' => 10.1234, 'sale_price' => 15, 'color_bar_sale_price' => 27]);
        $warehouse = InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $warehouse->update(['minimum_stock' => 2, 'maximum_stock' => 8, 'limits_configured' => true]);
        $balances = InventoryBalance::query()->whereKeyNot($warehouse->id)->get()->toArray();
        $quantities = InventoryBalance::pluck('available_quantity', 'id')->all();
        $movements = InventoryMovement::count();
        $payload = $this->payload([$this->change($variant, 'cost', '10.1234', '12.50'), $this->change($variant, 'sale_price', '15.00', '20.25'), $this->change($variant, 'minimum_stock', 2, 4, 'ALM'), $this->change($variant, 'maximum_stock', 8, 12, 'ALM')]);

        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();
        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();

        $this->assertSame('12.5000', $variant->fresh()->cost);
        $this->assertSame('20.25', $variant->fresh()->sale_price);
        $this->assertSame('27.00', $variant->fresh()->color_bar_sale_price);
        $this->assertSame('4.000', $warehouse->fresh()->minimum_stock);
        $this->assertSame('12.000', $warehouse->fresh()->maximum_stock);
        $this->assertSame($quantities, InventoryBalance::pluck('available_quantity', 'id')->all());
        $this->assertSame($balances, InventoryBalance::query()->whereKeyNot($warehouse->id)->get()->toArray());
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(1, AuditLog::where('action', 'inventory.inline.saved')->where('user_id', $admin->id)->count());
    }

    public function test_quantity_adjustment_requires_reason_and_replays_without_duplicate_movements(): void
    {
        [, $variant] = $this->warehouse();
        $balance = InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $before = InventoryMovement::count();
        $payload = $this->payload([$this->change($variant, 'quantity', $balance->available_quantity, 3, 'CB')]);
        $this->postJson(route('inventory.inline.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame($before, InventoryMovement::count());
        $payload['reason'] = 'Conteo de envases';

        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();
        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();

        $this->assertSame('3.000', $balance->fresh()->available_quantity);
        $this->assertSame($before + 1, InventoryMovement::count());
        $this->assertDatabaseCount('inventory_adjustment_batches', 1);
        $this->assertDatabaseHas('inventory_movements', ['product_variant_id' => $variant->id, 'inventory_location_id' => $balance->inventory_location_id, 'type' => 'adjustment', 'before_quantity' => $balance->available_quantity, 'after_quantity' => 3]);
    }

    public function test_concurrent_stock_and_cost_changes_are_preserved_when_only_price_is_edited(): void
    {
        [, $variant] = $this->warehouse();
        $payload = $this->payload([$this->change($variant, 'sale_price', $variant->sale_price, '88.50')]);
        $variant->update(['cost' => 16.7891]);
        $balance = InventoryBalance::where('product_variant_id', $variant->id)->firstOrFail();
        $balance->update(['available_quantity' => 19]);
        $movements = InventoryMovement::count();

        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();

        $this->assertSame('88.50', $variant->fresh()->sale_price);
        $this->assertSame('16.7891', $variant->fresh()->cost);
        $this->assertSame('19.000', $balance->fresh()->available_quantity);
        $this->assertSame($movements, InventoryMovement::count());
    }

    public function test_conflicting_field_rejects_entire_bundle_including_other_products(): void
    {
        [, $variant] = $this->warehouse();
        $other = ProductVariant::where('id', '!=', $variant->id)->firstOrFail();
        $originalCost = $other->cost;
        $payload = $this->payload([$this->change($other, 'cost', $other->cost, '34.00'), $this->change($variant, 'sale_price', $variant->sale_price, '90.00')]);
        $variant->update(['sale_price' => 25]);

        $this->postJson(route('inventory.inline.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('changes');

        $this->assertSame($originalCost, $other->fresh()->cost);
        $this->assertSame('25.00', $variant->fresh()->sale_price);
        $this->assertSame(0, AuditLog::where('action', 'inventory.inline.saved')->count());
    }

    #[TestWith(['quantity', 'REC', '1.5'])]
    #[TestWith(['cost', null, '-2'])]
    #[TestWith(['sale_price', null, '1.234'])]
    #[TestWith(['minimum_stock', 'REC', '2'])]
    public function test_invalid_counts_prices_and_non_warehouse_limits_are_rejected(string $field, ?string $code, string $value): void
    {
        [, $variant] = $this->warehouse();
        $current = in_array($field, ['cost', 'sale_price'], true) ? $variant->$field : InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', $code))->value($field === 'quantity' ? 'available_quantity' : $field);
        $before = DB::table('inventory_balances')->get()->map(fn ($row) => (array) $row)->all();

        $this->postJson(route('inventory.inline.store'), $this->payload([$this->change($variant, $field, $current, $value, $code)], 'Conteo de prueba'))->assertUnprocessable();

        $this->assertSame($before, DB::table('inventory_balances')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, AuditLog::where('action', 'inventory.inline.saved')->count());
    }

    public function test_lupis_can_only_edit_reception_price_and_cannot_change_cost_stock_or_limits(): void
    {
        [, $variant] = $this->warehouse();
        $lupis = User::factory()->create();
        $lupis->assignRole('Recepción');
        AppSetting::updateOrCreate(['key' => 'products.reception_price_only_user_ids'], ['value' => json_encode([$lupis->id])]);
        $variant->product->update(['is_reception_usable' => true]);
        $this->actingAs($lupis)->withSession(['crepe.mode' => 'almacen']);
        $this->get(route('inventory.index', ['search' => $variant->sku]))->assertOk()->assertDontSee('Costo por unidad')->assertDontSee('data-field="quantity"', false)->assertSee('data-field="sale_price"', false);
        foreach (['cost' => null, 'quantity' => 'ALM', 'minimum_stock' => 'ALM'] as $field => $location) {
            $this->postJson(route('inventory.inline.store'), $this->payload([$this->change($variant, $field, 0, 1, $location)]))->assertForbidden();
        }

        $this->postJson(route('inventory.inline.store'), $this->payload([$this->change($variant, 'sale_price', $variant->sale_price, '31.00')]))->assertOk();

        $this->assertSame('31.00', $variant->fresh()->sale_price);
        $variant->product->update(['is_reception_usable' => false]);
        $this->postJson(route('inventory.inline.store'), $this->payload([$this->change($variant, 'sale_price', '31.00', '32.00')]))->assertForbidden();
    }

    #[TestWith(['recepcion', 'REC', 'Inventario Recepción'])]
    #[TestWith(['color-bar', 'CB', 'Inventario Color Bar'])]
    public function test_local_inventory_has_no_limits_and_cannot_submit_joint_edits(string $mode, string $code, string $title): void
    {
        [, $variant] = $this->warehouse();
        $this->withSession(['crepe.mode' => $mode]);

        $this->get(route('inventory.index', ['location' => $code, 'low_stock' => 1]))->assertOk()->assertSee($title)->assertDontSee('MÍN. / MÁX.')->assertDontSee('Bajo mínimo')->assertViewHas('lowStock', false);
        $this->postJson(route('inventory.inline.store'), $this->payload([$this->change($variant, 'sale_price', $variant->sale_price, 10)]))->assertForbidden();
    }

    public function test_legacy_color_bar_excel_keeps_limits_and_updates_only_price_without_movements(): void
    {
        [$admin, $variant] = $this->warehouse();
        $balance = InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $balance->update(['minimum_stock' => 20, 'maximum_stock' => 30, 'limits_configured' => true]);
        $service = app(InventorySpreadsheetService::class);
        $files = app(SpreadsheetFile::class);
        $path = tempnam(sys_get_temp_dir(), 'cb-limits');
        try {
            file_put_contents($path, $service->export('csv', 'CB'));
            $rows = $files->read($path, 'csv', 'Inventario', 16);
            $row = collect($rows)->first(fn ($row) => ($row['A'] ?? null) === (string) $balance->id);
            $this->assertSame('', $row['K']);
            $this->assertSame('', $row['L']);
            $row['K'] = '100';
            $row['L'] = '1';
            $row['O'] = '97.50';
            file_put_contents($path, $files->writeCsv([InventorySpreadsheetService::Headers, array_values($row)]));
            $movements = InventoryMovement::count();

            $preview = $service->preview($path, 'csv', 'CB');
            $this->assertSame([], $preview['errors'] ?? []);
            $service->apply($preview['changes'], $admin->id, 'Cambio de precio', 'CB', 'csv');

            $this->assertSame('20.000', $balance->fresh()->minimum_stock);
            $this->assertSame('30.000', $balance->fresh()->maximum_stock);
            $this->assertSame('97.50', $variant->fresh()->sale_price);
            $this->assertSame($movements, InventoryMovement::count());
        } finally {
            unlink($path);
        }
    }

    public function test_product_catalog_shows_real_location_counts_and_inline_prices_only_in_warehouse(): void
    {
        [, $variant] = $this->warehouse();
        $this->get(route('products.index', ['search' => $variant->sku]))->assertOk()->assertSee('Existencias por ubicación')->assertSee('data-product-id="'.$variant->product_id.'"', false)->assertSee('data-field="sale_price"', false);
        $this->withSession(['crepe.mode' => 'recepcion'])->get(route('products.index'))->assertOk()->assertDontSee('location-breakdown')->assertDontSee('data-inline-root');
    }

    public function test_combined_count_price_and_limits_keep_concurrent_unedited_values(): void
    {
        [, $variant] = $this->warehouse();
        $balance = InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $balance->update(['minimum_stock' => 2, 'maximum_stock' => 10, 'limits_configured' => true]);
        $payload = $this->payload([$this->change($variant, 'quantity', $balance->available_quantity, 8, 'ALM'), $this->change($variant, 'minimum_stock', 2, 3, 'ALM'), $this->change($variant, 'sale_price', $variant->sale_price, 0)], 'Conteo físico combinado');
        $balance->update(['maximum_stock' => 12]);
        $movements = InventoryMovement::count();

        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();

        $this->assertSame('8.000', $balance->fresh()->available_quantity);
        $this->assertSame('3.000', $balance->fresh()->minimum_stock);
        $this->assertSame('12.000', $balance->fresh()->maximum_stock);
        $this->assertSame('0.00', $variant->fresh()->sale_price);
        $this->assertSame($movements + 1, InventoryMovement::count());
    }

    public function test_invalid_limit_bundle_does_not_apply_other_fields_and_maximum_can_be_removed_explicitly(): void
    {
        [, $variant] = $this->warehouse();
        $balance = InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'ALM'))->firstOrFail();
        $balance->update(['minimum_stock' => 2, 'maximum_stock' => 5, 'limits_configured' => true]);
        $originalPrice = $variant->sale_price;
        $payload = $this->payload([$this->change($variant, 'sale_price', $originalPrice, 30), $this->change($variant, 'minimum_stock', 2, 7, 'ALM')]);

        $this->postJson(route('inventory.inline.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->assertSame($originalPrice, $variant->fresh()->sale_price);
        $this->assertSame('2.000', $balance->fresh()->minimum_stock);
        $this->postJson(route('inventory.inline.store'), $this->payload([$this->change($variant, 'maximum_stock', 5, null, 'ALM')]))->assertOk();
        $this->assertNull($balance->fresh()->maximum_stock);
    }

    public function test_changed_stock_conflict_rolls_back_price_and_reused_key_cannot_apply_different_changes(): void
    {
        [, $variant] = $this->warehouse();
        $balance = InventoryBalance::where('product_variant_id', $variant->id)->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $oldPrice = $variant->sale_price;
        $payload = $this->payload([$this->change($variant, 'sale_price', $oldPrice, 30), $this->change($variant, 'quantity', $balance->available_quantity, 2, 'CB')], 'Conteo de prueba');
        $balance->update(['available_quantity' => 1]);
        $movements = InventoryMovement::count();

        $this->postJson(route('inventory.inline.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('changes');
        $this->assertSame($oldPrice, $variant->fresh()->sale_price);
        $this->assertSame('1.000', $balance->fresh()->available_quantity);
        $this->assertSame($movements, InventoryMovement::count());
        $payload = $this->payload([$this->change($variant, 'sale_price', $oldPrice, 30)]);
        $this->postJson(route('inventory.inline.store'), $payload)->assertOk();
        $payload['changes'][0]['value'] = 31;
        $this->postJson(route('inventory.inline.store'), $payload)->assertConflict();
        $this->assertSame('30.00', $variant->fresh()->sale_price);
    }
}
