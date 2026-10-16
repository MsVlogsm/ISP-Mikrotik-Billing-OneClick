<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DueCallReminderLog extends Model {
 protected $fillable=['customer_id','customer_code','customer_name','phone','due_amount','reminder_date','status','provider_call_id','provider_response','attempted_at'];
 protected $casts=['due_amount'=>'decimal:2','reminder_date'=>'date','attempted_at'=>'datetime'];
 public function customer(){return $this->belongsTo(CustomersInfo::class,'customer_id');}
}
