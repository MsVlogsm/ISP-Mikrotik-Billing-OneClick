<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::table('network_topology_nodes', function(Blueprint $t){ $t->unsignedInteger('input_ports')->nullable()->after('splitter_ratio'); $t->unsignedInteger('output_ports')->nullable()->after('input_ports'); });
  Schema::table('network_topology_links', function(Blueprint $t){ $t->string('source_port',40)->nullable()->after('fiber_type'); $t->string('target_port',40)->nullable()->after('source_port'); });
 }
 public function down(): void {
  Schema::table('network_topology_nodes', fn(Blueprint $t)=>$t->dropColumn(['input_ports','output_ports']));
  Schema::table('network_topology_links', fn(Blueprint $t)=>$t->dropColumn(['source_port','target_port']));
 }
};
