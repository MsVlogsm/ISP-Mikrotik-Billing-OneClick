<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use XLink\SmsBridge\Facades\SmsBridge;

class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var string|array
     */
    public $to;

    /**
     * @var string
     */
    public $message;

    /**
     * Create a new job instance.
     *
     * @param string|array $to
     * @param string $message
     * @return void
     */
    public function __construct($to, string $message)
    {
        $this->to = $to;
        $this->message = $message;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        SmsBridge::to($this->to)
            ->message($this->message)
            ->send();
    }
}
