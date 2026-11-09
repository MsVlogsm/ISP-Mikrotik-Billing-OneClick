<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAdvanceWallet extends Model
{
    protected $fillable = ['customer_id', 'customer_unique_id', 'balance'];

    protected $casts = ['balance' => 'decimal:2'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerAdvanceTransaction::class, 'wallet_id');
    }
}
