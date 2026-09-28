<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTimeBlock extends Model
{
    protected $fillable = ['employee_id', 'starts_at', 'ends_at', 'type', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
