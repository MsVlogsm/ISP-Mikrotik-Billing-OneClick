<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('network_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained('network_inventory_devices')->nullOnDelete();
            $table->string('source_type', 40)->default('monitoring');
            $table->string('event_type', 60);
            $table->string('severity', 20)->default('warning');
            $table->string('title', 180);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('fingerprint', 100)->nullable()->index();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity', 'created_at']);
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_events');
    }
};
