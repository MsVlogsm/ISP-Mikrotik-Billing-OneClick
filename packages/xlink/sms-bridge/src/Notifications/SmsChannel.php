<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Notifications;

use Illuminate\Notifications\Notification;
use XLink\SmsBridge\SmsBridgeManager;
use Illuminate\Support\Facades\Log;

class SmsChannel
{
    public function __construct(protected SmsBridgeManager $manager)
    {
    }

    /**
     * Send the given notification.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $to = $notifiable->routeNotificationFor('sms', $notification)) {
            return;
        }

        if (!method_exists($notification, 'toSms')) {
            Log::warning('SmsBridge: Notification ' . get_class($notification) . ' is missing the toSms() method.');
            return;
        }

        /** @var SmsMessage $message */
        $message = $notification->toSms($notifiable);

        $driverName = method_exists($notification, 'smsDriver')
            ? $notification->smsDriver()
            : null;

        try {
            if ($driverName) {
                $driver = $this->manager->driver($driverName);
                $driver->to($to)->message($message->content)->send();
            } else {
                // If no specific driver requested, use the service layer failover logic
                $service = app(\XLink\SmsBridge\Services\SmsBridgeService::class);
                $service->sendSms($to, $message->content);
            }
        } catch (\Exception $e) {
            Log::error('SmsBridge: Channel notification failed - ' . $e->getMessage());
        }
    }
}
