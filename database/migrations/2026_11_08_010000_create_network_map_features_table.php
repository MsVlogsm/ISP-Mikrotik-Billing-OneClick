<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('network_map_features')) {
            return;
        }

        Schema::create('network_map_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('map_id')->constrained('network_topology_maps')->cascadeOnDelete();
            $table->string('feature_type', 32);
            $table->string('name', 160);
            $table->json('geometry');
            $table->json('properties')->nullable();
            $table->string('status', 24)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['map_id', 'status']);
            $table->index(['map_id', 'feature_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_map_features');
    }
};
