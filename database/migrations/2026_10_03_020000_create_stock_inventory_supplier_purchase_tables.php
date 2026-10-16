<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('stock_inventory_suppliers',function(Blueprint $t){$t->id();$t->string('name');$t->string('phone')->nullable();$t->string('email')->nullable();$t->text('address')->nullable();$t->string('status')->default('active');$t->text('notes')->nullable();$t->timestamps();});
  Schema::create('stock_inventory_purchases',function(Blueprint $t){$t->id();$t->foreignId('supplier_id')->nullable()->constrained('stock_inventory_suppliers')->nullOnDelete();$t->string('invoice_no')->nullable();$t->date('purchase_date');$t->decimal('total_amount',12,2)->default(0);$t->string('status')->default('received');$t->text('notes')->nullable();$t->timestamps();});
  Schema::create('stock_inventory_purchase_items',function(Blueprint $t){$t->id();$t->foreignId('purchase_id')->constrained('stock_inventory_purchases')->cascadeOnDelete();$t->foreignId('product_id')->constrained('stock_inventory_products')->restrictOnDelete();$t->unsignedInteger('quantity');$t->decimal('unit_cost',12,2);$t->decimal('line_total',12,2);$t->timestamps();});
 }
 public function down(): void {Schema::dropIfExists('stock_inventory_purchase_items');Schema::dropIfExists('stock_inventory_purchases');Schema::dropIfExists('stock_inventory_suppliers');}
};