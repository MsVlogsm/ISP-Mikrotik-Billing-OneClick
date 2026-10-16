<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('network_topology_links', function (Blueprint $table) {
            $table->id();
            $table->string('source_key', 80);
            $table->string('target_key', 80);
            $table->string('connection_type', 40)->default('ethernet');
            $table->string('label', 120)->nullable();
            $table->string('status', 20)->default('unknown');
            $table->boolean('is_published')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_key', 'target_key', 'connection_type'], 'topology_link_unique');
            $table->index(['is_published', 'connection_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_topology_links');
    }
};
