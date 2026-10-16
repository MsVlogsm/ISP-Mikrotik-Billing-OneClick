<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkEventAlertDelivery extends Model
{
    protected $fillable = [
        'network_event_id','channel','status','http_status','error','attempts','sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(NetworkEvent::class, 'network_event_id');
    }
}