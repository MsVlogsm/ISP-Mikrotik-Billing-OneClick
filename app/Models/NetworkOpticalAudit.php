<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkOpticalAudit extends Model
{
    protected $fillable = ['name', 'olt_device_id', 'reading_count', 'readings', 'notes', 'created_by'];

    protected $casts = ['readings' => 'array'];

    public function olt(): BelongsTo
    {
        return $this->belongsTo(NetworkInventoryDevice::class, 'olt_device_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
