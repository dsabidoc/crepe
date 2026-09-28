<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_filters_keep_only_the_requested_scope(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('products.index', ['scope' => 'color_bar']))
            ->assertOk()
            ->assertSee('Wella Koleston 7/1')
            ->assertDontSee('Shampoo Restore');

        $this->actingAs($admin)
            ->get(route('services.index', ['color_bar' => 'yes']))
            ->assertOk()
            ->assertSee('Balayage')
            ->assertDontSee('<strong>Corte</strong>', false);
    }
}
