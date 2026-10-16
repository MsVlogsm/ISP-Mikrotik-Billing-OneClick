<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('official_infos') && Schema::hasColumn('official_infos','connection_type')) {
            Schema::table('official_infos', function (Blueprint $table) {
                $table->string('connection_type', 80)->default('fiber')->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally retain the expanded string column. Reverting to the old
        // three-value enum could fail or truncate valid customer records.
    }
};
