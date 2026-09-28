<?php

namespace Tests\Feature\Console\Commands;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncCrepeTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_adds_the_team_without_inventing_contact_details(): void
    {
        $this->artisan('crepe:sync-team')->assertSuccessful();

        $this->assertDatabaseHas('employees', [
            'first_name' => 'Pilu',
            'last_name' => null,
            'position' => 'Dueña',
            'commission_rate' => 75,
            'product_commission_rate' => 10,
            'is_bookable' => true,
        ]);
        $this->assertDatabaseHas('employees', [
            'first_name' => 'Ileana',
            'position' => 'Cajera',
            'commission_rate' => 0,
            'is_bookable' => false,
        ]);

        $this->assertSame(19, Employee::query()->count());
    }
}
