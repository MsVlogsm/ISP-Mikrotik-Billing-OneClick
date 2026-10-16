<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \XLink\SmsBridge\Contracts\SmsGatewayInterface to(string|array $to)
 * @method static \XLink\SmsBridge\Contracts\SmsGatewayInterface message(string $message)
 * @method static mixed send(string|array|null $to = null, string|null $message = null)
 * @method static mixed queue(string|array|null $to = null, string|null $message = null)
 *
 * @see \XLink\SmsBridge\SmsBridgeManager
 */
class SmsBridge extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'sms-bridge';
    }
}
