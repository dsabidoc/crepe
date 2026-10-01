<?php

namespace Tests\Feature;

use App\Models\ProductCommissionRule;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsCommissionRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_update_and_delete_a_product_commission_rule(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $rule = ProductCommissionRule::query()->create([
            'minimum_sales' => 1,
            'maximum_sales' => 5,
            'commission_rate' => 5,
            'positions' => ['Estilista'],
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->put(route('settings.product-commission-rules.update', $rule), [
                'minimum_sales' => 6,
                'maximum_sales' => 10,
                'commission_rate' => 7.5,
                'positions' => ['Estilista'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('product_commission_rules', [
            'id' => $rule->id,
            'minimum_sales' => 6,
            'maximum_sales' => 10,
            'commission_rate' => 7.5,
        ]);

        $this->actingAs($administrator)
            ->delete(route('settings.product-commission-rules.destroy', $rule))
            ->assertRedirect();

        $this->assertDatabaseMissing('product_commission_rules', ['id' => $rule->id]);
    }
}
