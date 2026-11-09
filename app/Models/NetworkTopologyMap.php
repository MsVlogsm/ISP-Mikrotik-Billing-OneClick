<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkTopologyMap extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'is_default', 'created_by', 'owner_user_id', 'public_token_hash', 'public_link_active', 'public_link_expires_at'];
    protected $casts = ['is_default' => 'boolean', 'public_link_active' => 'boolean', 'public_link_expires_at' => 'datetime'];

    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function ownerUser(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function nodes(): HasMany { return $this->hasMany(NetworkTopologyNode::class, 'map_id'); }
    public function links(): HasMany { return $this->hasMany(NetworkTopologyLink::class, 'map_id'); }
}
