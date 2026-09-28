<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;

class SyncCrepeTeam extends Command
{
    protected $signature = 'crepe:sync-team';

    protected $description = 'Registra o actualiza el equipo inicial de CREPÉ sin inventar datos de contacto';

    /**
     * @var array<int, array{name: string, position: string, product_commission_rate: int|null, service_commission_rate: int|null, is_bookable: bool}>
     */
    private const TEAM = [
        ['name' => 'Anair', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Cris', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 25, 'is_bookable' => true],
        ['name' => 'Ileana', 'position' => 'Cajera', 'product_commission_rate' => 10, 'service_commission_rate' => 0, 'is_bookable' => false],
        ['name' => 'Jou', 'position' => 'Dueña', 'product_commission_rate' => 10, 'service_commission_rate' => 75, 'is_bookable' => true],
        ['name' => 'Liz', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 25, 'is_bookable' => true],
        ['name' => 'Lupis', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Natalia', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Nely', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Pame', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Pilu', 'position' => 'Dueña', 'product_commission_rate' => 10, 'service_commission_rate' => 75, 'is_bookable' => true],
        ['name' => 'Arely', 'position' => 'Estilista', 'product_commission_rate' => null, 'service_commission_rate' => null, 'is_bookable' => true],
        ['name' => 'Vale', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Yaneth', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 30, 'is_bookable' => true],
        ['name' => 'Yulissa', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Lupita Mex', 'position' => 'Cajera', 'product_commission_rate' => 10, 'service_commission_rate' => 0, 'is_bookable' => false],
        ['name' => 'Jeziel', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Ale', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
        ['name' => 'Jasel', 'position' => 'Estilista', 'product_commission_rate' => null, 'service_commission_rate' => null, 'is_bookable' => true],
        ['name' => 'Marian', 'position' => 'Estilista', 'product_commission_rate' => 10, 'service_commission_rate' => 20, 'is_bookable' => true],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        foreach (self::TEAM as $member) {
            Employee::query()->updateOrCreate(
                ['first_name' => $member['name'], 'last_name' => null],
                [
                    'position' => $member['position'],
                    'product_commission_rate' => $member['product_commission_rate'],
                    'commission_rate' => $member['service_commission_rate'],
                    'is_bookable' => $member['is_bookable'],
                    'status' => 'active',
                ],
            );
        }

        $this->info('Equipo sincronizado: '.count(self::TEAM).' colaboradoras registradas.');

        return self::SUCCESS;
    }
}
