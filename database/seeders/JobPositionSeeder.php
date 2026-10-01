<?php

namespace Database\Seeders;

use App\Models\JobPosition;
use Illuminate\Database\Seeder;

class JobPositionSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const DEFAULT_POSITIONS = [
        'Dueña', 'Gerente', 'Administración', 'Recepción', 'Cajera',
        'Estilista', 'Estilista senior', 'Colorista', 'Almacén', 'Asistente',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::DEFAULT_POSITIONS as $index => $name) {
            JobPosition::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => true, 'sort_order' => $index + 1],
            );
        }
    }
}
