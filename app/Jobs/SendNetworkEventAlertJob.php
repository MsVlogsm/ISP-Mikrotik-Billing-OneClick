<?php

namespace App\Jobs;

use App\Models\NetworkEvent;
use App\Services\NetworkAlertNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendNetworkEventAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 20;

    public function __construct(
        public int $eventId,
        public bool $force = false,
        public ?string $channel = null,
    ) {}

    public function handle(NetworkAlertNotificationService $service): void
    {
        $event = NetworkEvent::find($this->eventId);
        if ($event) {
            $service->send($event, $this->force, $this->channel);
        }
    }
}
