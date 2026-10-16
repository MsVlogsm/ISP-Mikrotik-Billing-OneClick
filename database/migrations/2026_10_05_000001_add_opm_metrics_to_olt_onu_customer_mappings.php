<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('olt_onu_customer_mappings', function (Blueprint $table) {
            if (! Schema::hasColumn('olt_onu_customer_mappings', 'distance_m')) $table->decimal('distance_m', 10, 2)->nullable()->after('pon_port');
            if (! Schema::hasColumn('olt_onu_customer_mappings', 'temperature')) $table->decimal('temperature', 8, 2)->nullable()->after('distance_m');
            if (! Schema::hasColumn('olt_onu_customer_mappings', 'supply_voltage')) $table->decimal('supply_voltage', 8, 3)->nullable()->after('temperature');
            if (! Schema::hasColumn('olt_onu_customer_mappings', 'tx_bias_current')) $table->decimal('tx_bias_current', 10, 2)->nullable()->after('supply_voltage');
            if (! Schema::hasColumn('olt_onu_customer_mappings', 'opm_source')) $table->string('opm_source', 40)->nullable()->after('tx_power');
        });
    }

    public function down(): void
    {
        Schema::table('olt_onu_customer_mappings', function (Blueprint $table) {
            foreach (['opm_source','tx_bias_current','supply_voltage','temperature','distance_m'] as $column) {
                if (Schema::hasColumn('olt_onu_customer_mappings', $column)) $table->dropColumn($column);
            }
        });
    }
};
