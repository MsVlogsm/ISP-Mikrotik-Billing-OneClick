<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('customer_wifi_router_logins')) return;
        Schema::create('customer_wifi_router_logins', function (Blueprint $table) {
            $table->id();
            $table->string('customer_unique_id')->unique();
            $table->string('customer_ip')->nullable();
            $table->unsignedSmallInteger('wifi_port')->default(80);
            $table->string('username')->nullable();
            $table->text('password_encrypted')->nullable();
            $table->string('local_login_url', 1000)->nullable();
            $table->string('remote_login_url', 1000)->nullable();
            $table->timestamps();
            $table->index('customer_unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_wifi_router_logins');
    }
};
