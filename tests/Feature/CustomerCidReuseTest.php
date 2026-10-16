<?php

namespace Tests\Feature;

use App\Models\CustomersInfo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerCidReuseTest extends TestCase
{
    use RefreshDatabase;

    public function test_cid_can_be_reused_after_deleting_a_customer_without_cross_linking_history(): void
    {
        $oldCustomer = CustomersInfo::create([
            'customer_unique_id' => 'BT0029',
            'customer_name' => 'Old Customer',
            'status' => 'active',
        ]);

        DB::table('customer_connection_snapshots')->insert([
            'customer_unique_id' => 'BT0029',
            'state' => 'online',
            'sampled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldId = $oldCustomer->id;
        $oldCustomer->delete();

        $archiveKey = '__ARCHIVED_CUSTOMER_'.$oldId;

        $this->assertDatabaseHas('customers_infos', [
            'id' => $oldId,
            'customer_unique_id' => $archiveKey,
            'deleted_original_customer_unique_id' => 'BT0029',
        ]);
        $this->assertDatabaseHas('customer_connection_snapshots', [
            'customer_unique_id' => $archiveKey,
        ]);

        $newCustomer = CustomersInfo::create([
            'customer_unique_id' => 'BT0029',
            'customer_name' => 'Replacement Customer',
            'status' => 'active',
        ]);

        $this->assertSame('BT0029', $newCustomer->customer_unique_id);
        $this->assertDatabaseHas('customers_infos', [
            'id' => $newCustomer->id,
            'customer_unique_id' => 'BT0029',
            'deleted_at' => null,
        ]);
        $this->assertSame(0, DB::table('customer_connection_snapshots')
            ->where('customer_unique_id', 'BT0029')->count());
        $this->assertSame(1, DB::table('customer_connection_snapshots')
            ->where('customer_unique_id', $archiveKey)->count());

        $archivedCustomer = CustomersInfo::withTrashed()->findOrFail($oldId);
        $this->assertSame('BT0029', $archivedCustomer->displayCustomerUniqueId());
    }

    public function test_two_live_customers_cannot_share_the_same_cid(): void
    {
        CustomersInfo::create([
            'customer_unique_id' => 'BT0029',
            'customer_name' => 'First Customer',
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        CustomersInfo::create([
            'customer_unique_id' => 'BT0029',
            'customer_name' => 'Second Customer',
            'status' => 'active',
        ]);
    }
}
