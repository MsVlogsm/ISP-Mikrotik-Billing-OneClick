<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('network_events', function (Blueprint $table) {
            if (! Schema::hasColumn('network_events', 'resolved_by')) {
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('network_events', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('network_events', function (Blueprint $table) {
            if (Schema::hasColumn('network_events', 'resolved_by')) {
                $table->dropConstrainedForeignId('resolved_by');
            }
            if (Schema::hasColumn('network_events', 'resolved_at')) {
                $table->dropColumn('resolved_at');
            }
        });
    }
};
