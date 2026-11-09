<?php

namespace App\Observers;

use App\Jobs\SyncOnuMappingsJob;
use App\Models\PPPSecrets;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class PppSecretOnuSyncObserver implements ShouldHandleEventsAfterCommit
{
    public function created(PPPSecrets $ppp): void
    {
        if (strtolower(trim((string) $ppp->service)) === 'pppoe') {
            $this->dispatchSync();
        }
    }

    public function updated(PPPSecrets $ppp): void
    {
        if (strtolower(trim((string) $ppp->service)) !== 'pppoe') {
            return;
        }

        $identityChanged = $ppp->wasChanged([
            'username',
            'caller_id',
            'last_caller_id',
            'ppp_remote_ip',
            'service',
        ]);
        $becameActive = $ppp->wasChanged('status')
            && strtolower(trim((string) $ppp->status)) === 'active';

        if ($identityChanged || $becameActive) {
            $this->dispatchSync();
        }
    }

    private function dispatchSync(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        SyncOnuMappingsJob::dispatch()->afterCommit();
    }
}
