<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('customer_connection_snapshots')) return;
        Schema::create('customer_connection_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->index();
            $table->string('router_name')->nullable();
            $table->string('ppp_username')->nullable();
            $table->string('state', 10);
            $table->string('ip_address')->nullable();
            $table->string('uptime')->nullable();
            $table->timestamp('sampled_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('customer_connection_snapshots'); }
};
