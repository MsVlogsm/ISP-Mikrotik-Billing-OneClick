<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentGatewayAttempt extends Model
{
    protected $fillable = [
        'attempt_uuid',
        'gateway',
        'customer_unique_id',
        'amount',
        'currency',
        'merchant_reference',
        'provider_reference',
        'provider_transaction_id',
        'status',
        'provider_metadata',
        'poll_count',
        'last_polled_at',
        'next_poll_at',
        'expires_at',
        'processed_at',
        'last_error',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_metadata' => 'array',
        'last_polled_at' => 'datetime',
        'next_poll_at' => 'datetime',
        'expires_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentGatewayAttempt $attempt): void {
            if (blank($attempt->attempt_uuid)) {
                $attempt->attempt_uuid = (string) Str::uuid();
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_unique_id', 'customer_unique_id');
    }

    public function scopePollable($query)
    {
        return $query
            ->whereIn('status', ['initiated', 'pending'])
            ->whereNull('processed_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where(function ($q): void {
                $q->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now());
            });
    }
}
