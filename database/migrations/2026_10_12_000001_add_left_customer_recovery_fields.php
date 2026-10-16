<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers_infos', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers_infos', 'left_date')) $table->timestamp('left_date')->nullable();
            if (! Schema::hasColumn('customers_infos', 'left_reason')) $table->text('left_reason')->nullable();
            if (! Schema::hasColumn('customers_infos', 'zone_name')) $table->string('zone_name', 120)->nullable();
            if (! Schema::hasColumn('customers_infos', 'left_equipment')) $table->text('left_equipment')->nullable();
            if (! Schema::hasColumn('customers_infos', 'recovery_status')) $table->string('recovery_status', 40)->nullable();
            if (! Schema::hasColumn('customers_infos', 'server_label')) $table->string('server_label', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers_infos', function (Blueprint $table): void {
            foreach (['left_date', 'left_reason', 'zone_name', 'left_equipment', 'recovery_status', 'server_label'] as $column) {
                if (Schema::hasColumn('customers_infos', $column)) $table->dropColumn($column);
            }
        });
    }
};
