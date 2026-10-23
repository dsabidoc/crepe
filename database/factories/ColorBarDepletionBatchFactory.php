<?php

namespace Database\Factories;

use App\Models\ColorBarDepletionBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ColorBarDepletionBatch> */
class ColorBarDepletionBatchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'created_by' => User::factory(),
            'request_key' => fake()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
            'notes' => null,
        ];
    }
}
