<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_gateway_attempts')) {
            return;
        }

        Schema::create('payment_gateway_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_uuid')->unique();
            $table->string('gateway', 24);
            $table->string('customer_unique_id', 100);
            $table->decimal('amount', 11, 2);
            $table->char('currency', 3)->default('BDT');
            $table->string('merchant_reference', 80);
            $table->string('provider_reference', 120)->nullable();
            $table->string('provider_transaction_id', 120)->nullable();
            $table->string('status', 24)->default('initiated');
            $table->json('provider_metadata')->nullable();
            $table->unsignedInteger('poll_count')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('next_poll_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'merchant_reference'], 'payment_gateway_merchant_reference_unique');
            $table->index(['status', 'next_poll_at'], 'payment_gateway_attempts_due_idx');
            $table->index(['gateway', 'provider_reference'], 'payment_gateway_provider_ref_idx');
            $table->index(['customer_unique_id', 'created_at'], 'payment_gateway_customer_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_attempts');
    }
};
