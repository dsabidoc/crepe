<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAttendance>
 */
class EmployeeAttendanceFactory extends Factory
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
            'checked_in_at' => today()->setTime(9, 0),
            'checked_out_at' => today()->setTime(17, 0),
            'scheduled_starts_at' => '09:00:00',
            'scheduled_ends_at' => '17:00:00',
            'late_minutes' => 0,
            'late_penalty_amount' => 0,
        ];
    }
}
