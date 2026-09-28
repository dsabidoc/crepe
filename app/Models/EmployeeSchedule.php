<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSchedule extends Model
{
    protected $fillable = ['employee_id', 'day_of_week', 'starts_at', 'ends_at', 'is_available'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
