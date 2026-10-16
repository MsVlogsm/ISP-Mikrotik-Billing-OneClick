<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class StockInventoryPurchase extends Model {
 protected $fillable=['supplier_id','invoice_no','purchase_date','total_amount','status','notes'];
 protected $casts=['purchase_date'=>'date','total_amount'=>'decimal:2'];
 public function supplier():BelongsTo{return $this->belongsTo(StockInventorySupplier::class,'supplier_id');}
 public function items():HasMany{return $this->hasMany(StockInventoryPurchaseItem::class,'purchase_id');}
}