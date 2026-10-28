<?php

namespace Tests\Feature;

use App\Models\ColorFormula;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ColorBarBothPricingTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['fixed'])]
    #[TestWith(['multiplier'])]
    #[TestWith(['both'])]
    public function test_product_editor_preserves_existing_data_and_selections_on_save(string $pricingType): void
    {
        [$user, $variant] = $this->catalog();
        $variant->update(['base_unit' => 'ml', 'content_quantity' => 1000, 'cost' => 12.3456, 'minimum_stock' => 2, 'maximum_stock' => null, 'color_bar_pricing_type' => $pricingType]);
        $variant->product->update(['is_reception_usable' => false, 'is_color_bar_supply' => false, 'description' => 'Conservar descripción', 'image_paths' => ['products/keep.jpg']]);
        $product = $variant->product->fresh();
        $beforeProduct = $product->getAttributes();
        $beforeVariant = $variant->fresh()->getAttributes();
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();

        $response = $this->actingAs($user)->get(route('products.edit', $product))->assertOk()
            ->assertSee('¿Dónde se utiliza?')->assertSee('Uso y cobro en Color Bar')->assertSee('Ambos · elegir en cada uso');
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame($pricingType, $xpath->query('//select[@name="color_bar_pricing_type"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//input[@name="is_reception_usable" and @type="checkbox" and @checked]')->length);
        $this->assertSame(1, $xpath->query('//input[@name="is_color_bar_usable" and @type="checkbox" and @checked]')->length);
        $this->assertSame(0, $xpath->query('//input[@name="is_color_bar_supply" and @type="checkbox" and @checked]')->length);

        $this->put(route('products.update', $product), [...$this->productPayload($variant), 'cost' => '12.35'])->assertSessionHasNoErrors()->assertRedirect(route('products.index'));

        unset($beforeProduct['updated_at'], $beforeVariant['updated_at']);
        $afterProduct = $product->fresh()->getAttributes();
        $afterVariant = $variant->fresh()->getAttributes();
        unset($afterProduct['updated_at'], $afterVariant['updated_at']);
        $this->assertSame($beforeProduct, $afterProduct);
        $this->assertSame($beforeVariant, $afterVariant);
        $beforeQuantities = collect($beforeBalances)->pluck('available_quantity', 'id')->all();
        $this->assertSame($beforeQuantities, InventoryBalance::query()->whereIn('id', array_keys($beforeQuantities))->orderBy('id')->pluck('available_quantity', 'id')->all());
        $this->assertSame(0.0, (float) InventoryBalance::query()->whereNotIn('id', array_keys($beforeQuantities))->sum('available_quantity'));
    }

    public function test_disabling_formula_use_keeps_the_configured_prices_and_mode(): void
    {
        [$user, $variant] = $this->catalog();
        $variant->product->update(['is_reception_usable' => true]);
        $payload = $this->productPayload($variant);
        $payload['is_color_bar_usable'] = 0;

        $this->actingAs($user)->put(route('products.update', $variant->product), $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $variant->product_id, 'is_color_bar_usable' => false]);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'color_bar_pricing_type' => 'both', 'color_bar_sale_price' => 175]);
    }

    public function test_both_requires_a_category_rate_before_it_can_be_enabled(): void
    {
        [$user, $variant] = $this->catalog();
        $variant->update(['color_bar_pricing_type' => 'fixed']);
        $variant->product->category->update(['color_bar_multiplier' => null]);
        $payload = [...$this->productPayload($variant), 'color_bar_pricing_type' => 'both'];

        $this->actingAs($user)->put(route('products.update', $variant->product), $payload)->assertSessionHasErrors([
            'product_category_id' => 'Para usar medida (gr), selecciona una categoría con tarifa por gramo.',
        ]);

        $this->assertSame('fixed', $variant->fresh()->color_bar_pricing_type);
    }

    public function test_both_can_record_the_same_product_by_application_and_grams_without_changing_stock(): void
    {
        [$user, $variant, $ticket] = $this->catalog();
        $user->syncRoles(['Color Bar']);
        $beforeBalances = InventoryBalance::query()->orderBy('id')->get()->toArray();
        $beforeMovements = InventoryMovement::query()->count();

        $this->actingAs($user)->get(route('color-bar.index', ['ticket' => $ticket->id]))->assertOk()
            ->assertViewHas('variants', fn ($variants): bool => $variants->contains('id', $variant->id))
            ->assertSee('¿Cómo aplicarás este producto?')->assertSee('Por medida (gr)')
            ->assertDontSee('data-fixed-price=', false);
        $this->postJson(route('color-bar.store'), ['ticket_id' => $ticket->id, 'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => 35, 'unit' => 'g'],
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit' => 'unidad'],
        ]])->assertOk()->assertExactJson(['saved' => true]);

        $formula = ColorFormula::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $formula->id, 'unit' => 'g', 'quantity' => 35, 'sale_price' => 3.7]);
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $formula->id, 'unit' => 'unidad', 'quantity' => 1, 'sale_price' => 175]);
        $this->assertDatabaseHas('ticket_items', ['ticket_id' => $ticket->id, 'type' => 'color_bar', 'unit' => 'g', 'line_total' => 129.5]);
        $this->assertDatabaseHas('ticket_items', ['ticket_id' => $ticket->id, 'type' => 'color_bar', 'unit' => 'unidad', 'line_total' => 175]);
        $this->assertDatabaseHas('ticket_items', ['ticket_id' => $ticket->id, 'type' => 'color_bar_mc', 'line_total' => 30.45]);
        $rows = $ticket->items()->where('type', 'color_bar')->get()->keyBy('unit');
        $this->assertSame('fixed', $rows['unidad']->metadata['color_bar_pricing_type']);
        $this->assertSame('multiplier', $rows['g']->metadata['color_bar_pricing_type']);
        $beforeQuantities = collect($beforeBalances)->pluck('available_quantity', 'id')->all();
        $this->assertSame($beforeQuantities, InventoryBalance::query()->whereIn('id', array_keys($beforeQuantities))->orderBy('id')->pluck('available_quantity', 'id')->all());
        $this->assertSame(0.0, (float) InventoryBalance::query()->whereNotIn('id', array_keys($beforeQuantities))->sum('available_quantity'));
        $this->assertDatabaseCount('inventory_movements', $beforeMovements);

        $this->get(route('color-bar.index', ['ticket' => $ticket->id, 'formula' => $formula->id]))->assertOk()
            ->assertViewHas('initialFormulaItems', function ($items): bool {
                return $items->pluck('pricingType')->all() === ['multiplier', 'fixed']
                    && $items->every(fn (array $item): bool => $item['availablePricingType'] === 'both' && ! array_key_exists('unitPrice', $item) && ! array_key_exists('rate', $item) && ! array_key_exists('fixedPrice', $item));
            });
        $originalItems = $formula->items()->orderBy('id')->get()->toArray();
        $this->postJson(route('color-bar.update', $formula), ['ticket_id' => $ticket->id, 'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => 42, 'unit' => 'g'],
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit' => 'unidad'],
        ]])->assertOk();
        $this->assertSame($originalItems, $formula->items()->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('color_formulas', ['id' => $formula->id, 'status' => 'corrected']);
        $replacement = ColorFormula::query()->where('supersedes_color_formula_id', $formula->id)->sole();
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $replacement->id, 'unit' => 'g', 'quantity' => 42]);
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $replacement->id, 'unit' => 'unidad', 'quantity' => 1]);
        $beforeQuantities = collect($beforeBalances)->pluck('available_quantity', 'id')->all();
        $this->assertSame($beforeQuantities, InventoryBalance::query()->whereIn('id', array_keys($beforeQuantities))->orderBy('id')->pluck('available_quantity', 'id')->all());
        $this->assertSame(0.0, (float) InventoryBalance::query()->whereNotIn('id', array_keys($beforeQuantities))->sum('available_quantity'));
    }

    #[TestWith([null])]
    #[TestWith(['ml'])]
    public function test_both_rejects_an_absent_or_invalid_application_mode_without_recording_charges(?string $unit): void
    {
        [$user, $variant, $ticket] = $this->catalog();
        $beforeItems = $ticket->items()->get()->toArray();

        $this->actingAs($user)->postJson(route('color-bar.store'), ['ticket_id' => $ticket->id, 'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => 35, 'unit' => $unit],
        ]])->assertUnprocessable()->assertJsonValidationErrors('items.0.unit');

        $this->assertDatabaseMissing('color_formulas', ['ticket_id' => $ticket->id]);
        $this->assertSame($beforeItems, $ticket->items()->get()->toArray());
    }

    public function test_both_allows_zero_prices_in_each_mode(): void
    {
        [$user, $variant, $ticket] = $this->catalog();
        $variant->update(['color_bar_sale_price' => 0]);
        $variant->product->category->update(['color_bar_multiplier' => 0]);

        $this->actingAs($user)->postJson(route('color-bar.store'), ['ticket_id' => $ticket->id, 'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit' => 'g'],
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit' => 'unidad'],
        ]])->assertOk();

        $formula = ColorFormula::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $formula->id, 'unit' => 'g', 'quantity' => 10, 'sale_price' => 0]);
        $this->assertDatabaseHas('color_formula_items', ['color_formula_id' => $formula->id, 'unit' => 'unidad', 'quantity' => 1, 'sale_price' => 0]);
        $this->assertSame(0.0, (float) $ticket->items()->whereIn('type', ['color_bar', 'color_bar_mc'])->sum('line_total'));
    }

    public function test_validation_redisplays_each_draft_items_chosen_mode(): void
    {
        [$user, $variant, $ticket] = $this->catalog();
        $this->actingAs($user)->withSession(['_old_input' => ['ticket_id' => $ticket->id, 'items' => [
            ['product_variant_id' => $variant->id, 'quantity' => 20, 'unit' => 'g'],
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'unit' => 'unidad'],
        ]]])->get(route('color-bar.index', ['ticket' => $ticket->id]))->assertOk()
            ->assertViewHas('initialFormulaItems', fn ($items): bool => $items->pluck('pricingType')->all() === ['multiplier', 'fixed'] && $items->pluck('unit')->all() === ['g', 'unidad']);
    }

    /** @return array{User, ProductVariant, Ticket} */
    private function catalog(): array
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $variant = ProductVariant::query()->where('base_unit', 'g')->whereHas('product', fn ($products) => $products->where('is_color_bar_usable', true))->firstOrFail();
        $variant->product->category->update(['color_bar_multiplier' => 3.7]);
        $variant->update(['color_bar_pricing_type' => 'both', 'color_bar_sale_price' => 175]);
        $ticket = Ticket::query()->whereHas('customer', fn ($customers) => $customers->where('email', 'fernanda.ruiz@example.com'))->firstOrFail();
        $ticket->appointment->update(['starts_at' => today()->setTime(10, 0), 'ends_at' => today()->setTime(12, 0)]);

        return [$user, $variant, $ticket];
    }

    private function productPayload(ProductVariant $variant): array
    {
        $product = $variant->product->fresh();

        return [
            'name' => $product->name, 'product_category_id' => $product->product_category_id, 'product_brand_id' => $product->product_brand_id,
            'preferred_supplier_id' => $product->preferred_supplier_id, 'sku' => $product->sku, 'status' => $product->status, 'description' => $product->description,
            'is_reception_usable' => (int) $product->is_reception_usable, 'is_color_bar_usable' => (int) $product->is_color_bar_usable, 'is_color_bar_supply' => (int) $product->isColorBarSupply(),
            'variant_name' => $variant->name, 'variant_sku' => $variant->sku, 'base_unit' => $variant->base_unit, 'content_quantity' => $variant->content_quantity,
            'cost' => $variant->cost, 'sale_price' => $variant->sale_price, 'color_bar_sale_price' => $variant->color_bar_sale_price, 'color_bar_pricing_type' => $variant->color_bar_pricing_type,
            'minimum_stock' => $variant->minimum_stock, 'maximum_stock' => $variant->maximum_stock,
        ];
    }
}
