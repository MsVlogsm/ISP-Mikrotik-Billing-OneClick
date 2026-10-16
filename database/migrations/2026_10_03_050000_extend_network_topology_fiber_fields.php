<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('network_topology_nodes', function (Blueprint $table) {
            $table->string('subtype', 60)->nullable()->after('type');
            $table->string('port_reference', 80)->nullable()->after('location');
            $table->unsignedInteger('splitter_ratio')->nullable()->after('port_reference');
            $table->unsignedInteger('port_capacity')->nullable()->after('splitter_ratio');
            $table->decimal('latitude', 10, 7)->nullable()->after('port_capacity');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
        Schema::table('network_topology_links', function (Blueprint $table) {
            $table->unsignedBigInteger('capacity_mbps')->nullable()->after('label');
            $table->unsignedBigInteger('traffic_mbps')->nullable()->after('capacity_mbps');
            $table->decimal('latency_ms', 10, 2)->nullable()->after('traffic_mbps');
            $table->decimal('packet_loss', 5, 2)->nullable()->after('latency_ms');
            $table->unsignedInteger('fiber_core')->nullable()->after('packet_loss');
            $table->string('fiber_type', 40)->nullable()->after('fiber_core');
        });
    }

    public function down(): void
    {
        Schema::table('network_topology_nodes', function (Blueprint $table) {
            $table->dropColumn(['subtype','port_reference','splitter_ratio','port_capacity','latitude','longitude']);
        });
        Schema::table('network_topology_links', function (Blueprint $table) {
            $table->dropColumn(['capacity_mbps','traffic_mbps','latency_ms','packet_loss','fiber_core','fiber_type']);
        });
    }
};
