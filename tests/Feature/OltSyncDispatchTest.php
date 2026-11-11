<?php

namespace Tests\Feature;

use App\Jobs\SyncOnuMappingsJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OltSyncDispatchTest extends TestCase
{
    public function test_olt_sync_endpoint_dispatches_background_job_and_returns_immediately(): void
    {
        Queue::fake();
        $this->withoutMiddleware();

        $response = $this->from('/network-inventory/olt-management')
            ->post('/network-inventory/olt/sync');

        $response->assertRedirect('/network-inventory/olt-management');
        $response->assertSessionHas('onu_sync_success', true);
        $response->assertSessionHas('onu_sync_message', function (string $message): bool {
            return str_contains($message, 'queued') && str_contains($message, 'background');
        });

        Queue::assertPushed(SyncOnuMappingsJob::class, 1);
    }
}
