<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceTransaction extends Model
{
    protected $fillable = [
        'finance_account_id', 'transfer_to_account_id', 'finance_expense_category_id', 'type', 'direction', 'concept', 'amount', 'occurred_on', 'reference', 'notes',
        'source_type', 'source_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_on' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function transferTo(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'transfer_to_account_id');
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(FinanceExpenseCategory::class, 'finance_expense_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
