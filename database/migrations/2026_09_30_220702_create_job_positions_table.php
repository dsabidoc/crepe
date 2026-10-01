<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $defaults = [
            'Dueña', 'Gerente', 'Administración', 'Recepción', 'Cajera',
            'Estilista', 'Estilista senior', 'Colorista', 'Almacén', 'Asistente',
        ];
        $existingPositions = DB::table('employees')
            ->whereNotNull('position')
            ->where('position', '<>', '')
            ->distinct()
            ->pluck('position')
            ->map(fn (string $position): string => trim($position))
            ->filter()
            ->all();
        $positions = array_values(array_unique([...$defaults, ...$existingPositions]));
        $now = now();

        DB::table('job_positions')->insert(array_map(
            fn (string $name, int $index): array => [
                'name' => $name,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $positions,
            array_keys($positions),
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_positions');
    }
};
