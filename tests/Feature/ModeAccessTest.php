<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\ServiceCategory;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CrepeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_administrator_can_select_and_open_any_mode(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)
            ->get(route('modes.select'))
            ->assertOk()
            ->assertSee('Administración')
            ->assertSee('Recepción')
            ->assertSee('Color Bar')
            ->assertSee('Almacén')
            ->assertSee('Finanzas')
            ->assertSee('Promos')
            ->assertSee('Configuración');

        $this->actingAs($user)
            ->post(route('modes.store'), ['mode' => 'color-bar'])
            ->assertRedirect(route('color-bar.index'));

        $this->actingAs($user)
            ->get(route('color-bar.index'))
            ->assertOk()
            ->assertSee('Seleccionar ticket');

        $this->actingAs($user)
            ->post(route('modes.store'), ['mode' => 'almacen'])
            ->assertRedirect(route('workspace', ['mode' => 'almacen']));

        $this->actingAs($user)
            ->get(route('workspace', ['mode' => 'almacen']))
            ->assertOk()
            ->assertSee('Solicitudes de inventario')
            ->assertSee('Dar de alta producto');

        $this->actingAs($user)
            ->post(route('modes.store'), ['mode' => 'finanzas'])
            ->assertRedirect(route('finance.index'));

        $this->actingAs($user)
            ->withSession(['crepe.mode' => 'finanzas'])
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee('Resumen financiero')
            ->assertSee('Movimientos')
            ->assertSee('Cortes')
            ->assertSee('Cuentas');
    }

    public function test_administrator_can_create_and_view_a_customer(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)->post(route('customers.store'), [
            'first_name' => 'Lucía', 'last_name' => 'Vega', 'phone' => '999 450 6221',
            'whatsapp' => '999 450 6221', 'email' => 'lucia.vega@example.com', 'status' => 'active',
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::query()->where('email', 'lucia.vega@example.com')->firstOrFail();

        $this->actingAs($user)->get(route('customers.show', $customer))
            ->assertOk()->assertSee('Lucía Vega');
    }

    public function test_customer_profile_shows_visit_history_and_ticket_detail_data(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->where('email', 'maria.lopez@example.com')->firstOrFail();

        $this->actingAs($user)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Historial de citas')
            ->assertSee('Balayage')
            ->assertSee('Color Bar utilizado')
            ->assertSee('Ver detalle');
    }

    public function test_administrator_can_open_the_product_catalog_and_see_administration_navigation(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)
            ->withSession(['crepe.mode' => 'administracion'])
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Productos')
            ->assertSee('Color Bar')
            ->assertSee('Reportes');
    }

    public function test_warehouse_can_maintain_product_brand_and_supplier_catalogs(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)
            ->get(route('products.catalogs'))
            ->assertOk()
            ->assertSee('Marcas y proveedores');

        $this->actingAs($user)
            ->post(route('products.brands.store'), ['name' => 'Davines'])
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('suppliers.store'), ['name' => 'Proveedor de prueba', 'payment_grace_days' => 15])
            ->assertRedirect();

        $this->assertDatabaseHas('product_brands', ['name' => 'Davines']);
        $this->assertDatabaseHas('suppliers', ['name' => 'Proveedor de prueba', 'payment_grace_days' => 15]);
        $this->assertTrue(ProductBrand::query()->where('name', 'Davines')->value('is_active'));
        $this->assertTrue(Supplier::query()->where('name', 'Proveedor de prueba')->value('is_active'));
    }

    public function test_reception_navigation_hides_administration_only_modules(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)
            ->withSession(['crepe.mode' => 'recepcion'])
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee('Agenda')
            ->assertSee('Caja')
            ->assertSee('Inventario Recepción')
            ->assertDontSee('Equipo')
            ->assertDontSee('Reportes');
    }

    public function test_administration_navigation_is_consistent_between_dashboard_and_customers(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $navigation = ['Dashboard', 'Clientas', 'Equipo', 'Servicios', 'Reportes'];

        $dashboard = $this->actingAs($user)->get(route('workspace', ['mode' => 'administracion']));
        $customers = $this->actingAs($user)->get(route('customers.index'));

        foreach ($navigation as $item) {
            $dashboard->assertSee($item);
            $customers->assertSee($item);
        }

        foreach (['agenda.index', 'tickets.index', 'products.index', 'color-bar.index', 'inventory.index', 'activity.index'] as $routeName) {
            $link = 'href="'.route($routeName).'"';

            $dashboard->assertDontSee($link);
            $customers->assertDontSee($link);
        }

        $dashboard->assertDontSee('href="'.route('finance.index').'"');
        $customers->assertDontSee('href="'.route('finance.index').'"');
    }

    public function test_reception_can_open_its_inventory(): void
    {
        $this->seed(CrepeSeeder::class);
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');

        $this->actingAs($receptionist)
            ->withSession(['crepe.mode' => 'recepcion'])
            ->get(route('inventory.index', ['location' => 'REC']))
            ->assertOk()
            ->assertSee('Inventario Recepción');
    }

    public function test_color_bar_navigation_only_shows_its_operational_modules(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)
            ->withSession(['crepe.mode' => 'color-bar'])
            ->get(route('color-bar.index'))
            ->assertOk()
            ->assertSee('Tickets Color Bar')
            ->assertSee('Inventario Color Bar')
            ->assertSee('Todos los tickets')
            ->assertDontSee('Equipo')
            ->assertDontSee('Reportes');
    }

    public function test_color_bar_ticket_list_only_shows_tickets_with_a_color_bar_service(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $employee = Employee::query()->where('is_bookable', true)->firstOrFail();
        $service = SalonService::query()->where('name', 'Corte')->firstOrFail();
        $appointment = Appointment::query()->create([
            'customer_id' => $customer->id,
            'primary_employee_id' => $employee->id,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHour(),
            'status' => 'confirmed',
            'estimated_total' => $service->base_price,
            'created_by' => $user->id,
        ]);
        AppointmentService::query()->create([
            'appointment_id' => $appointment->id,
            'salon_service_id' => $service->id,
            'employee_id' => $employee->id,
            'name_snapshot' => $service->name,
            'estimated_price' => $service->base_price,
            'estimated_duration_minutes' => $service->estimated_duration_minutes,
        ]);
        $ineligibleTicket = Ticket::query()->create([
            'code' => '#NO-COLOR-BAR',
            'customer_id' => $customer->id,
            'appointment_id' => $appointment->id,
            'ticket_type' => 'appointment',
            'status' => 'open',
            'estimated_total' => $service->base_price,
            'opened_at' => now(),
        ]);
        $eligibleTicket = Ticket::query()->whereHas('appointment.services.service', fn ($query) => $query->where('requires_color_bar', true))->firstOrFail();

        $this->actingAs($user)
            ->withSession(['crepe.mode' => 'color-bar'])
            ->get(route('tickets.index'))
            ->assertOk()
            ->assertSee($eligibleTicket->code)
            ->assertDontSee($ineligibleTicket->code);
    }

    public function test_an_overlapping_appointment_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00', config('app.timezone')));
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $data = [
            'customer_id' => Customer::query()->firstOrFail()->id,
            'primary_employee_id' => Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail()->id,
            'date' => '2026-09-28', 'time' => '10:00', 'duration_minutes' => 60,
            'service_ids' => [SalonService::query()->where('name', 'Corte')->firstOrFail()->id],
        ];

        $this->actingAs($user)->post(route('appointments.store'), $data)
            ->assertRedirect(route('agenda.index', ['date' => '2026-09-28']));

        $this->assertDatabaseHas('tickets', ['customer_id' => $data['customer_id'], 'status' => 'open']);

        $this->actingAs($user)->from(route('appointments.create'))
            ->post(route('appointments.store'), $data)
            ->assertRedirect(route('appointments.create'))
            ->assertSessionHasErrors('time');

        $this->travelBack();
    }

    public function test_past_dates_and_times_cannot_be_booked(): void
    {
        $this->travelTo(Carbon::parse('2026-09-30 10:30:00', config('app.timezone')));
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $service = SalonService::query()->where('name', 'Corte')->firstOrFail();
        $data = [
            'customer_id' => $customer->id,
            'primary_employee_id' => $employee->id,
            'duration_minutes' => 60,
            'service_ids' => [$service->id],
        ];

        $this->actingAs($user)
            ->getJson(route('appointments.availability', [
                'date' => '2026-09-29',
                'duration_minutes' => 60,
                'employee_ids' => [$employee->id],
            ]))
            ->assertOk()
            ->assertExactJson(['available' => []]);

        $this->actingAs($user)->from(route('appointments.create'))
            ->post(route('appointments.store'), [...$data, 'date' => '2026-09-29', 'time' => '10:00'])
            ->assertRedirect(route('appointments.create'))
            ->assertSessionHasErrors(['time' => 'No puedes reservar una fecha u hora que ya pasó.']);

        $this->actingAs($user)->from(route('appointments.create'))
            ->post(route('appointments.store'), [...$data, 'date' => '2026-09-30', 'time' => '10:00'])
            ->assertRedirect(route('appointments.create'))
            ->assertSessionHasErrors(['time' => 'No puedes reservar una fecha u hora que ya pasó.']);

        $this->travelBack();
    }

    public function test_agenda_week_runs_from_monday_to_sunday_and_filters_appointments_by_stylist(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ana = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $sofia = Employee::query()->where('email', 'sofia@crepe.mx')->firstOrFail();
        $anaCustomer = Customer::query()->firstOrFail();
        $crisCustomer = Customer::query()->skip(1)->firstOrFail();

        Appointment::query()->create([
            'customer_id' => $anaCustomer->id,
            'primary_employee_id' => $ana->id,
            'starts_at' => '2026-09-28 10:00:00',
            'ends_at' => '2026-09-28 11:00:00',
            'status' => 'scheduled',
            'estimated_total' => 0,
            'created_by' => $user->id,
        ]);
        Appointment::query()->create([
            'customer_id' => $crisCustomer->id,
            'primary_employee_id' => $sofia->id,
            'starts_at' => '2026-09-28 10:00:00',
            'ends_at' => '2026-09-28 11:00:00',
            'status' => 'scheduled',
            'estimated_total' => 0,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)->get(route('agenda.index', [
            'date' => '2026-09-30',
            'view' => 'week',
            'employees' => [$ana->id],
        ]))
            ->assertOk()
            ->assertSee('Semana del lunes 28 al domingo 04 de octubre de 2026')
            ->assertSee($anaCustomer->full_name)
            ->assertDontSee($crisCustomer->full_name);
    }

    public function test_agenda_renders_the_selected_month_view(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)->get(route('agenda.index', [
            'date' => '2026-09-30',
            'view' => 'month',
        ]))
            ->assertOk()
            ->assertSee('septiembre de 2026')
            ->assertSee('Lun')
            ->assertSee('Dom');
    }

    public function test_appointment_reserves_the_duration_selected_by_reception(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00', config('app.timezone')));
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->firstOrFail();
        $employee = Employee::query()->where('email', 'ana@crepe.mx')->firstOrFail();
        $services = SalonService::query()->whereIn('name', ['Corte', 'Tinte'])->pluck('id')->all();

        $this->actingAs($user)->post(route('appointments.store'), [
            'customer_id' => $customer->id,
            'primary_employee_id' => $employee->id,
            'date' => '2026-09-28',
            'time' => '14:00',
            'duration_minutes' => 120,
            'service_ids' => $services,
        ])->assertRedirect(route('agenda.index', ['date' => '2026-09-28']));

        $appointment = Appointment::query()
            ->where('customer_id', $customer->id)
            ->whereDate('starts_at', '2026-09-28')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('14:00', $appointment->starts_at->format('H:i'));
        $this->assertSame('16:00', $appointment->ends_at->format('H:i'));

        $this->travelBack();
    }

    public function test_bookable_stylist_without_a_personal_schedule_uses_salon_hours(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 08:00:00', config('app.timezone')));
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $stylist = Employee::query()->create([
            'first_name' => 'Estilista',
            'last_name' => 'Nueva',
            'position' => 'Estilista',
            'is_bookable' => true,
            'status' => 'active',
        ]);
        $customer = Customer::query()->firstOrFail();
        $service = SalonService::query()->where('name', 'Corte')->firstOrFail();

        $this->actingAs($user)->post(route('appointments.store'), [
            'customer_id' => $customer->id,
            'primary_employee_id' => $stylist->id,
            'date' => '2026-09-28',
            'time' => '10:00',
            'duration_minutes' => 120,
            'service_ids' => [$service->id],
        ])->assertRedirect(route('agenda.index', ['date' => '2026-09-28']));

        $this->assertDatabaseHas('appointments', [
            'primary_employee_id' => $stylist->id,
            'starts_at' => '2026-09-28 10:00:00',
            'ends_at' => '2026-09-28 12:00:00',
        ]);

        $this->travelBack();
    }

    public function test_seeded_ticket_can_be_paid_without_exceeding_its_balance(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->with(['items', 'payments'])->firstOrFail();
        $cashRegister = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($user)->post(route('tickets.payments.store', $ticket), [
            'amount' => $ticket->balance, 'method' => 'card', 'cash_register_id' => $cashRegister->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['ticket_id' => $ticket->id, 'cash_session_id' => CashSession::query()->where('cash_register_id', $cashRegister->id)->value('id')]);
    }

    public function test_reception_can_generate_a_daily_cut_with_payment_totals_and_administration_confirms_it(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $cashRegister = CashRegister::query()->where('code', 'REC-01')->firstOrFail();

        $this->actingAs($administrator)->post(route('tickets.payments.store', $ticket), [
            'amount' => 100,
            'method' => 'card',
            'cash_register_id' => $cashRegister->id,
        ])->assertSessionHasNoErrors();

        $cashSession = CashSession::query()->where('cash_register_id', $cashRegister->id)->whereDate('business_date', now())->firstOrFail();

        $this->actingAs($administrator)->post(route('cash.cuts.store', $cashSession), [
            'actual_card' => 100,
            'actual_cash' => 0,
            'actual_change' => 1250,
            'actual_gift_card' => 0,
            'actual_other' => 0,
            'actual_transfer' => 0,
            'cashier_notes' => 'Corte contado correctamente.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_sessions', [
            'id' => $cashSession->id,
            'status' => 'pending_review',
            'expected_card' => 100,
            'difference' => 0,
        ]);

        $this->actingAs($administrator)
            ->get(route('cash.index'))
            ->assertOk()
            ->assertSee('DETALLE DEL CORTE')
            ->assertSee($ticket->code)
            ->assertSee('Tiempo hasta cobro')
            ->assertSee('Enviado');

        $this->actingAs($administrator)->post(route('cash.cuts.confirm', $cashSession), [
            'verification_notes' => 'Revisado por administración.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('cash_sessions', [
            'id' => $cashSession->id,
            'status' => 'verified',
            'verification_notes' => 'Revisado por administración.',
        ]);
    }

    public function test_reception_cannot_confirm_a_cash_cut(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $receptionist = User::factory()->create();
        $receptionist->assignRole('Recepción');
        $cashSession = CashSession::query()->where('status', 'open')->firstOrFail();

        $this->actingAs($administrator)->post(route('cash.cuts.store', $cashSession), [
            'actual_card' => 0,
            'actual_cash' => 0,
            'actual_change' => 1250,
            'actual_gift_card' => 0,
            'actual_other' => 0,
            'actual_transfer' => 0,
        ])->assertSessionHasNoErrors();

        $this->actingAs($receptionist)
            ->post(route('cash.cuts.confirm', $cashSession), ['verification_notes' => 'Intento sin permiso.'])
            ->assertForbidden();
    }

    public function test_reception_dashboard_has_real_operational_destinations(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)->get(route('workspace', ['mode' => 'recepcion']))
            ->assertOk()
            ->assertSee(route('customers.create'), false)
            ->assertSee(route('appointments.create'), false)
            ->assertSee(route('tickets.index'), false)
            ->assertDontSee('href="#"', false);
    }

    public function test_dashboard_only_marks_inventory_below_its_variant_minimum_as_low_stock(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        $this->actingAs($user)->get(route('workspace', ['mode' => 'administracion']))
            ->assertDontSee('Shampoo Restore con stock bajo');

        $balance = InventoryBalance::query()
            ->whereHas('variant', fn ($query) => $query->where('sku', 'SH-REST-STD'))
            ->whereHas('location', fn ($query) => $query->where('code', 'REC'))
            ->firstOrFail();
        $balance->update(['available_quantity' => 4]);

        $this->actingAs($user)->get(route('workspace', ['mode' => 'administracion']))
            ->assertSee('Shampoo Restore con stock bajo');
    }

    public function test_product_sale_creates_an_immutable_inventory_movement(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->where('status', 'open')->firstOrFail();
        $variant = ProductVariant::query()->where('sku', 'SH-REST-STD')->firstOrFail();
        $balance = InventoryBalance::query()->where('product_variant_id', $variant->id)
            ->whereHas('location', fn ($query) => $query->where('code', 'REC'))->firstOrFail();
        $before = (float) $balance->available_quantity;

        $this->actingAs($user)->post(route('tickets.products.store', $ticket), [
            'product_variant_id' => $variant->id, 'quantity' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('inventory_movements', [
            'product_variant_id' => $variant->id, 'inventory_location_id' => $balance->inventory_location_id,
            'type' => 'sale', 'quantity' => -1,
        ]);
        $this->assertEquals($before - 1, (float) $balance->fresh()->available_quantity);
    }

    public function test_ticket_can_add_a_second_responsible_stylist_without_exposing_commission_details(): void
    {
        $this->seed(CrepeSeeder::class);
        $administrator = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $ticket = Ticket::query()->with('appointment')->whereNotNull('appointment_id')->firstOrFail();
        $secondaryStylist = Employee::query()
            ->where('is_bookable', true)
            ->where('status', 'active')
            ->whereKeyNot($ticket->appointment->primary_employee_id)
            ->firstOrFail();

        $this->actingAs($administrator)
            ->post(route('tickets.stylists.secondary.store', $ticket), ['secondary_employee_id' => $secondaryStylist->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'id' => $ticket->appointment_id,
            'secondary_employee_id' => $secondaryStylist->id,
        ]);
        $this->actingAs($administrator)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Estilistas responsables')
            ->assertSee($secondaryStylist->full_name)
            ->assertSee('Quitar')
            ->assertDontSee('Comisión');

        $this->actingAs($administrator)
            ->delete(route('tickets.stylists.secondary.destroy', $ticket))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('appointments', [
            'id' => $ticket->appointment_id,
            'secondary_employee_id' => null,
        ]);
    }

    public function test_archiving_a_customer_keeps_the_record_out_of_active_lists(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $customer = Customer::query()->firstOrFail();

        $this->actingAs($user)->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_administrator_can_create_a_product_with_its_real_unit(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $category = ProductCategory::query()->firstOrFail();

        $this->actingAs($user)->post(route('products.store'), [
            'product_category_id' => $category->id, 'name' => 'Mascarilla Prueba', 'brand' => 'CREPÉ Care',
            'sku' => 'MASK-TEST', 'status' => 'active', 'variant_name' => '250 ml', 'variant_sku' => 'MASK-TEST-250',
            'base_unit' => 'ml', 'content_quantity' => 250, 'cost' => 90, 'sale_price' => 260, 'minimum_stock' => 3,
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseHas('products', ['sku' => 'MASK-TEST']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'MASK-TEST-250', 'base_unit' => 'ml', 'content_quantity' => 250]);
    }

    public function test_administrator_can_attach_product_photos(): void
    {
        Storage::fake('public');
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $product = Product::query()->firstOrFail();

        $this->actingAs($user)->put(route('products.update', $product), [
            'product_category_id' => $product->product_category_id, 'name' => $product->name, 'sku' => $product->sku,
            'status' => 'active', 'variant_name' => $product->variants()->value('name'), 'base_unit' => 'unidad',
            'content_quantity' => 1, 'cost' => 1, 'sale_price' => 2, 'minimum_stock' => 1,
            'photos' => [UploadedFile::fake()->image('producto.png')],
        ])->assertRedirect(route('products.index'));

        $path = $product->fresh()->image_paths[0] ?? null;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_administrator_can_define_three_price_tiers_for_a_fixed_service(): void
    {
        $this->seed(CrepeSeeder::class);
        $user = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();
        $category = ServiceCategory::query()->firstOrFail();

        $this->actingAs($user)->post(route('services.store'), [
            'service_category_id' => $category->id,
            'name' => 'Corte de prueba',
            'base_price' => 400,
            'base_cost' => 100,
            'price_b_sale_price' => 550,
            'price_b_cost' => 150,
            'price_c_sale_price' => 700,
            'price_c_cost' => 220,
            'estimated_duration_minutes' => 60,
            'price_type' => 'fixed',
            'status' => 'active',
        ])->assertRedirect(route('services.index'));

        $service = SalonService::query()->where('name', 'Corte de prueba')->firstOrFail();
        $this->assertDatabaseHas('salon_service_prices', ['salon_service_id' => $service->id, 'tier' => 'A', 'cost' => 100, 'sale_price' => 400]);
        $this->assertDatabaseHas('salon_service_prices', ['salon_service_id' => $service->id, 'tier' => 'B', 'cost' => 150, 'sale_price' => 550]);
        $this->assertDatabaseHas('salon_service_prices', ['salon_service_id' => $service->id, 'tier' => 'C', 'cost' => 220, 'sale_price' => 700]);
    }
}
