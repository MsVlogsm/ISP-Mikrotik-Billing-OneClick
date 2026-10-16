<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sms_dispatch_logs')) return;

        Schema::create('sms_dispatch_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers_infos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient', 40);
            $table->string('customer_code', 100)->nullable();
            $table->string('customer_name', 190)->nullable();
            $table->string('sms_type', 80)->default('Direct SMS');
            $table->unsignedSmallInteger('sms_count')->default(1);
            $table->text('message');
            $table->string('status', 20)->default('pending')->index();
            $table->text('gateway_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'status']);
            $table->index(['customer_code', 'sms_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_dispatch_logs');
    }
};
