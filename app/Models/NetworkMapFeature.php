<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkMapFeature extends Model
{
    protected $fillable = [
        'map_id', 'feature_type', 'name', 'geometry', 'properties',
        'status', 'created_by', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'geometry' => 'array',
        'properties' => 'array',
        'verified_at' => 'datetime',
    ];

    public function map(): BelongsTo
    {
        return $this->belongsTo(NetworkTopologyMap::class, 'map_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
