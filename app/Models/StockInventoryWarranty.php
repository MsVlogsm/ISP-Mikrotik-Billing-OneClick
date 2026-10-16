<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockInventoryWarranty extends Model { protected $fillable=['product_id','asset_serial','asset_mac','vendor','warranty_start','warranty_end','status','reference','notes']; protected $casts=['warranty_start'=>'date','warranty_end'=>'date']; public function product():BelongsTo{return $this->belongsTo(StockInventoryProduct::class,'product_id');} }
