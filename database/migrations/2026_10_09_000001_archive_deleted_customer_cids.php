<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $references = [
        'billing_infos' => 'customer_bill_unique_id',
        'collection_summaries' => 'customer_collection_unique_id',
        'customer_connection_snapshots' => 'customer_unique_id',
        'customer_wifi_router_logins' => 'customer_unique_id',
        'customers_addresses' => 'customer_address_unique_id',
        'kyc_requests' => 'customer_unique_id',
        'mikrotik_pending_actions' => 'customer_unique_id',
        'network_alarm_events' => 'customer_unique_id',
        'official_infos' => 'customer_office_unique_id',
        'onu_mac_ledgers' => 'customer_unique_id',
        'payment_summaries' => 'customer_payment_unique_id',
        'support_tickets' => 'customer_unique_id',
    ];

    private function archiveKey(int $id): string
    {
        return '__ARCHIVED_CUSTOMER_'.$id;
    }

    public function up(): void
    {
        if (! Schema::hasColumn('customers_infos', 'deleted_original_customer_unique_id')) {
            Schema::table('customers_infos', function (Blueprint $table): void {
                $table->string('deleted_original_customer_unique_id')
                    ->nullable()
                    ->index('customers_infos_deleted_original_cid_index');
            });
        }

        DB::transaction(function (): void {
            DB::table('customers_infos')
                ->whereNotNull('deleted_at')
                ->whereNull('deleted_original_customer_unique_id')
                ->update([
                    'deleted_original_customer_unique_id' => DB::raw('customer_unique_id'),
                ]);

        $deleted = DB::table('customers_infos')
            ->whereNotNull('deleted_at')
            ->whereNotNull('deleted_original_customer_unique_id')
            ->orderBy('id')
            ->get(['id', 'deleted_original_customer_unique_id']);

        $archiveKeys = $deleted->map(fn ($row) => $this->archiveKey((int) $row->id))->all();
        $existingKeys = DB::table('customers_infos')
            ->whereIn('customer_unique_id', $archiveKeys)
            ->get(['id', 'customer_unique_id']);

        foreach ($existingKeys as $existingKey) {
            if ($existingKey->customer_unique_id !== $this->archiveKey((int) $existingKey->id)) {
                throw new \RuntimeException(
                    'CID archival stopped because an internal archive key already exists as a customer CID.'
                );
            }
        }

        // Rename the parent CID first. Any declared ON UPDATE CASCADE foreign
        // keys move with it; the next step handles remaining string references.
        foreach ($deleted as $row) {
            DB::table('customers_infos')
                ->where('id', $row->id)
                ->whereNotNull('deleted_at')
                ->where('customer_unique_id', '<>', $this->archiveKey((int) $row->id))
                ->update(['customer_unique_id' => $this->archiveKey((int) $row->id)]);
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->rewriteReferencesWithJoins(false);
        } else {
            $this->rewriteReferencesOneByOne(false, $deleted);
        }
        });
    }

    private function rewriteReferencesWithJoins(bool $restore): void
    {
        foreach ($this->references as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if ($restore) {
                $keyExpression = "CONCAT('__ARCHIVED_CUSTOMER_', c.id)";
                $setExpression = 'c.deleted_original_customer_unique_id';
            } else {
                $keyExpression = 'c.deleted_original_customer_unique_id';
                $setExpression = "CONCAT('__ARCHIVED_CUSTOMER_', c.id)";
            }

            DB::statement(
                'UPDATE '.$table.' AS r
                 INNER JOIN customers_infos AS c
                    ON r.'.$column.' = '.$keyExpression.'
                 SET r.'.$column.' = '.$setExpression.'
                 WHERE c.deleted_at IS NOT NULL
                   AND c.deleted_original_customer_unique_id IS NOT NULL'
            );
        }

        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            if ($restore) {
                $matchExpression = "CONCAT('__ARCHIVED_CUSTOMER_', c.id)";
                $valueExpression = 'c.deleted_original_customer_unique_id';
            } else {
                $matchExpression = 'c.deleted_original_customer_unique_id';
                $valueExpression = "CONCAT('__ARCHIVED_CUSTOMER_', c.id)";
            }

            DB::statement(
                "UPDATE activity_log AS a
                 INNER JOIN customers_infos AS c
                    ON JSON_UNQUOTE(JSON_EXTRACT(a.properties, '$.customer_unique_id')) = ".$matchExpression."
                 SET a.properties = JSON_SET(a.properties, '$.customer_unique_id', ".$valueExpression.")
                 WHERE c.deleted_at IS NOT NULL
                   AND c.deleted_original_customer_unique_id IS NOT NULL"
            );
        }
    }

    private function rewriteReferencesOneByOne(bool $restore, $deleted): void
    {
        foreach ($deleted as $row) {
            $original = (string) $row->deleted_original_customer_unique_id;
            $archiveKey = $this->archiveKey((int) $row->id);
            $from = $restore ? $archiveKey : $original;
            $to = $restore ? $original : $archiveKey;

            foreach ($this->references as $table => $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    DB::table($table)->where($column, $from)->update([$column => $to]);
                }
            }

            if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
                $logs = DB::table('activity_log')
                    ->where('properties->customer_unique_id', $from)
                    ->get(['id', 'properties']);

                foreach ($logs as $log) {
                    $properties = is_array($log->properties)
                        ? $log->properties
                        : json_decode((string) $log->properties, true);

                    if (! is_array($properties)) {
                        continue;
                    }

                    $properties['customer_unique_id'] = $to;
                    DB::table('activity_log')->where('id', $log->id)->update([
                        'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers_infos', 'deleted_original_customer_unique_id')) {
            return;
        }

        $collision = DB::table('customers_infos as active')
            ->join('customers_infos as archived', 'active.customer_unique_id', '=', 'archived.deleted_original_customer_unique_id')
            ->whereNull('active.deleted_at')
            ->whereNotNull('archived.deleted_at')
            ->exists();

        if ($collision) {
            throw new RuntimeException(
                'Cannot roll back CID archival while a reused CID is assigned to an active customer.'
            );
        }

        $deleted = DB::table('customers_infos')
            ->whereNotNull('deleted_at')
            ->whereNotNull('deleted_original_customer_unique_id')
            ->orderBy('id')
            ->get(['id', 'deleted_original_customer_unique_id']);

        // Restore the parent keys first so any declared ON UPDATE CASCADE
        // foreign keys restore automatically; then restore remaining references.
        foreach ($deleted as $row) {
            DB::table('customers_infos')->where('id', $row->id)->update([
                'customer_unique_id' => $row->deleted_original_customer_unique_id,
            ]);
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->rewriteReferencesWithJoins(true);
        } else {
            $this->rewriteReferencesOneByOne(true, $deleted);
        }

        Schema::table('customers_infos', function (Blueprint $table): void {
            $table->dropIndex('customers_infos_deleted_original_cid_index');
            $table->dropColumn('deleted_original_customer_unique_id');
        });
    }
};
