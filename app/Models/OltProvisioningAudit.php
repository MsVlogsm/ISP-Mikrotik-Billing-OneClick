<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class OltProvisioningAudit extends Model {
 protected $fillable=['olt_device_id','user_id','action','target','model_profile','transport','request_payload','result','error','duration_ms','status','source_ip'];
 protected $casts=['request_payload'=>'array'];
 public function olt(): BelongsTo { return $this->belongsTo(NetworkInventoryDevice::class,'olt_device_id'); }
 public function user(): BelongsTo { return $this->belongsTo(User::class); }
}