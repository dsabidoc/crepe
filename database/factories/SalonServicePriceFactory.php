<?php

namespace Database\Factories;

use App\Models\SalonService;
use App\Models\SalonServicePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalonServicePrice>
 */
class SalonServicePriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'salon_service_id' => SalonService::factory(),
            'tier' => 'A',
            'cost' => fake()->randomFloat(2, 50, 500),
            'sale_price' => fake()->randomFloat(2, 300, 2000),
        ];
    }
}
