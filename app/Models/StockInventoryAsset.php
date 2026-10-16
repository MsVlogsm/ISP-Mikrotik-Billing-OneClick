<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockInventoryAsset extends Model { protected $fillable=['product_id','asset_type','asset_tag','serial_no','mac_address','vendor','model','status','location','assigned_to','reference','purchase_date','warranty_end','notes']; protected $casts=['purchase_date'=>'date','warranty_end'=>'date']; public function product():BelongsTo{return $this->belongsTo(StockInventoryProduct::class,'product_id');} }
