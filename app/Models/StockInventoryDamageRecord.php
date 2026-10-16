<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockInventoryDamageRecord extends Model { protected $fillable=['product_id','quantity','asset_serial','asset_mac','record_type','incident_date','reason','status','reference','notes']; protected $casts=['incident_date'=>'date']; public function product():BelongsTo{return $this->belongsTo(StockInventoryProduct::class,'product_id');} }
