<?php

namespace Tests\Feature;

use App\Models\ColorBarDepletionBatch;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\InventoryRequest;
use App\Models\User;
use App\Services\WorkflowNotificationService;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ColorBarDepletionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_depletions_are_recorded_together_with_audit_and_without_affecting_formulas(): void
    {
        [$user, $first, $second] = $this->inventory();
        $beforeOther = InventoryBalance::whereNotIn('id', [$first->id, $second->id])->orderBy('id')->get()->toArray();
        $payload = $this->payload($first, $second);
        $this->actingAs($user)->withSession(['crepe.mode' => 'color-bar'])
            ->get(route('inventory.color-bar.depletions.create'))->assertOk()->assertSee('Baja de insumos')->assertDontSee('data-rate=', false);

        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertRedirect(route('inventory.color-bar.depletions.create'))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('inventory_requests', 0);
        $batch = ColorBarDepletionBatch::sole();
        $this->assertSame($user->id, $batch->created_by);
        $this->assertSame('Turno de la mañana', $batch->notes);
        $this->assertSame(2, $batch->movements()->count());
        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 1000]);
        $this->assertDatabaseHas('inventory_balances', ['id' => $second->id, 'available_quantity' => 0]);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => ColorBarDepletionBatch::class, 'reference_id' => $batch->id, 'product_variant_id' => $first->product_variant_id, 'quantity' => -1000, 'before_quantity' => 2000, 'after_quantity' => 1000, 'created_by' => $user->id]);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => ColorBarDepletionBatch::class, 'reference_id' => $batch->id, 'product_variant_id' => $second->product_variant_id, 'quantity' => -965, 'after_quantity' => 0]);
        $this->assertSame($beforeOther, InventoryBalance::whereNotIn('id', [$first->id, $second->id])->orderBy('id')->get()->toArray());
        $this->get(route('color-bar.index'))->assertOk()->assertViewHas('variants', fn ($variants) => $variants->contains('id', $second->product_variant_id));
        $this->get(route('inventory.color-bar.depletions.create'))->assertOk()->assertSee('Baja #'.$batch->id)->assertSee('Turno de la mañana');
        $this->get(route('activity.index', ['location' => 'CB']))->assertOk()->assertSee('Baja #'.$batch->id);
    }

    public function test_repeated_submission_is_idempotent_and_changed_replay_is_rejected(): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasNoErrors();
        $movements = InventoryMovement::count();
        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('color_bar_depletion_batches', 1);
        $this->assertDatabaseCount('inventory_movements', $movements);
        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 1000]);
        $payload['items'][0]['units'] = 2;
        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('inventory_movements', $movements);
    }

    #[TestWith([0])]
    #[TestWith([1965])]
    public function test_if_stock_changes_before_confirmation_no_part_of_the_batch_is_applied(float $newStock): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $second->update(['available_quantity' => $newStock]);
        $beforeMovements = InventoryMovement::count();

        $this->actingAs($user)->from(route('inventory.color-bar.depletions.create'))->post(route('inventory.color-bar.depletions.store'), $payload)
            ->assertSessionHasErrors(['items' => 'La existencia de '.$second->variant->product->name.' cambió. Revisa el saldo actualizado y confirma nuevamente; no se aplicó ninguna baja.']);

        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 2000]);
        $this->assertDatabaseHas('inventory_balances', ['id' => $second->id, 'available_quantity' => $newStock]);
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
        $this->get(route('inventory.color-bar.depletions.create'))->assertOk()->assertSee('value="'.$payload['request_key'].'"', false);
    }

    #[TestWith(['warehouse'])]
    #[TestWith(['formula_only'])]
    #[TestWith(['inactive'])]
    #[TestWith(['missing'])]
    public function test_batch_rejects_ineligible_inventory(string $case): void
    {
        [$user, $first, $second] = $this->inventory();
        if ($case === 'warehouse') {
            $second = InventoryBalance::whereHas('location', fn ($q) => $q->where('code', 'ALM'))->firstOrFail();
        } elseif ($case === 'formula_only') {
            $second->variant->product->update(['is_color_bar_supply' => false]);
        } elseif ($case === 'inactive') {
            $second->variant->product->update(['status' => 'inactive']);
        }
        $payload = $this->payload($first, $second);
        if ($case === 'missing') {
            $payload['items'][1]['balance_id'] = 999999;
        }
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors(['items' => 'Selecciona únicamente insumos activos del inventario de Color Bar.']);
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([0.5])]
    #[TestWith([3])]
    public function test_invalid_quantities_do_not_apply_any_depletion(float $units): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $payload['items'][0]['units'] = $units;
        $before = InventoryBalance::orderBy('id')->get()->toArray();
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors();
        $this->assertSame($before, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
    }

    public function test_empty_and_duplicate_selection_are_rejected(): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), [...$payload, 'items' => []])->assertSessionHasErrors(['items' => 'Selecciona al menos un insumo para dar de baja.']);
        $payload['items'][1]['balance_id'] = $first->id;
        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors('items.0.balance_id');
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
    }

    public function test_batch_requires_color_bar_permission_for_read_and_write(): void
    {
        [$user, $first, $second] = $this->inventory();
        $this->get(route('inventory.color-bar.depletions.create'))->assertRedirect(route('login'));
        $this->post(route('inventory.color-bar.depletions.store'), $this->payload($first, $second))->assertRedirect(route('login'));
        $user->syncRoles(['Almacén']);
        $this->actingAs($user)->get(route('inventory.color-bar.depletions.create'))->assertForbidden();
        $this->post(route('inventory.color-bar.depletions.store'), $this->payload($first, $second))->assertForbidden();
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
    }

    public function test_mixed_batch_creates_one_request_with_independent_quantities_and_safe_replay(): void
    {
        [$user, $first, $second] = $this->inventory();
        $warehouseUser = User::factory()->create();
        $warehouseUser->assignRole('Almacén');
        $payload = $this->payload($first, $second);
        $payload['items'][0]['requested_units'] = 3;
        $this->actingAs($user)->withSession(['crepe.mode' => 'color-bar'])
            ->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasNoErrors();

        $batch = ColorBarDepletionBatch::sole();
        $request = InventoryRequest::sole();
        $this->assertSame($request->id, $batch->inventory_request_id);
        $this->assertSame($user->id, $request->requested_by);
        $this->assertSame('CB', $request->sourceLocation->code);
        $this->assertSame('pending', $request->status);
        $this->assertSame(1, $request->items()->count());
        $this->assertDatabaseHas('inventory_request_items', ['inventory_request_id' => $request->id, 'product_variant_id' => $first->product_variant_id, 'requested_units' => 3, 'requested_quantity' => 3000]);
        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 1000]);
        $this->assertDatabaseHas('inventory_balances', ['id' => $second->id, 'available_quantity' => 0]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.request.created', 'subject_id' => $request->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('notifications', ['type' => 'inventory.request.pending', 'notifiable_id' => $warehouseUser->id, 'subject_id' => $request->id]);
        $notifications = DB::table('notifications')->count();
        $movements = InventoryMovement::count();
        $this->get(route('inventory.color-bar.depletions.create'))->assertOk()->assertSee($request->code)->assertSee('Solicitados: 3 envases');
        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_requests', 1);
        $this->assertDatabaseCount('inventory_movements', $movements);
        $this->assertDatabaseCount('notifications', $notifications);
        $payload['items'][0]['requested_units'] = 4;
        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('inventory_requests', 1);
    }

    public function test_multiple_replenishments_are_grouped_and_stale_stock_rolls_back_everything(): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $payload['items'][0]['requested_units'] = 3;
        $payload['items'][1]['requested_units'] = 2;
        $second->update(['available_quantity' => 500]);
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors('items');
        $this->assertDatabaseCount('inventory_requests', 0);
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 2000]);
        $payload['items'][1]['expected_quantity'] = 500;
        $this->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('inventory_requests', 1);
        $this->assertDatabaseCount('inventory_request_items', 2);
        $this->assertDatabaseHas('inventory_request_items', ['product_variant_id' => $second->product_variant_id, 'requested_units' => 2, 'requested_quantity' => 2000]);
    }

    public function test_request_failure_rolls_back_depletions_request_and_audit(): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $payload['items'][0]['requested_units'] = 3;
        $beforeBalances = InventoryBalance::orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::count();
        $beforeAudit = DB::table('audit_logs')->count();
        $this->mock(WorkflowNotificationService::class)->shouldReceive('inventoryRequested')->once()->andThrow(new \RuntimeException('Request unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload);
            $this->fail('The simulated request failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Request unavailable', $exception->getMessage());
        }
        $this->assertSame($beforeBalances, InventoryBalance::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);
        $this->assertDatabaseCount('audit_logs', $beforeAudit);
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
        $this->assertDatabaseCount('inventory_requests', 0);
        $this->assertDatabaseCount('inventory_request_items', 0);
    }

    #[TestWith([-1])]
    #[TestWith([0.5])]
    #[TestWith([1000001])]
    public function test_invalid_replenishment_quantities_do_not_apply_depletions(float $units): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $payload['items'][0]['requested_units'] = $units;
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasErrors('items.0.requested_units');
        $this->assertDatabaseCount('inventory_requests', 0);
        $this->assertDatabaseCount('color_bar_depletion_batches', 0);
        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 2000]);
    }

    public function test_legacy_batch_hash_is_compatible_with_zero_replenishment_fields(): void
    {
        [$user, $first, $second] = $this->inventory();
        $payload = $this->payload($first, $second);
        $legacyItems = collect($payload['items'])->map(fn (array $item): array => [
            'balance_id' => (int) $item['balance_id'], 'expected_quantity' => round((float) $item['expected_quantity'], 3),
            'units' => (int) ($item['units'] ?? 0), 'empty_stock' => (bool) ($item['empty_stock'] ?? false),
        ])->sortBy('balance_id')->values()->all();
        ColorBarDepletionBatch::factory()->create(['created_by' => $user->id, 'request_key' => $payload['request_key'], 'request_hash' => hash('sha256', json_encode([$legacyItems, $payload['notes']], JSON_THROW_ON_ERROR))]);
        $payload['items'][0]['requested_units'] = 0;
        $this->actingAs($user)->post(route('inventory.color-bar.depletions.store'), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('color_bar_depletion_batches', 1);
        $this->assertDatabaseCount('inventory_requests', 0);
        $this->assertDatabaseHas('inventory_balances', ['id' => $first->id, 'available_quantity' => 2000]);
    }

    /** @return array{User, InventoryBalance, InventoryBalance} */
    private function inventory(): array
    {
        $this->seed(CrepeSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Color Bar');
        $balances = InventoryBalance::with('variant.product')->whereHas('location', fn ($q) => $q->where('code', 'CB'))
            ->whereHas('variant.product', fn ($q) => $q->where('is_color_bar_usable', true))->orderBy('id')->take(2)->get();
        foreach ($balances as $balance) {
            $balance->variant->update(['content_quantity' => 1000]);
            $balance->variant->product->update(['is_color_bar_supply' => true]);
        }
        $balances[0]->update(['available_quantity' => 2000]);
        $balances[1]->update(['available_quantity' => 965]);

        return [$user, $balances[0], $balances[1]];
    }

    /** @return array{request_key: string, notes: string, items: array} */
    private function payload(InventoryBalance $first, InventoryBalance $second): array
    {
        return ['request_key' => (string) Str::uuid(), 'notes' => 'Turno de la mañana', 'items' => [
            ['balance_id' => $first->id, 'expected_quantity' => (float) $first->available_quantity, 'units' => 1],
            ['balance_id' => $second->id, 'expected_quantity' => (float) $second->available_quantity, 'empty_stock' => true],
        ]];
    }
}
