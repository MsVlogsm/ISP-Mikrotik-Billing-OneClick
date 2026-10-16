<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mikrotik_pending_actions')) {
            return;
        }

        Schema::create('mikrotik_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->nullable()->index();
            $table->string('router_name')->index();
            $table->string('username');
            $table->string('action');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_pending_actions');
    }
};
