<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resellers') && ! Schema::hasColumn('resellers', 'panel_type')) {
            Schema::table('resellers', function (Blueprint $table) {
                $table->string('panel_type', 20)->default('standard')->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('resellers') && Schema::hasColumn('resellers', 'panel_type')) {
            Schema::table('resellers', function (Blueprint $table) {
                $table->dropIndex(['panel_type']);
                $table->dropColumn('panel_type');
            });
        }
    }
};
