<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Channels;

use Illuminate\Notifications\Notification;
use XLink\SmsBridge\Facades\SmsBridge;

class SmsBridgeChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        if (! method_exists($notification, 'toSms')) {
            throw new \RuntimeException('Notification is missing toSms method.');
        }

        $message = $notification->toSms($notifiable);
        $to = $notifiable->routeNotificationFor('sms') ?? $notifiable->phone ?? $notifiable->mobile;

        if (! $to || ! $message) {
            return;
        }

        SmsBridge::to($to)->message($message)->send();
    }
}
