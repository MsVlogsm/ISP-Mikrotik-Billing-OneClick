<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('network_inventory_devices', 'enable_password')) {
            Schema::table('network_inventory_devices', function (Blueprint $table): void {
                $table->text('enable_password')->nullable()->after('password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('network_inventory_devices', 'enable_password')) {
            Schema::table('network_inventory_devices', function (Blueprint $table): void {
                $table->dropColumn('enable_password');
            });
        }
    }
};
