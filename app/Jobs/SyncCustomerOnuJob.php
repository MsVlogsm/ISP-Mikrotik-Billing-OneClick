<?php

namespace App\Jobs;

use App\Models\CustomersInfo;
use App\Services\Olt\CustomerOnuRealtimeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SyncCustomerOnuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;
    public bool $failOnTimeout = true;

    public function __construct(
        public int $customerId,
        public int $requestedBy,
    ) {
        $this->onQueue('default');
    }

    public function handle(CustomerOnuRealtimeSyncService $syncService): void
    {
        $statusKey = $this->statusKey();
        $lock = Cache::lock('customer-onu-realtime-sync:'.$this->customerId, 210);

        if (! $lock->get()) {
            Cache::put($statusKey, ['state' => 'failed', 'message' => 'এই customer-এর ONU sync ইতিমধ্যে চলছে।'], now()->addMinutes(10));
            return;
        }

        Cache::put($statusKey, ['state' => 'running'], now()->addMinutes(10));

        try {
            $customer = CustomersInfo::query()->with('pppUser')->findOrFail($this->customerId);
            $result = $syncService->sync($customer);
            $signal = $result['rx_power'] !== null
                ? 'RX '.number_format((float) $result['rx_power'], 2).' dBm'
                : 'এই poll-এ নতুন RX reading পাওয়া যায়নি';
            $session = $result['session_online'] ? 'Live PPPoE session' : 'Last-known PPPoE MAC';
            $message = "{$customer->customer_unique_id}: {$result['olt_name']} · PON {$result['pon']} / ONU {$result['onu_id']} · {$signal} · {$session} যাচাই হয়েছে।";

            if (! $result['opm_fresh']) {
                $reason = ($result['opm_status'] ?? null) === 'olt_reports_offline'
                    ? 'OLT ONU-টিকে Offline দেখাচ্ছে; optical reading অনুপস্থিত।'
                    : 'OLT এই ONU-এর জন্য optical reading দেয়নি; আগের reading অপরিবর্তিত আছে।';
                $message .= ' '.$reason;
            }

            Cache::put($statusKey, [
                'state' => 'completed',
                'fresh' => (bool) $result['opm_fresh'],
                'message' => $message,
            ], now()->addMinutes(10));
        } catch (\Throwable $exception) {
            Log::warning('Queued customer ONU sync failed', [
                'customer_id' => $this->customerId,
                'requested_by' => $this->requestedBy,
                'error' => $exception->getMessage(),
            ]);
            Cache::put($statusKey, ['state' => 'failed', 'message' => $exception->getMessage()], now()->addMinutes(10));
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::put($this->statusKey(), [
            'state' => 'failed',
            'message' => $exception?->getMessage() ?: 'ONU sync queue job ব্যর্থ হয়েছে।',
        ], now()->addMinutes(10));
    }

    private function statusKey(): string
    {
        return 'customer-onu-realtime-sync:status:'.$this->requestedBy.':'.$this->customerId;
    }
}
