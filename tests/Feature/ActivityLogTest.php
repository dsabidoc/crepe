<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_and_request_logs_have_a_dedicated_paginated_module(): void
    {
        $this->seed(CrepeSeeder::class);
        $admin = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($admin)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('activity.index', ['location' => 'ALM']))
            ->assertOk()
            ->assertSee('Bitácoras')
            ->assertSee('Movimientos de inventario')
            ->assertSee('Auditoría de solicitudes')
            ->assertSee('Existencia inicial');

        $this->actingAs($admin)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.index', ['location' => 'ALM']))
            ->assertOk()
            ->assertDontSee('Bitácora de movimientos')
            ->assertSee('Bitácoras');

        $this->actingAs($admin)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('inventory.requests.index'))
            ->assertOk()
            ->assertDontSee('Historial cerrado')
            ->assertSee('Bitácoras');
    }

    public function test_operational_users_can_only_access_their_location_bitacora(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');

        $this->actingAs($receptionist)
            ->withSession(['crepe.mode' => 'recepcion'])
            ->get(route('activity.index', ['location' => 'REC']))
            ->assertOk()
            ->assertSee('Bitácoras');

        $this->actingAs($receptionist)
            ->withSession(['crepe.mode' => 'recepcion'])
            ->get(route('activity.index', ['location' => 'ALM']))
            ->assertForbidden();
    }
}
