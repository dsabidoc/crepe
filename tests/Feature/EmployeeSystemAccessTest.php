<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeSystemAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_create_a_collaborator_account_with_a_role(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($administrator)->post(route('employees.store'), [
            'first_name' => 'Marina',
            'last_name' => 'Vega',
            'email' => 'marina@crepe.mx',
            'position' => 'Recepción',
            'status' => 'active',
            'system_access_enabled' => true,
            'system_email' => 'acceso.marina@crepe.mx',
            'system_role' => 'Recepción',
            'system_password' => 'contrasena-segura',
            'system_password_confirmation' => 'contrasena-segura',
        ])->assertRedirect(route('employees.index'));

        $employee = Employee::query()->where('email', 'marina@crepe.mx')->firstOrFail();
        $user = $employee->user;

        $this->assertNotNull($user);
        $this->assertSame('acceso.marina@crepe.mx', $user->email);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('contrasena-segura', $user->password));
        $this->assertTrue($user->hasRole('Recepción'));

        $this->actingAs($administrator)->get(route('employees.edit', $employee))
            ->assertSee('Acceso a sistema')
            ->assertSee('Acceso activo');
    }

    public function test_revoking_a_collaborator_account_prevents_a_new_login(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $collaborator = User::factory()->create([
            'name' => 'Pilar Acceso',
            'email' => 'pilar.acceso@crepe.mx',
            'password' => 'contrasena-segura',
        ]);
        $collaborator->assignRole('Recepción');
        $employee = Employee::query()->create([
            'user_id' => $collaborator->id,
            'first_name' => 'Pilar',
            'last_name' => 'Acceso',
            'email' => 'pilar@crepe.mx',
            'position' => 'Recepción',
            'status' => 'active',
        ]);

        $this->actingAs($administrator)->put(route('employees.update', $employee), [
            'first_name' => 'Pilar',
            'last_name' => 'Acceso',
            'email' => 'pilar@crepe.mx',
            'position' => 'Recepción',
            'status' => 'active',
        ])->assertRedirect(route('employees.index'));

        $this->assertFalse($collaborator->fresh()->is_active);

        $this->post(route('logout'));
        $this->post(route('login.store'), [
            'email' => 'pilar.acceso@crepe.mx',
            'password' => 'contrasena-segura',
        ])->assertSessionHasErrors('email');
    }

    public function test_administrator_manages_job_positions_and_employees_must_select_one(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($administrator)->from(route('settings.edit'))->post(route('settings.job-positions.store'), [
            'name' => 'Técnica de uñas',
        ])->assertRedirect(route('settings.edit'));

        $this->assertDatabaseHas('job_positions', ['name' => 'Técnica de uñas', 'is_active' => true]);

        $this->actingAs($administrator)->get(route('employees.create'))
            ->assertOk()
            ->assertSee('Puesto')
            ->assertSee('Técnica de uñas')
            ->assertSee('<select name="position"', false);

        $this->actingAs($administrator)->from(route('employees.create'))->post(route('employees.store'), [
            'first_name' => 'Pilar',
            'email' => 'pilar.catalogo@crepe.mx',
            'position' => 'Puesto inexistente',
            'status' => 'active',
        ])->assertRedirect(route('employees.create'))
            ->assertSessionHasErrors('position');
    }
}
