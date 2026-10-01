<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\FinanceAccount;
use App\Models\Product;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministrativeSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_editor_includes_the_products_category(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $product = Product::query()->firstOrFail();

        $this->actingAs($administrator)
            ->withSession(['crepe.mode' => 'almacen'])
            ->get(route('products.edit', $product))
            ->assertViewHas('categories', fn ($categories): bool => $categories->contains('id', $product->product_category_id));
    }

    public function test_administrator_can_render_the_new_finance_and_inventory_modules(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'finanzas'])->get(route('finance.index'))->assertOk();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'finanzas'])->get(route('payables.index'))->assertOk();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'finanzas'])->get(route('payroll.index'))->assertOk();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'almacen'])->get(route('purchase-orders.index'))->assertOk();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'almacen'])->get(route('purchase-orders.create'))->assertOk();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'administracion'])->get(route('settings.edit'))->assertOk();
        $this->actingAs($administrator)->withSession(['crepe.mode' => 'almacen'])->get(route('products.index'))->assertOk();

        $this->actingAs($administrator)->post(route('products.categories.store'), ['name' => 'Categoría de prueba', 'color' => '#B95070'])->assertRedirect();
        $this->assertDatabaseHas('product_categories', ['name' => 'Categoría de prueba']);

    }

    public function test_administrator_can_render_all_primary_operational_screens(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $employee = Employee::query()->firstOrFail();
        $product = Product::query()->firstOrFail();
        $service = SalonService::query()->firstOrFail();
        $ticket = Ticket::query()->firstOrFail();
        $financeAccount = FinanceAccount::query()->firstOrFail();

        $urls = [
            route('modes.select'),
            route('workspace', 'administracion'),
            route('workspace', 'recepcion'),
            route('workspace', 'color-bar'),
            route('workspace', 'almacen'),
            route('workspace', 'finanzas'),
            route('workspace', 'promos'),
            route('workspace', 'configuracion'),
            route('agenda.index'),
            route('appointments.create'),
            route('tickets.index'),
            route('tickets.show', $ticket),
            route('tickets.product-sales.create'),
            route('color-bar.index'),
            route('inventory.index'),
            route('activity.index'),
            route('inventory.requests.index'),
            route('inventory.requests.create', ['location' => 'REC']),
            route('purchase-orders.index'),
            route('cash.index'),
            route('reports.index'),
            route('finance.index', ['view' => 'dashboard']),
            route('finance.index', ['view' => 'movements']),
            route('finance.index', ['view' => 'accounts']),
            route('finance.index', ['view' => 'catalogs']),
            route('finance.accounts.show', $financeAccount),
            route('payables.index'),
            route('payroll.index'),
            route('customers.index'),
            route('customers.create'),
            route('customers.show', $customer),
            route('customers.edit', $customer),
            route('employees.index'),
            route('employees.create'),
            route('employees.show', $employee),
            route('employees.edit', $employee),
            route('services.index'),
            route('services.create'),
            route('services.edit', $service),
            route('products.index'),
            route('products.catalogs'),
            route('products.create'),
            route('products.edit', $product),
            route('promotions.index'),
            route('promotions.create'),
            route('settings.edit'),
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($administrator)
                ->withSession(['crepe.mode' => 'administracion'])
                ->followingRedirects()
                ->get($url);

            $this->assertSame(200, $response->status(), $url);
        }
    }
}
