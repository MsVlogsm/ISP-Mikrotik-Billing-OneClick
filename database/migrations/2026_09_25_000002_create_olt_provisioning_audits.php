<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('olt_provisioning_audits', function(Blueprint $table){
  $table->id(); $table->foreignId('olt_device_id')->constrained('network_inventory_devices')->cascadeOnDelete();
  $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
  $table->string('action',50); $table->string('target',160)->nullable(); $table->string('model_profile',120)->nullable();
  $table->string('transport',30)->nullable(); $table->json('request_payload')->nullable(); $table->text('result')->nullable();
  $table->text('error')->nullable(); $table->unsignedInteger('duration_ms')->nullable(); $table->string('status',20)->default('pending');
  $table->ipAddress('source_ip')->nullable(); $table->timestamps(); $table->index(['olt_device_id','created_at']); $table->index(['status','created_at']);
 });}
 public function down(): void { Schema::dropIfExists('olt_provisioning_audits'); }
};