<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends Model
{
    protected $fillable = ['name', 'type', 'initial_balance', 'is_active', 'is_primary', 'created_by'];

    protected function casts(): array
    {
        return ['initial_balance' => 'decimal:2', 'is_active' => 'boolean', 'is_primary' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function payablePayments(): HasMany
    {
        return $this->hasMany(PayablePayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
