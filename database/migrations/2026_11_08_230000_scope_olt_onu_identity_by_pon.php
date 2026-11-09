<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexNameFor(array $columns): ?string
    {
        $expected = array_map('strtolower', $columns);
        foreach (Schema::getIndexes('olt_onu_customer_mappings') as $index) {
            if (($index['unique'] ?? false) !== true) continue;
            if (array_map('strtolower', $index['columns'] ?? []) === $expected) return (string) $index['name'];
        }
        return null;
    }

    public function up(): void
    {
        if (! Schema::hasTable('olt_onu_customer_mappings')) return;
        if ($this->indexNameFor(['olt_device_id', 'pon_port', 'onu_id']) === null) {
            Schema::table('olt_onu_customer_mappings', function (Blueprint $table): void {
                $table->unique(['olt_device_id', 'pon_port', 'onu_id'], 'olt_onu_customer_mappings_olt_pon_onu_unique');
            });
        }
        $legacy = $this->indexNameFor(['olt_device_id', 'onu_id']);
        if ($legacy !== null) {
            Schema::table('olt_onu_customer_mappings', function (Blueprint $table) use ($legacy): void { $table->dropUnique($legacy); });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('olt_onu_customer_mappings')) return;
        $duplicates = DB::table('olt_onu_customer_mappings')->select('olt_device_id', 'onu_id')->whereNotNull('onu_id')->groupBy('olt_device_id', 'onu_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) throw new RuntimeException('Cannot restore OLT+ONU unique index: duplicate ONU IDs exist across PON ports.');
        $pon = $this->indexNameFor(['olt_device_id', 'pon_port', 'onu_id']);
        if ($pon !== null) Schema::table('olt_onu_customer_mappings', function (Blueprint $table) use ($pon): void { $table->dropUnique($pon); });
        if ($this->indexNameFor(['olt_device_id', 'onu_id']) === null) {
            Schema::table('olt_onu_customer_mappings', function (Blueprint $table): void { $table->unique(['olt_device_id', 'onu_id'], 'olt_onu_customer_mappings_olt_device_id_onu_id_unique'); });
        }
    }
};
