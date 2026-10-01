<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\BusinessHour;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\ColorFormula;
use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\FinanceAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\SalonService;
use App\Models\ServiceCategory;
use App\Models\Ticket;
use App\Models\TicketItem;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CrepeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AppSetting::put('cash.opening_float', AppSetting::value('cash.opening_float', 1250));
        $this->call(JobPositionSeeder::class);
        $permissions = [
            'mode.administration.access', 'mode.reception.access', 'mode.color-bar.access',
            'mode.almacen.access', 'products.manage', 'inventory.requests.manage',
            'appointments.view', 'appointments.create', 'customers.view', 'customers.create',
            'tickets.view', 'tickets.update', 'tickets.charge', 'inventory.view', 'inventory.move',
            'cash.open', 'cash.close', 'cash.authorize', 'reports.view', 'settings.manage', 'customers.update',
            'finance.view', 'finance.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permissionModels = Permission::query()->whereIn('name', $permissions)->get();

        $administrator = Role::findOrCreate('Administrador', 'web');
        $administrator->syncPermissions($permissionModels);
        Role::findOrCreate('Recepción', 'web')->syncPermissions($permissionModels->whereIn('name', [
            'mode.reception.access', 'appointments.view', 'appointments.create', 'customers.view',
            'customers.create', 'tickets.view', 'tickets.update', 'tickets.charge', 'inventory.view', 'cash.open', 'cash.close',
        ]));
        Role::findOrCreate('Color Bar', 'web')->syncPermissions($permissionModels->whereIn('name', [
            'mode.color-bar.access', 'tickets.view', 'tickets.update', 'inventory.view',
        ]));
        Role::findOrCreate('Almacén', 'web')->syncPermissions($permissionModels->whereIn('name', [
            'mode.almacen.access', 'products.manage', 'inventory.view', 'inventory.move', 'inventory.requests.manage',
        ]));

        $user = User::query()->updateOrCreate(
            ['email' => 'hi@davidsabido.com'],
            ['name' => 'David Sabido', 'password' => Hash::make('123456')],
        );
        $user->syncRoles([$administrator]);

        FinanceAccount::query()->updateOrCreate(['name' => 'C-Efectivo'], ['type' => 'cash', 'initial_balance' => 0, 'is_active' => true, 'is_primary' => true, 'created_by' => $user->id]);
        FinanceAccount::query()->updateOrCreate(['name' => 'C-Bancomer'], ['type' => 'bank', 'initial_balance' => 0, 'is_active' => true, 'is_primary' => true, 'created_by' => $user->id]);
        foreach (['C-Recepción 1', 'C-Recepción 2'] as $accountName) {
            FinanceAccount::query()->updateOrCreate(['name' => $accountName], ['type' => 'cash', 'initial_balance' => 0, 'is_active' => true, 'is_primary' => false, 'created_by' => $user->id]);
        }
        foreach (['Nómina', 'Servicios', 'Compras', 'Renta', 'Otros'] as $categoryName) {
            FinanceExpenseCategory::query()->updateOrCreate(['name' => $categoryName], ['is_active' => true, 'created_by' => $user->id]);
        }

        Employee::query()->updateOrCreate(['email' => 'ana@crepe.mx'], [
            'first_name' => 'Ana', 'last_name' => 'Torres', 'email' => 'ana@crepe.mx',
            'phone' => '999 210 1840', 'position' => 'Estilista senior', 'is_bookable' => true,
            'status' => 'active', 'commission_rate' => 20,
        ]);
        Employee::query()->updateOrCreate(['email' => 'sofia@crepe.mx'], [
            'first_name' => 'Sofía', 'last_name' => 'Herrera', 'email' => 'sofia@crepe.mx',
            'phone' => '999 365 7201', 'position' => 'Colorista', 'is_bookable' => true,
            'status' => 'active', 'commission_rate' => 18,
        ]);
        Employee::query()->updateOrCreate(['email' => 'laura@crepe.mx'], [
            'first_name' => 'Laura', 'last_name' => 'Méndez', 'email' => 'laura@crepe.mx',
            'phone' => '999 444 8912', 'position' => 'Recepción', 'is_bookable' => false,
            'status' => 'active', 'commission_rate' => null,
        ]);

        foreach ([['Corte y peinado', '#2F63F5', 1], ['Coloración', '#9A5EEA', 2], ['Tratamientos', '#1A9B74', 3]] as [$name, $color, $sort]) {
            ServiceCategory::query()->updateOrCreate(['name' => $name], ['color' => $color, 'sort_order' => $sort, 'is_active' => true]);
        }

        $categories = ServiceCategory::query()->pluck('id', 'name');
        foreach ([
            ['Corte', 'Corte y peinado', 'Corte personalizado con diagnóstico y acabado.', 500, 60, 20, 'fixed', false],
            ['Peinado', 'Corte y peinado', 'Peinado para ocasión especial o evento.', 650, 75, 18, 'fixed', false],
            ['Balayage', 'Coloración', 'Técnica de iluminación personalizada.', 2300, 210, 22, 'variable', true],
            ['Tinte', 'Coloración', 'Coloración y cobertura de raíz.', 1500, 120, 20, 'variable', true],
            ['Tratamiento hidratante', 'Tratamientos', 'Tratamiento de hidratación profunda.', 850, 60, 18, 'fixed', false],
        ] as [$name, $category, $description, $price, $duration, $commission, $type, $colorBar]) {
            SalonService::query()->updateOrCreate(['name' => $name], ['service_category_id' => $categories[$category], 'description' => $description, 'base_price' => $price, 'estimated_duration_minutes' => $duration, 'commission_rate' => $commission, 'price_type' => $type, 'requires_color_bar' => $colorBar, 'status' => 'active']);
        }

        SalonService::query()->where('price_type', 'fixed')->each(function (SalonService $service): void {
            foreach (['A', 'B', 'C'] as $tier) {
                $service->prices()->updateOrCreate(['tier' => $tier], [
                    'cost' => $service->base_cost,
                    'sale_price' => $service->base_price,
                ]);
            }
        });

        foreach ([
            ['María', 'López', '999 121 4512', '999 121 4512', 'maria.lopez@example.com', '1991-06-18'],
            ['Fernanda', 'Ruiz', '999 350 9230', '999 350 9230', 'fernanda.ruiz@example.com', '1987-11-04'],
            ['Valeria', 'Campos', '999 286 6103', '999 286 6103', 'valeria.campos@example.com', '1994-02-12'],
        ] as [$firstName, $lastName, $phone, $whatsapp, $email, $birthday]) {
            Customer::query()->updateOrCreate(['email' => $email], ['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'whatsapp' => $whatsapp, 'birthday' => $birthday, 'status' => 'active']);
        }

        foreach (range(0, 6) as $day) {
            BusinessHour::query()->updateOrCreate(['day_of_week' => $day], [
                'is_open' => $day !== 0, 'opens_at' => $day === 0 ? null : '09:00', 'closes_at' => $day === 0 ? null : '19:00',
            ]);
        }
        foreach (Employee::query()->where('is_bookable', true)->get() as $employee) {
            foreach (range(1, 6) as $day) {
                EmployeeSchedule::query()->updateOrCreate(['employee_id' => $employee->id, 'day_of_week' => $day], ['starts_at' => '09:00', 'ends_at' => '19:00', 'is_available' => true]);
            }
        }

        $today = Carbon::now()->startOfDay()->subDay();
        while ($today->isSunday()) {
            $today->subDay();
        }
        $customers = Customer::query()->pluck('id', 'email');
        $employees = Employee::query()->pluck('id', 'email');
        $services = SalonService::query()->get()->keyBy('name');
        foreach ([
            ['maria.lopez@example.com', 'ana@crepe.mx', '10:00', ['Balayage', 'Corte'], 'in_service'],
            ['fernanda.ruiz@example.com', 'sofia@crepe.mx', '14:00', ['Tinte'], 'confirmed'],
        ] as [$customerEmail, $employeeEmail, $time, $serviceNames, $status]) {
            $chosen = collect($serviceNames)->map(fn ($name) => $services[$name]);
            $startsAt = $today->copy()->setTimeFromTimeString($time);
            $appointment = Appointment::query()->updateOrCreate([
                'customer_id' => $customers[$customerEmail], 'starts_at' => $startsAt,
            ], [
                'primary_employee_id' => $employees[$employeeEmail], 'ends_at' => $startsAt->copy()->addMinutes($chosen->sum('estimated_duration_minutes')),
                'status' => $status, 'estimated_total' => $chosen->sum('base_price'), 'created_by' => $user->id,
            ]);
            foreach ($chosen as $service) {
                $appointment->services()->updateOrCreate(['salon_service_id' => $service->id], ['employee_id' => $appointment->primary_employee_id, 'name_snapshot' => $service->name, 'estimated_price' => $service->base_price, 'estimated_duration_minutes' => $service->estimated_duration_minutes]);
            }
            $seededTicket = app(TicketService::class)->ensureForAppointment($appointment->load('services.employee'), $user->id);
            $seededTicket->items()->where('type', 'service')->get()->each(function (TicketItem $item): void {
                $metadata = $item->metadata ?? [];
                $metadata['price_confirmed'] = true;
                $item->update(['metadata' => $metadata]);
            });
        }

        foreach ([['Coloración', '#9A5EEA'], ['Oxidantes', '#6B9DEA'], ['Retail', '#1A9B74']] as [$name, $color]) {
            ProductCategory::query()->updateOrCreate(['name' => $name], ['color' => $color]);
        }
        foreach (['Wella', 'CREPÉ Care'] as $brandName) {
            ProductBrand::query()->firstOrCreate(['name' => $brandName], ['is_active' => true]);
        }
        $productCategories = ProductCategory::query()->pluck('id', 'name');
        $productBrands = ProductBrand::query()->pluck('id', 'name');
        foreach ([
            ['Wella Koleston 7/1', 'Coloración', 'Wella', 'KOL-71', true, 'Coloración 7/1', 'g', 1000, 1.00, 8.00, 500, 2000],
            ['Oxidante 20 vol', 'Oxidantes', 'Wella', 'OX-20', true, 'Presentación 20 vol', 'ml', 1000, .40, 3.00, 500, 3000],
            ['Shampoo Restore', 'Retail', 'CREPÉ Care', 'SH-REST', false, '250 ml', 'unidad', 1, 210, 650, 4, 24],
        ] as [$name, $category, $brand, $sku, $colorBar, $variantName, $unit, $content, $cost, $price, $minimum, $maximum]) {
            $product = Product::query()->updateOrCreate(['sku' => $sku], ['product_category_id' => $productCategories[$category], 'product_brand_id' => $productBrands[$brand], 'name' => $name, 'brand' => $brand, 'sku' => $sku, 'is_color_bar_usable' => $colorBar, 'status' => 'active']);
            ProductVariant::query()->updateOrCreate(['sku' => $sku.'-STD'], ['product_id' => $product->id, 'name' => $variantName, 'sku' => $sku.'-STD', 'base_unit' => $unit, 'content_quantity' => $content, 'cost' => $cost, 'sale_price' => $price, 'minimum_stock' => $minimum, 'maximum_stock' => $maximum]);
        }
        foreach ([['Almacén', 'ALM'], ['Color Bar', 'CB'], ['Recepción', 'REC']] as [$name, $code]) {
            InventoryLocation::query()->updateOrCreate(['code' => $code], ['name' => $name, 'code' => $code]);
        }
        $variants = ProductVariant::query()->with('product')->get()->keyBy('sku');
        $locations = InventoryLocation::query()->pluck('id', 'code');
        $inventory = app(InventoryService::class);
        foreach ([['KOL-71-STD', 'CB', 2000], ['OX-20-STD', 'CB', 3000], ['SH-REST-STD', 'REC', 15], ['KOL-71-STD', 'ALM', 5000], ['SH-REST-STD', 'ALM', 36]] as [$sku, $location, $quantity]) {
            $inventory->move($variants[$sku]->id, $locations[$location], $quantity, 'opening', $user->id, null, null, 'Existencia inicial');
        }
        $mariaTicket = Ticket::query()->whereHas('customer', fn ($query) => $query->where('email', 'maria.lopez@example.com'))->firstOrFail();
        $formula = ColorFormula::query()->firstOrCreate(['ticket_id' => $mariaTicket->id], ['customer_id' => $mariaTicket->customer_id, 'inventory_location_id' => $locations['CB'], 'status' => 'confirmed', 'created_by' => $user->id]);
        foreach ([['KOL-71-STD', 35], ['OX-20-STD', 50]] as [$sku, $quantity]) {
            $variant = $variants[$sku];
            $formula->items()->updateOrCreate(['product_variant_id' => $variant->id], ['quantity' => $quantity, 'unit' => $variant->base_unit, 'cost_snapshot' => $variant->cost, 'sale_price' => $variant->sale_price]);
            $inventory->move($variant->id, $locations['CB'], -$quantity, 'color_consumption', $user->id, ColorFormula::class, $formula->id, 'Fórmula María López');
            $mariaTicket->items()->firstOrCreate(['type' => 'color_bar', 'name_snapshot' => $variant->product->name], ['quantity' => $quantity, 'unit' => $variant->base_unit, 'unit_price' => $variant->sale_price, 'line_total' => $variant->sale_price * $quantity, 'cost_snapshot' => $variant->cost, 'status' => 'active', 'added_by' => $user->id]);
        }
        $shampoo = $variants['SH-REST-STD'];
        $mariaTicket->items()->firstOrCreate(['type' => 'product', 'name_snapshot' => 'Shampoo Restore'], ['quantity' => 1, 'unit' => 'unidad', 'unit_price' => $shampoo->sale_price, 'line_total' => $shampoo->sale_price, 'cost_snapshot' => $shampoo->cost, 'status' => 'active', 'metadata' => ['employee_id' => $employees['ana@crepe.mx']], 'added_by' => $user->id]);
        $mariaTicket->payments()->firstOrCreate(['type' => 'deposit', 'method' => 'transfer', 'amount' => 500], ['status' => 'registered', 'reference' => 'ANT-0001', 'created_by' => $user->id]);
        CashRegister::query()->updateOrCreate(['code' => 'REC-01'], ['name' => 'Recepción 1', 'code' => 'REC-01', 'is_active' => true]);
        CashRegister::query()->updateOrCreate(['code' => 'REC-02'], ['name' => 'Recepción 2', 'code' => 'REC-02', 'is_active' => true]);
        CashRegister::query()->where('code', 'REC-01')->update(['finance_account_id' => FinanceAccount::query()->where('name', 'C-Recepción 1')->value('id')]);
        CashRegister::query()->where('code', 'REC-02')->update(['finance_account_id' => FinanceAccount::query()->where('name', 'C-Recepción 2')->value('id')]);
        $cashRegisters = CashRegister::query()->whereIn('code', ['REC-01', 'REC-02'])->get();
        foreach ($cashRegisters as $cashRegister) {
            CashSession::query()->updateOrCreate(
                ['cash_register_id' => $cashRegister->id, 'business_date' => now()->toDateString()],
                ['opened_by' => $user->id, 'opened_at' => now(), 'opening_float' => (float) AppSetting::value('cash.opening_float', 1250), 'status' => 'open'],
            );
        }
        foreach ($mariaTicket->items()->where('type', 'service')->get() as $item) {
            CommissionEntry::query()->firstOrCreate(['ticket_id' => $mariaTicket->id, 'ticket_item_id' => $item->id], ['employee_id' => $employees['ana@crepe.mx'], 'type' => 'service', 'base_amount' => $item->line_total, 'rate_snapshot' => 20, 'amount' => $item->line_total * .2, 'status' => 'pending']);
        }
        AuditLog::query()->firstOrCreate(['action' => 'seed.ticket.opened', 'subject_type' => Ticket::class, 'subject_id' => $mariaTicket->id], ['user_id' => $user->id, 'after' => ['status' => $mariaTicket->status], 'reason' => 'Datos iniciales']);
    }
}
