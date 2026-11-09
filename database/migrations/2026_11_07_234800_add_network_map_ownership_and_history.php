<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('network_topology_maps', 'owner_user_id')) {
            Schema::table('network_topology_maps', function (Blueprint $table) {
                $table->foreignId('owner_user_id')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('network_topology_maps', 'public_token_hash')) {
            Schema::table('network_topology_maps', function (Blueprint $table) {
                $table->string('public_token_hash', 64)->nullable()->unique();
            });
        }

        if (! Schema::hasColumn('network_topology_maps', 'public_link_active')) {
            Schema::table('network_topology_maps', function (Blueprint $table) {
                $table->boolean('public_link_active')->default(false);
            });
        }

        if (! Schema::hasColumn('network_topology_maps', 'public_link_expires_at')) {
            Schema::table('network_topology_maps', function (Blueprint $table) {
                $table->timestamp('public_link_expires_at')->nullable();
            });
        }

        DB::table('network_topology_maps')
            ->whereNull('owner_user_id')
            ->update(['owner_user_id' => DB::raw('created_by')]);

        if (! Schema::hasTable('network_topology_map_snapshots')) {
            Schema::create('network_topology_map_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('map_id')->constrained('network_topology_maps')->cascadeOnDelete();
                $table->string('label', 160);
                $table->json('snapshot_data');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['map_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('network_topology_map_snapshots');

        if (Schema::hasColumn('network_topology_maps', 'public_link_expires_at')) {
            Schema::table('network_topology_maps', fn (Blueprint $table) => $table->dropColumn('public_link_expires_at'));
        }
        if (Schema::hasColumn('network_topology_maps', 'public_link_active')) {
            Schema::table('network_topology_maps', fn (Blueprint $table) => $table->dropColumn('public_link_active'));
        }
        if (Schema::hasColumn('network_topology_maps', 'public_token_hash')) {
            Schema::table('network_topology_maps', function (Blueprint $table) {
                $table->dropUnique(['public_token_hash']);
                $table->dropColumn('public_token_hash');
            });
        }
        if (Schema::hasColumn('network_topology_maps', 'owner_user_id')) {
            Schema::table('network_topology_maps', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_user_id'));
        }
    }
};
