<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('network_inventory_devices', function (Blueprint $table) {
            // Some production databases already have these fields from manual setup.
            // Guard each addition so this migration is safe in both environments.
            if (! Schema::hasColumn('network_inventory_devices', 'username')) $table->text('username')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'password')) $table->text('password')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'snmp_community')) $table->text('snmp_community')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'api_token')) $table->text('api_token')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'api_endpoint')) $table->string('api_endpoint', 255)->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'snmp_version')) $table->string('snmp_version', 10)->default('2C');
            if (! Schema::hasColumn('network_inventory_devices', 'ssh_port')) $table->unsignedInteger('ssh_port')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'telnet_port')) $table->unsignedInteger('telnet_port')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'api_port')) $table->unsignedInteger('api_port')->nullable();
            if (! Schema::hasColumn('network_inventory_devices', 'ssh_enabled')) $table->boolean('ssh_enabled')->default(false);
            if (! Schema::hasColumn('network_inventory_devices', 'telnet_enabled')) $table->boolean('telnet_enabled')->default(false);
            if (! Schema::hasColumn('network_inventory_devices', 'api_enabled')) $table->boolean('api_enabled')->default(false);
            if (! Schema::hasColumn('network_inventory_devices', 'provisioning_enabled')) $table->boolean('provisioning_enabled')->default(false);
        });
    }

    public function down(): void
    {
        // Intentionally retain these connection fields on rollback. Existing production
        // databases may have had them before this migration, so dropping them is unsafe.
    }
};
