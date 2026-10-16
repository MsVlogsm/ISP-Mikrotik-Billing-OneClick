<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_event_alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_event_id')->constrained('network_events')->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['network_event_id', 'channel']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_event_alert_deliveries');
    }
};