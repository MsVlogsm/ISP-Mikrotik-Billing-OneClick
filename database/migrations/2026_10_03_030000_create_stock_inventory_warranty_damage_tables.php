<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('stock_inventory_warranties', function(Blueprint $t){
   $t->id(); $t->foreignId('product_id')->constrained('stock_inventory_products')->cascadeOnDelete();
   $t->string('asset_serial',160)->nullable(); $t->string('asset_mac',80)->nullable(); $t->string('vendor',160)->nullable();
   $t->date('warranty_start')->nullable(); $t->date('warranty_end')->nullable(); $t->string('status',30)->default('active');
   $t->string('reference',120)->nullable(); $t->text('notes')->nullable(); $t->timestamps();
   $t->index(['warranty_end','status']); $t->index('asset_serial'); $t->index('asset_mac');
  });
  Schema::create('stock_inventory_damage_records', function(Blueprint $t){
   $t->id(); $t->foreignId('product_id')->constrained('stock_inventory_products')->cascadeOnDelete();
   $t->unsignedInteger('quantity')->default(1); $t->string('asset_serial',160)->nullable(); $t->string('asset_mac',80)->nullable();
   $t->string('record_type',20)->default('damaged'); $t->date('incident_date'); $t->string('reason',190)->nullable();
   $t->string('status',30)->default('open'); $t->string('reference',120)->nullable(); $t->text('notes')->nullable(); $t->timestamps();
   $t->index(['status','incident_date']); $t->index('asset_serial'); $t->index('asset_mac');
  });
 }
 public function down(): void { Schema::dropIfExists('stock_inventory_damage_records'); Schema::dropIfExists('stock_inventory_warranties'); }
};
