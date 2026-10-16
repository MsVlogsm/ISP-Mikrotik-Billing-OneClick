<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('olt_onu_customer_mappings', function(Blueprint $t){
   $t->id();
   $t->foreignId('olt_device_id')->constrained('network_inventory_devices')->cascadeOnDelete();
   $t->foreignId('customer_id')->nullable()->constrained('customers_infos')->nullOnDelete();
   $t->foreignId('ppp_user_id')->nullable()->constrained('p_p_p_secrets')->nullOnDelete();
   $t->string('onu_id')->nullable(); $t->string('onu_serial')->nullable(); $t->string('onu_mac')->nullable();
   $t->string('pon_port')->nullable(); $t->string('onu_type')->nullable();
   $t->string('status')->default('unknown'); $t->decimal('rx_power',7,2)->nullable(); $t->decimal('tx_power',7,2)->nullable();
   $t->string('onu_ip')->nullable(); $t->timestamp('last_seen_at')->nullable(); $t->text('notes')->nullable();
   $t->timestamps();
   $t->unique(['olt_device_id','onu_id']); $t->index(['onu_serial']); $t->index(['onu_mac']); $t->index(['customer_id']);
  });
 }
 public function down(): void { Schema::dropIfExists('olt_onu_customer_mappings'); }
};
