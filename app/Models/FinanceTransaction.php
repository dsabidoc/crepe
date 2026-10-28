<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinanceTransaction extends Model
{
    protected $fillable = [
        'finance_account_id', 'transfer_to_account_id', 'finance_expense_category_id', 'type', 'direction', 'concept', 'amount', 'occurred_on', 'reference', 'notes',
        'transfer_group', 'cash_session_id', 'evidence_path', 'source_type', 'source_id', 'created_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('effective', fn (Builder $query) => $query->whereNotExists(fn ($corrections) => $corrections->selectRaw('1')->from('finance_transaction_corrections')->whereColumn('finance_transaction_corrections.finance_transaction_id', 'finance_transactions.id')));
    }

    public function correction(): HasOne
    {
        return $this->hasOne(FinanceTransactionCorrection::class);
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'occurred_on' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
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
