<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('olt_onu_customer_mappings', 'opm_last_seen_at')) {
            Schema::table('olt_onu_customer_mappings', function (Blueprint $table) {
                $table->timestamp('opm_last_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('olt_onu_customer_mappings', 'opm_last_seen_at')) {
            Schema::table('olt_onu_customer_mappings', function (Blueprint $table) {
                $table->dropColumn('opm_last_seen_at');
            });
        }
    }
};
