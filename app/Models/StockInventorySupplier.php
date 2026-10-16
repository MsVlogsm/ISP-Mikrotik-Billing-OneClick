<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class StockInventorySupplier extends Model {
 protected $fillable=['name','phone','email','address','status','notes'];
 public function purchases():HasMany{return $this->hasMany(StockInventoryPurchase::class,'supplier_id');}
 public function payments():HasMany{return $this->hasMany(StockInventorySupplierPayment::class,'supplier_id');}
}