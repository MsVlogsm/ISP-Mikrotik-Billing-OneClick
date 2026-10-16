<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use Illuminate\Support\Facades\Log;

final class LogGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $payload = [
            'to' => $this->getRecipientsAsString(),
            'message' => $this->messageBody,
        ];

        Log::info('SmsBridge Log Driver:', $payload);

        return new \XLink\SmsBridge\Response\SmsResponse(
            true,
            'SMS logged successfully',
            ['data' => $payload]
        );
    }
}
