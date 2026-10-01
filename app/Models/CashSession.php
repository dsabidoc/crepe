<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    protected $fillable = [
        'actual_card', 'actual_cash', 'actual_change', 'actual_gift_card', 'actual_other', 'actual_transfer', 'business_date',
        'cash_register_id', 'cashier_notes', 'closed_at', 'closed_by', 'difference', 'expected_card',
        'expected_cash', 'expected_gift_card', 'expected_other', 'expected_transfer', 'opened_at', 'opened_by', 'opening_float',
        'status', 'verification_notes', 'verified_at', 'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'actual_card' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'actual_change' => 'decimal:2',
            'actual_gift_card' => 'decimal:2',
            'actual_other' => 'decimal:2',
            'actual_transfer' => 'decimal:2',
            'business_date' => 'date',
            'closed_at' => 'datetime',
            'difference' => 'decimal:2',
            'expected_card' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'expected_gift_card' => 'decimal:2',
            'expected_other' => 'decimal:2',
            'expected_transfer' => 'decimal:2',
            'opened_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cashTransactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
