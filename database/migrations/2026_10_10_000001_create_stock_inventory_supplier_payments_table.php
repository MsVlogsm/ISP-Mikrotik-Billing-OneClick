<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('stock_inventory_supplier_payments', function(Blueprint $t){ $t->id(); $t->foreignId('supplier_id')->constrained('stock_inventory_suppliers')->cascadeOnDelete(); $t->date('payment_date'); $t->decimal('amount',12,2); $t->string('method',30)->default('cash'); $t->string('reference',120)->nullable(); $t->text('notes')->nullable(); $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete(); $t->timestamps(); $t->index(['supplier_id','payment_date']); }); }
 public function down(): void { Schema::dropIfExists('stock_inventory_supplier_payments'); }
};
