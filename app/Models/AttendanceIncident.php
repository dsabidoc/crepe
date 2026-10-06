<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceIncident extends Model
{
    use HasFactory;

    protected $fillable = ['employee_id', 'work_date', 'type', 'status', 'resolution', 'reason', 'deduction_amount', 'payroll_run_id', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'deduction_amount' => 'decimal:2', 'resolved_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
