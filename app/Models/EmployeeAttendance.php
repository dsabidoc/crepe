<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAttendance extends Model
{
    use HasFactory;

    protected $fillable = ['employee_id', 'work_date', 'checked_in_at', 'checked_out_at', 'scheduled_starts_at', 'scheduled_ends_at', 'late_minutes', 'late_penalty_amount'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'checked_in_at' => 'datetime', 'checked_out_at' => 'datetime', 'late_minutes' => 'integer', 'late_penalty_amount' => 'decimal:2'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
