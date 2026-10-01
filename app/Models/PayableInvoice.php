<?php

namespace App\Models;

use Database\Factories\PayableInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayableInvoice extends Model
{
    /** @use HasFactory<PayableInvoiceFactory> */
    use HasFactory;

    protected $fillable = ['purchase_order_id', 'invoice_reference', 'invoice_path', 'amount', 'paid_amount', 'due_on', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'due_on' => 'date'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class);
    }
}
