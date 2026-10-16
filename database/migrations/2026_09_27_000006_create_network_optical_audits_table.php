<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('network_optical_audits', function (Blueprint $table) {
            $table->id();
            $table->string('name', 140);
            $table->foreignId('olt_device_id')->nullable()->constrained('network_inventory_devices')->nullOnDelete();
            $table->unsignedInteger('reading_count')->default(0);
            $table->json('readings');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['olt_device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_optical_audits');
    }
};
