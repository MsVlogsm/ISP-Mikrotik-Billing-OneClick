<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsDispatchLog extends Model
{
    protected $fillable = [
        'customer_id', 'user_id', 'recipient', 'customer_code', 'customer_name',
        'sms_type', 'sms_count', 'message', 'status', 'gateway_response', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'sms_count' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
