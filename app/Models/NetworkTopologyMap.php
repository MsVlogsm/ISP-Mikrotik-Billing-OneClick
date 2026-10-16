<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkTopologyMap extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_default', 'created_by'];
    protected $casts = ['is_default' => 'boolean'];

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function nodes(): HasMany { return $this->hasMany(NetworkTopologyNode::class, 'map_id'); }
    public function links(): HasMany { return $this->hasMany(NetworkTopologyLink::class, 'map_id'); }
}
