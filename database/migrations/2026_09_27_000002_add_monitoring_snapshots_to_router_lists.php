<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('router_lists', function (Blueprint $table) {
            if (! Schema::hasColumn('router_lists', 'last_latency_ms')) {
                $table->unsignedInteger('last_latency_ms')->nullable();
            }
            if (! Schema::hasColumn('router_lists', 'last_checked_at')) {
                $table->timestamp('last_checked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('router_lists', function (Blueprint $table) {
            if (Schema::hasColumn('router_lists', 'last_checked_at')) {
                $table->dropColumn('last_checked_at');
            }
            if (Schema::hasColumn('router_lists', 'last_latency_ms')) {
                $table->dropColumn('last_latency_ms');
            }
        });
    }
};
