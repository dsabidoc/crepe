<?php

namespace App\Models;

use Database\Factories\PayrollItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollItem extends Model
{
    /** @use HasFactory<PayrollItemFactory> */
    use HasFactory;

    protected $fillable = ['payroll_run_id', 'employee_id', 'employee_name_snapshot', 'base_pay', 'service_commissions', 'product_commissions', 'infonavit_deduction', 'other_deductions', 'tardiness_deduction', 'absence_deduction', 'total'];

    protected function casts(): array
    {
        return ['base_pay' => 'decimal:2', 'service_commissions' => 'decimal:2', 'product_commissions' => 'decimal:2', 'infonavit_deduction' => 'decimal:2', 'other_deductions' => 'decimal:2', 'tardiness_deduction' => 'decimal:2', 'absence_deduction' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }
}
