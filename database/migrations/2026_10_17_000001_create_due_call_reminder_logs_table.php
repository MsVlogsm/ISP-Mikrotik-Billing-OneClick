<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('due_call_reminder_logs', function(Blueprint $table) { $table->id(); $table->foreignId('customer_id')->nullable()->constrained('customers_infos')->nullOnDelete(); $table->string('customer_code',100)->index(); $table->string('customer_name',190)->nullable(); $table->string('phone',40); $table->decimal('due_amount',12,2)->default(0); $table->date('reminder_date'); $table->string('status',30)->default('pending')->index(); $table->string('provider_call_id',190)->nullable(); $table->text('provider_response')->nullable(); $table->timestamp('attempted_at')->nullable(); $table->timestamps(); $table->unique(['customer_code','reminder_date'],'due_call_customer_date_unique'); }); }
 public function down(): void { Schema::dropIfExists('due_call_reminder_logs'); }
};
