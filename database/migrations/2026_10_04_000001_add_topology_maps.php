<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('network_topology_maps', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $defaultId = DB::table('network_topology_maps')->insertGetId([
            'name' => 'Main Network',
            'slug' => 'main-network',
            'description' => 'Primary X-Link network topology map.',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('network_topology_nodes', function (Blueprint $table) {
            $table->foreignId('map_id')->nullable()->after('id')->constrained('network_topology_maps')->cascadeOnDelete();
            $table->index(['map_id', 'is_published']);
        });
        Schema::table('network_topology_links', function (Blueprint $table) {
            $table->dropUnique('topology_link_unique');
            $table->foreignId('map_id')->nullable()->after('id')->constrained('network_topology_maps')->cascadeOnDelete();
            $table->unique(['map_id', 'source_key', 'target_key', 'connection_type'], 'topology_map_link_unique');
            $table->index(['map_id', 'is_published']);
        });

        DB::table('network_topology_nodes')->whereNull('map_id')->update(['map_id' => $defaultId]);
        DB::table('network_topology_links')->whereNull('map_id')->update(['map_id' => $defaultId]);
    }

    public function down(): void
    {
        Schema::table('network_topology_links', function (Blueprint $table) { $table->dropConstrainedForeignId('map_id'); });
        Schema::table('network_topology_nodes', function (Blueprint $table) { $table->dropConstrainedForeignId('map_id'); });
        Schema::dropIfExists('network_topology_maps');
    }
};
