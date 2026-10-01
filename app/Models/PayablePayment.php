<?php

namespace App\Models;

use Database\Factories\PayablePaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayablePayment extends Model
{
    /** @use HasFactory<PayablePaymentFactory> */
    use HasFactory;

    protected $fillable = ['payable_invoice_id', 'finance_account_id', 'amount', 'paid_on', 'reference', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }

    public function payableInvoice(): BelongsTo
    {
        return $this->belongsTo(PayableInvoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id');
    }
}
