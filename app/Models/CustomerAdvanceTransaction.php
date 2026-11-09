<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAdvanceTransaction extends Model
{
    protected $fillable = [
        'wallet_id', 'customer_id', 'customer_unique_id', 'entry_type', 'direction',
        'amount', 'balance_after', 'payment_method', 'transaction_id', 'bill_month',
        'collection_summary_id', 'related_transaction_id', 'idempotency_key', 'created_by', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CustomerAdvanceWallet::class, 'wallet_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_id');
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(CollectionSummary::class, 'collection_summary_id');
    }

    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }
}
