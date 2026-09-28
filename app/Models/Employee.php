<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'email', 'phone', 'position',
        'hired_at', 'salary', 'salary_type', 'is_bookable', 'status', 'commission_rate', 'product_commission_rate', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
            'commission_rate' => 'decimal:2',
            'product_commission_rate' => 'decimal:2',
            'hired_at' => 'date',
            'salary' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function timeBlocks(): HasMany
    {
        return $this->hasMany(EmployeeTimeBlock::class);
    }
}
