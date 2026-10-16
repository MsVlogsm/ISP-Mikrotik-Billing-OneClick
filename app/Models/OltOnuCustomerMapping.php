<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OltOnuCustomerMapping extends Model {
 protected $fillable=['olt_device_id','customer_id','ppp_user_id','onu_id','onu_serial','onu_mac','pon_port','distance_m','temperature','supply_voltage','tx_bias_current','onu_type','status','rx_power','tx_power','opm_source','opm_last_seen_at','onu_ip','last_seen_at','notes'];
 protected $casts=['last_seen_at'=>'datetime','opm_last_seen_at'=>'datetime','distance_m'=>'decimal:2','temperature'=>'decimal:2','supply_voltage'=>'decimal:3','tx_bias_current'=>'decimal:2','rx_power'=>'decimal:2','tx_power'=>'decimal:2'];
 public function olt(){return $this->belongsTo(NetworkInventoryDevice::class,'olt_device_id');}
 public function customer(){return $this->belongsTo(CustomersInfo::class,'customer_id');}
 public function pppUser(){return $this->belongsTo(PPPSecrets::class,'ppp_user_id');}
}
