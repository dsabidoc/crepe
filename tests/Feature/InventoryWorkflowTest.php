<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryLimitService;
use App\Services\InventorySpreadsheetService;
use App\Services\InventoryWorkflowService;
use App\Services\SpreadsheetFile;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_and_confirmation_adjust_only_changed_location_once_and_preserve_original_movements(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $balance = InventoryBalance::query()->with(['variant', 'location'])->whereHas('location', fn ($q) => $q->where('code', 'ALM'))->firstOrFail();
        $allBefore = InventoryBalance::query()->pluck('available_quantity', 'id')->all();
        $limits = InventoryLimitService::values($balance, $balance->variant);
        $movements = InventoryMovement::count();
        $this->actingAs($admin)->withSession(['crepe.mode' => 'almacen'])->post(route('inventory.adjustments.review'), ['reason' => 'Conteo físico', 'items' => [['id' => $balance->id, 'quantity' => 3, 'before' => $balance->available_quantity, 'minimum_stock' => 1, 'maximum_stock' => 5, 'limits_control' => hash_hmac('sha256', json_encode([$balance->id, $limits], JSON_THROW_ON_ERROR), config('app.key'))]]])->assertOk()->assertSee('TODAVÍA SIN APLICAR');
        $this->assertSame($allBefore, InventoryBalance::query()->pluck('available_quantity', 'id')->all());
        $key = array_key_first(session('inventory.workflow'));
        $this->post(route('inventory.adjustments.store'), ['key' => $key])->assertSessionHasNoErrors();
        $this->post(route('inventory.adjustments.store'), ['key' => $key])->assertSessionHasNoErrors();
        $this->assertSame('3.000', $balance->fresh()->available_quantity);
        $this->assertSame('1.000', $balance->fresh()->minimum_stock);
        $this->assertSame($movements + ((float) $balance->available_quantity === 3.0 ? 0 : 1), InventoryMovement::count());
        foreach ($allBefore as $id => $quantity) {
            if ($id !== $balance->id) {
                $this->assertSame($quantity, InventoryBalance::findOrFail($id)->available_quantity);
            }
        }
        $this->assertDatabaseCount('inventory_adjustment_batches', 1);
    }

    public function test_concurrent_count_change_rejects_entire_reviewed_batch(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $balance = InventoryBalance::query()->with('variant')->firstOrFail();
        $limits = InventoryLimitService::values($balance, $balance->variant);
        $change = ['id' => $balance->id, 'before' => $balance->available_quantity, 'after' => 2, 'minimum_stock' => (int) $limits['minimum_stock'], 'maximum_stock' => (int) $limits['maximum_stock'], 'limits_before' => $limits];
        $balance->increment('available_quantity');
        try {
            app(InventoryWorkflowService::class)->adjust([$change], $admin, 'Conteo revisión', (string) str()->uuid());
            $this->fail('A stale review must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_adjustment_batches', 0);
            $this->assertSame((float) $change['before'] + 1, (float) $balance->fresh()->available_quantity);
        }
    }

    public function test_warehouse_limits_round_trip_through_excel_without_overwriting_other_locations_or_global_prices(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $balance = InventoryBalance::query()->with(['variant', 'location'])->whereHas('location', fn ($q) => $q->where('code', 'ALM'))->firstOrFail();
        $variantBefore = $balance->variant->getAttributes();
        $otherBalances = InventoryBalance::query()->whereKeyNot($balance->id)->get()->keyBy('id')->toArray();
        app(InventoryLimitService::class)->update($balance->variant, $balance->location, ['minimum_stock' => 1, 'maximum_stock' => 7]);
        $service = app(InventorySpreadsheetService::class);
        $files = app(SpreadsheetFile::class);
        $path = tempnam(sys_get_temp_dir(), 'location-limits');
        try {
            file_put_contents($path, $service->export('csv', 'ALM'));
            $rows = $files->read($path, 'csv', 'Inventario', 16);
            $updatedRows = [InventorySpreadsheetService::Headers];
            foreach ($rows as $number => $row) {
                if ($number === 1) {
                    continue;
                }
                if ((int) $row['A'] === $balance->id) {
                    $this->assertSame('1', $row['K']);
                    $this->assertSame('7', $row['L']);
                    $row['K'] = '2';
                    $row['L'] = '9';
                }
                $updatedRows[] = array_values($row);
            }
            file_put_contents($path, $files->writeCsv($updatedRows));
            $preview = $service->preview($path, 'csv', 'ALM');
            $this->assertSame([], $preview['errors'] ?? []);
            $this->assertCount(1, $preview['changes']);
            $count = InventoryMovement::count();
            $service->apply($preview['changes'], $admin->id, 'Límites Almacén', 'ALM', 'csv');
            $this->assertSame('2.000', $balance->fresh()->minimum_stock);
            $this->assertSame('9.000', $balance->fresh()->maximum_stock);
            $this->assertSame(number_format((float) $variantBefore['sale_price'], 2, '.', ''), $balance->variant->fresh()->sale_price);
            $this->assertSame(number_format((float) $variantBefore['cost'], 4, '.', ''), $balance->variant->fresh()->cost);
            $this->assertSame('2.000', $balance->variant->fresh()->minimum_stock);
            $this->assertSame($otherBalances, InventoryBalance::query()->whereKeyNot($balance->id)->get()->keyBy('id')->toArray());
            $this->assertSame($count, InventoryMovement::count());
            $again = $service->preview($path, 'csv', 'ALM');
            $this->assertSame([], $again['changes']);
            $this->assertSame([], $again['errors'] ?? []);
        } finally {
            unlink($path);
        }
    }

    public function test_positive_color_bar_stock_stays_visible_even_when_supply_flag_is_off(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::where('email', 'hi@davidsabido.com')->firstOrFail();
        $balance = InventoryBalance::query()->whereHas('location', fn ($query) => $query->where('code', 'CB'))->firstOrFail();
        $balance->variant->product->update(['is_color_bar_supply' => false, 'is_color_bar_usable' => true]);
        $this->actingAs($admin)->get(route('inventory.index', ['location' => 'CB']))->assertOk()->assertSee('no habilitado como insumo')->assertViewHas('balances', fn ($balances) => $balances->contains('id', $balance->id));
        $balance->update(['available_quantity' => 0]);
        $this->get(route('inventory.index', ['location' => 'CB']))->assertViewHas('balances', fn ($balances) => ! $balances->contains('id', $balance->id));
    }

    public function test_non_admin_cannot_submit_counts_or_stock_limits(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Almacén');
        $this->actingAs($user)->withSession(['crepe.mode' => 'almacen'])->get(route('inventory.matrix'))->assertRedirect(route('inventory.index', ['location' => 'ALM']));
        $this->get(route('inventory.index', ['location' => 'ALM']))->assertOk();
        $this->post(route('inventory.adjustments.review'), [])->assertForbidden();
        $this->post(route('inventory.adjustments.store'), [])->assertForbidden();
    }
}
