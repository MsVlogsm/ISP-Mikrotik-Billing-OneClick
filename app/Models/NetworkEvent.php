<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Jobs\SendNetworkEventAlertJob;

class NetworkEvent extends Model
{
    protected static function booted(): void
    {
        static::created(function (NetworkEvent $event) {
            if ($event->status === 'open') {
                SendNetworkEventAlertJob::dispatch($event->id);
            }
        });
    }

    protected $fillable = [
        'device_id','source_type','event_type','severity','title','message','status',
        'acknowledged_by','acknowledged_at','resolved_by','resolved_at','fingerprint','occurrences','first_seen_at',
        'last_seen_at','metadata',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function alertDeliveries(): HasMany
    {
        return $this->hasMany(NetworkEventAlertDelivery::class, 'network_event_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'device_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
