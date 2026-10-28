<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceTransactionCorrection extends Model
{
    protected $fillable = ['finance_transaction_id', 'replacement_transaction_id', 'request_key', 'created_by', 'reason', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }
}
