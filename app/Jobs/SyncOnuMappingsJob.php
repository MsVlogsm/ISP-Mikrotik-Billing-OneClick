<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AutomationSyncMonitor;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class SyncOnuMappingsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'production-all-olt-onu-mapping-sync';
    }

    public function handle(AutomationSyncMonitor $monitor): void
    {
        $monitor->run(
            'event-driven-onu-mapping-sync',
            'Event-Driven ONU / Customer Sync',
            'After Customer or PPPoE identity changes',
            function (): array {
                Artisan::output();
                $exitCode = Artisan::call('olt:sync-onu-mappings');
                $output = trim(Artisan::output());

                if ($exitCode !== 0) {
                    throw new RuntimeException(
                        'Event-driven ONU mapping sync failed with exit code '.$exitCode.': '.mb_substr($output, 0, 2500)
                    );
                }

                return [
                    'message' => $output !== '' ? $output : 'Event-driven ONU/customer synchronization completed.',
                    'metadata' => [
                        'trigger' => 'customer_or_pppoe_identity_change',
                        'completed_at' => now()->toIso8601String(),
                        'queue' => (string) $this->queue,
                    ],
                ];
            }
        );
    }
}
