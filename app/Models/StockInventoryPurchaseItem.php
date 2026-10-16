<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockInventoryPurchaseItem extends Model {
 protected $fillable=['purchase_id','product_id','quantity','unit_cost','line_total'];
 protected $casts=['unit_cost'=>'decimal:2','line_total'=>'decimal:2'];
 public function purchase():BelongsTo{return $this->belongsTo(StockInventoryPurchase::class,'purchase_id');}
 public function product():BelongsTo{return $this->belongsTo(StockInventoryProduct::class,'product_id');}
}