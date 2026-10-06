<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('999 ### ####'),
            'position' => 'Estilista',
            'salary' => 600,
            'salary_type' => 'weekly',
            'is_bookable' => true,
            'status' => 'active',
            'requires_check_in' => true,
            'check_pin' => fake()->unique()->numerify('####'),
        ];
    }
}
