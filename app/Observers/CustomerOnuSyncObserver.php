<?php

namespace App\Observers;

use App\Jobs\SyncOnuMappingsJob;
use App\Models\CustomersInfo;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class CustomerOnuSyncObserver implements ShouldHandleEventsAfterCommit
{
    public function created(CustomersInfo $customer): void
    {
        $this->dispatchSync();
    }

    public function updated(CustomersInfo $customer): void
    {
        if ($customer->wasChanged([
            'ppp_user_id',
            'status',
            'customer_unique_id',
        ])) {
            $this->dispatchSync();
        }
    }

    public function restored(CustomersInfo $customer): void
    {
        $this->dispatchSync();
    }

    private function dispatchSync(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        SyncOnuMappingsJob::dispatch()->afterCommit();
    }
}
