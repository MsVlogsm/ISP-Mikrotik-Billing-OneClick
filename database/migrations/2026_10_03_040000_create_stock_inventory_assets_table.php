<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('stock_inventory_assets', function(Blueprint $t){
  $t->id(); $t->foreignId('product_id')->nullable()->constrained('stock_inventory_products')->nullOnDelete();
  $t->string('asset_type',40); $t->string('asset_tag',100)->nullable(); $t->string('serial_no',160)->nullable(); $t->string('mac_address',80)->nullable();
  $t->string('vendor',160)->nullable(); $t->string('model',160)->nullable(); $t->string('status',30)->default('in-stock');
  $t->string('location',190)->nullable(); $t->string('assigned_to',190)->nullable(); $t->string('reference',120)->nullable(); $t->date('purchase_date')->nullable(); $t->date('warranty_end')->nullable(); $t->text('notes')->nullable(); $t->timestamps();
  $t->unique('asset_tag'); $t->unique('serial_no'); $t->index('mac_address'); $t->index(['asset_type','status']);
 }); }
 public function down(): void { Schema::dropIfExists('stock_inventory_assets'); }
};
