<?php

namespace Database\Factories;

use App\Models\AttendanceIncident;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceIncident>
 */
class AttendanceIncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => today(),
            'type' => 'absence',
            'status' => 'pending',
            'deduction_amount' => 0,
        ];
    }
}
