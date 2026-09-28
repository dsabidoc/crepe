<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\AppointmentService as AppointmentServiceModel;
use App\Models\AuditLog;
use App\Models\CashSession;
use App\Models\ColorFormula;
use App\Models\ColorFormulaItem;
use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\FinanceAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\InventoryLocation;
use App\Models\InventoryRequest;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\SalonService;
use App\Models\ServiceCategory;
use App\Models\Ticket;
use App\Models\TicketAdjustment;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->where('email', 'hi@davidsabido.com')->firstOrFail();

        if (AuditLog::query()->where('action', 'demo.dataset.v1')->exists()) {
            $this->command?->info('Los datos demo ya están cargados.');

            return;
        }

        DB::transaction(function () use ($actor): void {
            $employees = $this->createEmployees();
            $customers = $this->createCustomers();
            $services = $this->createServices();
            $products = $this->createProducts();
            $this->seedInventory($products, $actor);
            [$appointments, $tickets] = $this->createAppointmentsAndTickets($customers, $employees, $services, $products, $actor);
            $this->createProductSales($customers, $products, $actor);
            $promotions = $this->createPromotions($services, $products);
            $this->createPromotionExample($tickets, $promotions, $actor);
            $this->createFinanceTransactions($actor);
            $this->createInventoryRequests($products, $actor);

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'demo.dataset.v1',
                'subject_type' => self::class,
                'subject_id' => $actor->id,
                'after' => ['customers' => $customers->count(), 'employees' => $employees->count(), 'services' => $services->count(), 'products' => $products->count(), 'appointments' => $appointments->count(), 'tickets' => $tickets->count()],
                'reason' => 'Carga de ejemplos demo para revisión del sistema',
            ]);
        });

        $this->command?->info('Se cargaron datos demo: 30 clientas, 30 citas, productos, servicios, tickets, promociones, movimientos y solicitudes de inventario.');
    }

    /** @return Collection<int, Employee> */
    private function createEmployees(): Collection
    {
        $names = [
            ['Paola', 'Mendoza', 'Estilista'], ['Renata', 'Sosa', 'Estilista senior'], ['Julia', 'Navarro', 'Colorista'], ['Mónica', 'Pech', 'Estilista'], ['Daniela', 'Canché', 'Colorista'],
            ['Carolina', 'Dzul', 'Estilista'], ['Isabela', 'Chan', 'Estilista'], ['Lucía', 'Cervera', 'Estilista senior'], ['Mariana', 'Tun', 'Colorista'], ['Ximena', 'Barrera', 'Estilista'],
        ];

        foreach ($names as $index => [$firstName, $lastName, $position]) {
            $email = 'demo.'.strtolower($firstName).$index.'@crepe.mx';
            $employee = Employee::query()->firstOrCreate(['email' => $email], [
                'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email,
                'phone' => '999 '.str_pad((string) (400 + $index), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (1800 + $index * 37), 4, '0', STR_PAD_LEFT),
                'position' => $position, 'hired_at' => Carbon::today()->subMonths(3 + $index), 'is_bookable' => true, 'status' => 'active',
                'commission_rate' => 15 + ($index % 4) * 2, 'product_commission_rate' => 5 + ($index % 3),
            ]);
            foreach (range(1, 6) as $day) {
                EmployeeSchedule::query()->updateOrCreate(['employee_id' => $employee->id, 'day_of_week' => $day], ['starts_at' => '09:00', 'ends_at' => '19:00', 'is_available' => true]);
            }
        }

        return Employee::query()->where('is_bookable', true)->where('status', 'active')->orderBy('id')->get();
    }

    /** @return Collection<int, Customer> */
    private function createCustomers(): Collection
    {
        $names = [
            ['Alejandra', 'Sánchez'], ['Camila', 'Pérez'], ['Regina', 'Castillo'], ['Andrea', 'Canto'], ['Gabriela', 'Moo'], ['Natalia', 'Vargas'], ['Paulina', 'Herrera'], ['Sofía', 'Barrera'], ['Jimena', 'Cauich'], ['Claudia', 'Noh'],
            ['Montserrat', 'Poot'], ['Karla', 'Domínguez'], ['Elena', 'Rosado'], ['Diana', 'Medina'], ['Patricia', 'Lara'], ['Valentina', 'Peña'], ['Marisol', 'May'], ['Fátima', 'Carrillo'], ['Laura', 'Ek'], ['Fernanda', 'Cabrera'],
            ['Melissa', 'Vega'], ['Araceli', 'Balam'], ['Silvia', 'Márquez'], ['Dulce', 'Canul'], ['Verónica', 'Pineda'], ['Mariana', 'Góngora'], ['Berenice', 'Méndez'], ['Cecilia', 'Puc'], ['Irene', 'Salazar'], ['Rocío', 'Zapata'],
        ];

        foreach ($names as $index => [$firstName, $lastName]) {
            $email = 'demo.clienta'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'@crepe.mx';
            Customer::query()->firstOrCreate(['email' => $email], [
                'first_name' => $firstName, 'last_name' => $lastName,
                'phone' => '999 '.str_pad((string) (210 + $index), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (3000 + $index * 41), 4, '0', STR_PAD_LEFT),
                'whatsapp' => $index % 3 === 0 ? null : '999 '.str_pad((string) (210 + $index), 3, '0', STR_PAD_LEFT).' '.str_pad((string) (3000 + $index * 41), 4, '0', STR_PAD_LEFT),
                'email' => $email, 'birthday' => Carbon::today()->subYears(24 + ($index % 18))->subDays($index * 4),
                'notes' => $index % 4 === 0 ? 'Prefiere confirmación por WhatsApp y horarios por la tarde.' : null, 'status' => 'active',
            ]);
        }

        return Customer::query()->where('email', 'like', 'demo.clienta%@crepe.mx')->orderBy('id')->get();
    }

    /** @return Collection<int, SalonService> */
    private function createServices(): Collection
    {
        $categories = ServiceCategory::query()->pluck('id', 'name');
        $definitions = [
            ['Corte Express Demo', 'Corte y peinado', 380, 45, 'fixed', false], ['Brushing Demo', 'Corte y peinado', 450, 50, 'fixed', false], ['Ondas de evento Demo', 'Corte y peinado', 720, 90, 'variable', false], ['Gloss de color Demo', 'Coloración', 980, 75, 'variable', true],
            ['Balayage suave Demo', 'Coloración', 1850, 180, 'variable', true], ['Retoque de raíz Demo', 'Coloración', 1100, 105, 'fixed', true], ['Mascarilla nutritiva Demo', 'Tratamientos', 540, 45, 'fixed', false], ['Botox capilar Demo', 'Tratamientos', 1250, 100, 'variable', false],
            ['Ritual anti-frizz Demo', 'Tratamientos', 890, 70, 'fixed', false], ['Peinado editorial Demo', 'Corte y peinado', 950, 110, 'variable', false], ['Color fantasía Demo', 'Coloración', 2200, 210, 'variable', true], ['Diagnóstico capilar Demo', 'Tratamientos', 250, 30, 'fixed', false],
        ];

        foreach ($definitions as $index => [$name, $category, $price, $duration, $priceType, $requiresColorBar]) {
            $service = SalonService::query()->updateOrCreate(['name' => $name], [
                'service_category_id' => $categories[$category], 'description' => 'Servicio de demostración para validar filtros, agenda y tickets.', 'base_cost' => round($price * .42, 2), 'base_price' => $price,
                'estimated_duration_minutes' => $duration, 'commission_rate' => 15 + ($index % 4) * 2, 'price_type' => $priceType, 'requires_color_bar' => $requiresColorBar, 'status' => 'active',
            ]);
            if ($priceType === 'fixed') {
                foreach ([['A', .95], ['B', 1], ['C', 1.12]] as [$tier, $factor]) {
                    $service->prices()->updateOrCreate(['tier' => $tier], ['cost' => round($price * .42, 2), 'sale_price' => round($price * $factor, 2)]);
                }
            }
        }

        return SalonService::query()->where('status', 'active')->orderBy('id')->get();
    }

    /** @return Collection<int, ProductVariant> */
    private function createProducts(): Collection
    {
        $categories = ProductCategory::query()->pluck('id', 'name');
        $names = [
            'Shampoo Brillo', 'Acondicionador Reparador', 'Mascarilla Nutritiva', 'Crema para Peinar', 'Aceite Ligero', 'Spray Termoprotector', 'Ampolleta Hidratación', 'Shampoo Color', 'Acondicionador Color', 'Leave-in Protección',
            'Tinte Castaño', 'Tinte Chocolate', 'Tinte Rubio', 'Tinte Cobrizo', 'Matizador Perla', 'Oxidante 10 vol', 'Oxidante 30 vol', 'Decolorante Polvo', 'Neutralizante', 'Protector de Color', 'Cepillo Ovalado', 'Peine Profesional',
            'Pinzas de Sección', 'Guantes Desechables', 'Toalla Microfibra', 'Capa de Corte', 'Spray Fijador', 'Mousse Volumen', 'Cera Texturizante', 'Serum Puntas',
        ];
        $variants = collect();
        foreach ($names as $index => $name) {
            $isColorBar = $index >= 10 && $index <= 19;
            $category = $isColorBar ? ($index % 3 === 0 ? 'Coloración' : 'Oxidantes') : 'Retail';
            $sku = 'DEMO-PRD-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $unit = $isColorBar ? 'ml' : 'unidad';
            $cost = $isColorBar ? 1.5 + ($index % 5) * .75 : 65 + ($index % 7) * 18;
            $price = round($cost * ($isColorBar ? 2.2 : 2.7), 2);
            $product = Product::query()->updateOrCreate(['sku' => $sku], [
                'product_category_id' => $categories[$category], 'name' => $name.' Demo', 'brand' => $isColorBar ? 'Color Lab' : 'CREPÉ Care', 'sku' => $sku,
                'description' => 'Producto demo para validar inventario, ventas y reportes.', 'is_color_bar_usable' => $isColorBar, 'status' => 'active',
            ]);
            $variants->push(ProductVariant::query()->updateOrCreate(['sku' => $sku.'-STD'], [
                'product_id' => $product->id, 'name' => $isColorBar ? ($index % 2 === 0 ? 'Presentación profesional' : 'Frasco de respaldo') : ($index % 2 === 0 ? '250 ml' : 'Unidad'),
                'sku' => $sku.'-STD', 'base_unit' => $unit, 'content_quantity' => $isColorBar ? 1000 : 1, 'cost' => $cost, 'sale_price' => $price,
                'minimum_stock' => $isColorBar ? 250 : 4, 'maximum_stock' => $isColorBar ? 1500 : 30,
            ]));
        }

        return $variants;
    }

    /** @param Collection<int, ProductVariant> $variants */
    private function seedInventory(Collection $variants, User $actor): void
    {
        $locations = InventoryLocation::query()->pluck('id', 'code');
        $inventory = app(InventoryService::class);
        foreach ($variants as $index => $variant) {
            $isColorBar = (bool) $variant->product->is_color_bar_usable;
            $destination = $isColorBar ? 'CB' : 'REC';
            $inventory->move($variant->id, $locations['ALM'], $isColorBar ? 2500 + ($index * 30) : 40 + ($index % 6) * 8, 'opening', $actor->id, self::class, $variant->id, 'Existencia demo inicial');
            $inventory->move($variant->id, $locations[$destination], $isColorBar ? 800 + ($index * 20) : 12 + ($index % 5) * 2, 'opening', $actor->id, self::class, $variant->id, 'Existencia demo en ubicación');
        }
    }

    /** @return array{0: Collection<int, Appointment>, 1: Collection<int, Ticket>} */
    private function createAppointmentsAndTickets(Collection $customers, Collection $employees, Collection $services, Collection $products, User $actor): array
    {
        $ticketService = app(TicketService::class);
        $inventory = app(InventoryService::class);
        $locations = InventoryLocation::query()->pluck('id', 'code');
        $retailProducts = $products->filter(fn (ProductVariant $variant): bool => ! $variant->product->is_color_bar_usable)->values();
        $colorProducts = ProductVariant::query()->whereHas('product', fn ($query) => $query->where('is_color_bar_usable', true))->orderBy('id')->get()->values();
        $cashSessions = CashSession::query()->where('status', 'open')->orderBy('id')->get();
        $accounts = FinanceAccount::query()->whereIn('name', ['C-Efectivo', 'C-Bancomer'])->pluck('id', 'name');
        $statuses = ['completed', 'confirmed', 'in_service', 'scheduled', 'cancelled'];
        $slots = [9, 10, 11, 12, 13, 14, 15];
        $appointments = collect();
        $tickets = collect();

        for ($index = 0; $index < 30; $index++) {
            $date = Carbon::today()->subDays(14)->addDays($index);
            if ($date->isSunday()) {
                $date->addDay();
            }
            $primary = $employees[$index % $employees->count()];
            $secondary = $index % 6 === 0 ? $employees[($index + 1) % $employees->count()] : null;
            $chosenServices = collect([$services[$index % $services->count()]]);
            $candidate = $services[($index + 3) % $services->count()];
            if ($index % 3 === 0 && $candidate->id !== $chosenServices->first()->id && $chosenServices->sum('estimated_duration_minutes') + $candidate->estimated_duration_minutes <= 210) {
                $chosenServices->push($candidate);
            }
            $startsAt = $date->copy()->setTime($slots[$index % count($slots)], 0);
            $endsAt = $startsAt->copy()->addMinutes($chosenServices->sum('estimated_duration_minutes'));
            $status = $statuses[$index % count($statuses)];
            $customer = $customers[$index];
            $appointment = Appointment::create([
                'customer_id' => $customer->id, 'primary_employee_id' => $primary->id, 'secondary_employee_id' => $secondary?->id, 'starts_at' => $startsAt, 'ends_at' => $endsAt,
                'status' => $status, 'estimated_total' => $chosenServices->sum('base_price'), 'notes' => $index % 5 === 0 ? 'Clienta solicita diagnóstico y recomendaciones de mantenimiento.' : null, 'created_by' => $actor->id,
            ]);
            foreach ($chosenServices as $serviceIndex => $service) {
                $serviceEmployee = $serviceIndex === 1 && $secondary !== null ? $secondary : $primary;
                AppointmentServiceModel::create(['appointment_id' => $appointment->id, 'salon_service_id' => $service->id, 'employee_id' => $serviceEmployee->id, 'name_snapshot' => $service->name, 'estimated_price' => $service->base_price, 'estimated_duration_minutes' => $service->estimated_duration_minutes]);
            }

            $ticket = $ticketService->ensureForAppointment($appointment->load('services.employee'), $actor->id);
            $ticket->refresh()->load('items');
            $confirmPrices = $status === 'completed' || $index % 4 === 0;
            foreach ($ticket->items as $item) {
                $metadata = $item->metadata ?? [];
                $metadata['price_confirmed'] = $confirmPrices;
                $item->update(['metadata' => $metadata]);
                if ($status !== 'cancelled') {
                    CommissionEntry::firstOrCreate(['ticket_id' => $ticket->id, 'ticket_item_id' => $item->id], [
                        'employee_id' => $metadata['employee_id'] ?? $primary->id, 'type' => 'service', 'base_amount' => $item->line_total,
                        'rate_snapshot' => $metadata['commission_rate'] ?? $primary->commission_rate ?? 0, 'amount' => round((float) $item->line_total * ((float) ($metadata['commission_rate'] ?? 0) / 100), 2), 'status' => 'pending',
                    ]);
                }
            }

            if ($index % 2 === 0 && $status !== 'cancelled') {
                $product = $retailProducts[$index % $retailProducts->count()];
                $quantity = 1 + ($index % 2);
                $ticket->items()->create(['type' => 'product', 'name_snapshot' => $product->product->name, 'quantity' => $quantity, 'unit' => $product->base_unit, 'unit_price' => $product->sale_price, 'line_total' => $product->sale_price * $quantity, 'cost_snapshot' => $product->cost, 'status' => 'active', 'metadata' => ['product_id' => $product->product_id, 'product_variant_id' => $product->id, 'employee_id' => $primary->id], 'added_by' => $actor->id]);
                $inventory->move($product->id, $locations['REC'], -$quantity, 'sale', $actor->id, Ticket::class, $ticket->id, 'Producto demo vendido en cita');
            }

            if ($chosenServices->contains(fn (SalonService $service): bool => $service->requires_color_bar) && $status !== 'cancelled' && $colorProducts->isNotEmpty()) {
                $color = $colorProducts[$index % $colorProducts->count()];
                $formula = ColorFormula::create(['ticket_id' => $ticket->id, 'customer_id' => $customer->id, 'inventory_location_id' => $locations['CB'], 'status' => $status === 'completed' ? 'confirmed' : 'draft', 'notes' => 'Fórmula demo para mostrar trazabilidad.', 'created_by' => $actor->id]);
                $quantity = 20 + ($index % 4) * 5;
                ColorFormulaItem::create(['color_formula_id' => $formula->id, 'product_variant_id' => $color->id, 'quantity' => $quantity, 'unit' => $color->base_unit, 'notes' => 'Mezcla demo', 'cost_snapshot' => $color->cost, 'sale_price' => $color->sale_price]);
                $inventory->move($color->id, $locations['CB'], -$quantity, 'color_consumption', $actor->id, ColorFormula::class, $formula->id, 'Consumo demo de fórmula');
                $ticket->items()->create(['type' => 'color_bar', 'name_snapshot' => $color->product->name, 'quantity' => $quantity, 'unit' => $color->base_unit, 'unit_price' => 0, 'line_total' => 0, 'cost_snapshot' => $color->cost, 'status' => 'active', 'metadata' => ['color_formula_id' => $formula->id], 'added_by' => $actor->id]);
            }

            $ticket->refresh();
            if ($status === 'cancelled') {
                $ticket->update(['status' => 'cancelled']);
            } elseif ($status === 'completed') {
                $amount = max(0, $ticket->total);
                if ($amount > 0) {
                    $this->createPayment($ticket, $amount, $index % 2 === 0 ? 'cash' : 'card', $cashSessions, $accounts, $actor, $index);
                }
                $ticket->update(['status' => 'paid', 'paid_at' => $endsAt]);
            } elseif ($index % 5 === 0) {
                $this->createPayment($ticket, round($ticket->total * .25, 2), 'transfer', $cashSessions, $accounts, $actor, $index);
            }
            $appointments->push($appointment);
            $tickets->push($ticket->fresh());
        }

        return [$appointments, $tickets];
    }

    /** @param Collection<int, Customer> $customers */
    private function createProductSales(Collection $customers, Collection $products, User $actor): void
    {
        $inventory = app(InventoryService::class);
        $locationId = InventoryLocation::query()->where('code', 'REC')->value('id');
        $retailProducts = $products->filter(fn (ProductVariant $variant): bool => ! $variant->product->is_color_bar_usable)->values();
        $cashSessions = CashSession::query()->where('status', 'open')->orderBy('id')->get();
        $accounts = FinanceAccount::query()->whereIn('name', ['C-Efectivo', 'C-Bancomer'])->pluck('id', 'name');
        for ($index = 0; $index < 10; $index++) {
            $ticket = Ticket::create(['code' => 'TMP-DEMO-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT), 'customer_id' => $index % 3 === 0 ? null : $customers[($index + 7) % $customers->count()]->id, 'appointment_id' => null, 'ticket_type' => 'product_sale', 'status' => 'open', 'estimated_total' => 0, 'opened_at' => Carbon::now()->subDays($index + 1)->setTime(10 + ($index % 7), 0)]);
            $ticket->update(['code' => '#'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)]);
            $product = $retailProducts[$index % $retailProducts->count()];
            $quantity = 1 + ($index % 3);
            $ticket->items()->create(['type' => 'product', 'name_snapshot' => $product->product->name, 'quantity' => $quantity, 'unit' => $product->base_unit, 'unit_price' => $product->sale_price, 'line_total' => $product->sale_price * $quantity, 'cost_snapshot' => $product->cost, 'status' => 'active', 'metadata' => ['product_id' => $product->product_id, 'product_variant_id' => $product->id], 'added_by' => $actor->id]);
            $inventory->move($product->id, $locationId, -$quantity, 'sale', $actor->id, Ticket::class, $ticket->id, 'Venta de mostrador demo');
            $ticket->refresh();
            if ($index % 2 === 0) {
                $this->createPayment($ticket, $ticket->total, $index % 4 === 0 ? 'cash' : 'card', $cashSessions, $accounts, $actor, 40 + $index);
                $ticket->update(['status' => 'paid', 'paid_at' => now()->subDays($index)]);
            } elseif ($index % 3 === 0) {
                $this->createPayment($ticket, round($ticket->total * .5, 2), 'cash', $cashSessions, $accounts, $actor, 40 + $index);
            }
        }
    }

    /** @return Collection<int, Promotion> */
    private function createPromotions(Collection $services, Collection $products): Collection
    {
        $promotions = collect();
        $retail = $products->filter(fn (ProductVariant $variant): bool => ! $variant->product->is_color_bar_usable)->values();
        $colorService = $services->firstWhere('requires_color_bar', true);
        $definitions = [
            ['10% en tu visita demo', 'DEMO-10-VISITA', 'order', 'percentage', 10, 900, null, null, [], []],
            ['$120 menos en productos demo', 'DEMO-120-RETAIL', 'product', 'fixed', 120, null, null, null, ['product_ids' => [$retail[0]->product_id, $retail[1]->product_id]], []],
            ['Compra 2 y recibe 1 producto demo', 'DEMO-2X1-PRODUCTO', 'buy_x_get_y', null, null, null, 2, 1, ['product_ids' => [$retail[2]->product_id]], ['type' => 'product', 'id' => $retail[3]->id]],
            ['Compra 2 servicios y recibe diagnóstico', 'DEMO-SERVICIO-REGALO', 'buy_x_get_y', null, null, null, 2, 1, ['service_ids' => [$services[0]->id, $services[1]->id]], ['type' => 'service', 'id' => $services->last()->id]],
            ['$200 menos en coloración demo', 'DEMO-COLOR-200', 'product', 'fixed', 200, 1500, null, null, ['service_ids' => [$colorService?->id]], []],
            ['15% en tratamientos demo', 'DEMO-TRAT-15', 'product', 'percentage', 15, null, null, null, ['service_ids' => $services->filter(fn (SalonService $service): bool => ! $service->requires_color_bar)->take(3)->pluck('id')->all()], []],
            ['Regalo de temporada demo', 'DEMO-TEMPORADA', 'order', 'fixed', 250, 2200, null, null, [], []],
            ['Promoción inactiva demo', 'DEMO-INACTIVA', 'order', 'percentage', 20, null, null, null, [], []],
        ];
        foreach ($definitions as $index => [$name, $code, $type, $valueType, $value, $minimum, $buyQuantity, $rewardQuantity, $target, $reward]) {
            $promotions->push(Promotion::query()->updateOrCreate(['code' => $code], ['name' => $name, 'method' => $index % 2 === 0 ? 'automatic' : 'code', 'type' => $type, 'value_type' => $valueType, 'value' => $value, 'minimum_amount' => $minimum, 'buy_quantity' => $buyQuantity, 'reward_quantity' => $rewardQuantity, 'target' => $target, 'reward' => $reward, 'usage_limit' => $index === 7 ? 1 : null, 'usage_count' => 0, 'one_per_customer' => $index % 3 === 0, 'starts_at' => Carbon::now()->subDays(10), 'ends_at' => Carbon::now()->addDays(30), 'status' => $index === 7 ? 'inactive' : 'active']));
        }

        return $promotions;
    }

    /** @param Collection<int, Ticket> $tickets */
    private function createPromotionExample(Collection $tickets, Collection $promotions, User $actor): void
    {
        $ticket = $tickets->first(fn (Ticket $candidate): bool => $candidate->status === 'open' && $candidate->ticket_type === 'appointment');
        $promotion = $promotions->first();
        if ($ticket === null || $promotion === null || $ticket->total <= 0) {
            return;
        }
        TicketAdjustment::create(['ticket_id' => $ticket->id, 'type' => 'discount', 'amount' => -75, 'reason' => $promotion->name, 'metadata' => ['promotion_id' => $promotion->id, 'promotion_code' => $promotion->code], 'created_by' => $actor->id]);
        $promotion->increment('usage_count');
    }

    private function createFinanceTransactions(User $actor): void
    {
        $accounts = FinanceAccount::query()->whereIn('name', ['C-Efectivo', 'C-Bancomer', 'C-Lou', 'C-Pilar'])->pluck('id', 'name');
        $categories = FinanceExpenseCategory::query()->pluck('id', 'name');
        $concepts = ['Venta de producto demo', 'Propina recibida', 'Compra de papelería', 'Servicio de internet', 'Mantenimiento de equipo', 'Ajuste de caja demo'];
        for ($index = 0; $index < 30; $index++) {
            $date = Carbon::today()->subDays($index);
            $type = match ($index % 6) {
                0, 1 => 'income', 2, 3 => 'expense', 4 => 'adjustment', default => 'transfer'
            };
            $source = $accounts[$index % 2 === 0 ? 'C-Efectivo' : 'C-Bancomer'];
            $amount = 150 + ($index * 37.5);
            $base = ['finance_account_id' => $source, 'transfer_to_account_id' => null, 'finance_expense_category_id' => $type === 'expense' ? $categories[['Compras', 'Servicios', 'Otros'][$index % 3]] : null, 'type' => $type, 'direction' => $type === 'income' ? 'in' : ($type === 'expense' ? 'out' : ($type === 'adjustment' ? ($index % 2 === 0 ? 'in' : 'out') : 'out')), 'concept' => $concepts[$index % count($concepts)], 'amount' => round($amount, 2), 'occurred_on' => $date, 'reference' => 'DEMO-MOV-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'notes' => 'Movimiento generado para mostrar filtros y reportes.', 'created_by' => $actor->id];
            if ($type === 'transfer') {
                $destination = $accounts[$index % 2 === 0 ? 'C-Lou' : 'C-Pilar'];
                FinanceTransaction::create(array_merge($base, ['transfer_to_account_id' => $destination, 'direction' => 'out']));
                FinanceTransaction::create(array_merge($base, ['finance_account_id' => $destination, 'transfer_to_account_id' => $source, 'direction' => 'in']));
            } else {
                FinanceTransaction::create($base);
            }
        }
    }

    /** @param Collection<int, ProductVariant> $products */
    private function createInventoryRequests(Collection $products, User $actor): void
    {
        $locations = InventoryLocation::query()->pluck('id', 'code');
        $inventory = app(InventoryService::class);
        $colorProducts = $products->filter(fn (ProductVariant $variant): bool => $variant->product->is_color_bar_usable)->values();
        $retailProducts = $products->filter(fn (ProductVariant $variant): bool => ! $variant->product->is_color_bar_usable)->values();
        for ($index = 0; $index < 12; $index++) {
            $isColorBar = $index % 2 === 0;
            $sourceCode = $isColorBar ? 'CB' : 'REC';
            $sourceProducts = $isColorBar ? $colorProducts : $retailProducts;
            $variant = $sourceProducts[$index % $sourceProducts->count()];
            $requested = 2 + ($index % 4);
            $closed = $index % 3 !== 0;
            $request = InventoryRequest::create(['code' => 'DEMO-SOL-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'source_location_id' => $locations[$sourceCode], 'status' => $closed ? 'closed' : 'pending', 'requested_by' => $actor->id, 'processed_by' => $closed ? $actor->id : null, 'processed_at' => $closed ? Carbon::now()->subDays($index + 1) : null, 'notes' => $index % 4 === 0 ? 'Solicitud demo prioritaria por mínimos de inventario.' : 'Solicitud demo de reposición.', 'closure_notes' => $closed ? ($index % 2 === 0 ? 'Entregada completa.' : 'Entregada parcialmente por existencias disponibles.') : null]);
            $delivered = $closed ? ($index % 2 === 0 ? $requested : max(1, $requested - 1)) : 0;
            $request->items()->create(['product_variant_id' => $variant->id, 'requested_quantity' => $requested, 'delivered_quantity' => $delivered, 'unit' => $variant->base_unit, 'note' => $index % 2 === 0 ? 'Reponer mínimo' : null]);
            if ($closed && $delivered > 0) {
                $inventory->move($variant->id, $locations['ALM'], -$delivered, 'transfer_out', $actor->id, InventoryRequest::class, $request->id, 'Salida demo '.$request->code);
                $inventory->move($variant->id, $locations[$sourceCode], $delivered, 'transfer_in', $actor->id, InventoryRequest::class, $request->id, 'Entrega demo '.$request->code);
            }
        }
    }

    /** @param Collection<int, CashSession> $cashSessions */
    /** @param Collection<string, int> $accounts */
    private function createPayment(Ticket $ticket, float $amount, string $method, Collection $cashSessions, Collection $accounts, User $actor, int $index): void
    {
        if ($amount <= 0 || $cashSessions->isEmpty()) {
            return;
        }
        $accountName = in_array($method, ['card', 'transfer'], true) ? 'C-Bancomer' : 'C-Efectivo';
        Payment::create(['ticket_id' => $ticket->id, 'cash_session_id' => $cashSessions[$index % $cashSessions->count()]->id, 'finance_account_id' => $accounts[$accountName] ?? null, 'type' => 'payment', 'method' => $method, 'amount' => round($amount, 2), 'status' => 'registered', 'reference' => 'DEMO-PAGO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'notes' => 'Pago demo para revisión de reportes.', 'created_by' => $actor->id]);
    }
}
