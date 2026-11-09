<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advance_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers_infos')->restrictOnDelete();
            $table->unique('customer_id', 'customer_advance_wallets_customer_id_unique');
            $table->string('customer_unique_id')->unique();
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('customer_advance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('customer_advance_wallets')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers_infos')->restrictOnDelete();
            $table->string('customer_unique_id')->index();
            $table->string('entry_type', 32);
            $table->string('direction', 8);
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('payment_method', 32)->nullable();
            $table->string('transaction_id', 120)->nullable()->index();
            $table->char('bill_month', 7)->nullable()->index();
            $table->foreignId('collection_summary_id')->nullable()->constrained('collection_summaries')->nullOnDelete();
            $table->foreignId('related_transaction_id')->nullable()->constrained('customer_advance_transactions')->nullOnDelete();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at'], 'customer_advance_customer_date_idx');
            $table->index(['wallet_id', 'id'], 'customer_advance_wallet_sequence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_advance_transactions');
        Schema::dropIfExists('customer_advance_wallets');
    }
};
