<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockInventorySupplierPayment extends Model {
 protected $fillable=['supplier_id','payment_date','amount','method','reference','notes','recorded_by'];
 protected $casts=['payment_date'=>'date','amount'=>'decimal:2'];
 public function supplier():BelongsTo{return $this->belongsTo(StockInventorySupplier::class,'supplier_id');}
}
