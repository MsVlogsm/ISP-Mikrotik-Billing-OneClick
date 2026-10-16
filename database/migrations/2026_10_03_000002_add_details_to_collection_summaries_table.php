<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('collection_summaries') && !Schema::hasColumn('collection_summaries','details')) {
            Schema::table('collection_summaries', function (Blueprint $table) { $table->text('details')->nullable()->after('transaction_id'); });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('collection_summaries') && Schema::hasColumn('collection_summaries','details')) {
            Schema::table('collection_summaries', function (Blueprint $table) { $table->dropColumn('details'); });
        }
    }
};
