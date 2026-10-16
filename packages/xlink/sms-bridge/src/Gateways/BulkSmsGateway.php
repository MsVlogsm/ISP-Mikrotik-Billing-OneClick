<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class BulkSmsGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('BulkSms', 'api_key');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('BulkSms', 'sender_id');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://bulksmsbd.net/api';
        $ext = $this->credentials['api_send_url'] ?: 'smsapi';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'senderid' => $this->credentials['sender_id'],
            'type' => 'text',
            'number' => $this->getRecipientsAsString(),
            'message' => $this->messageBody,
        ];

        $response = $this->httpClient()->get($url, $payload);

        $data = $response->json() ?? [];
        if (isset($data['error_message'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('BulkSms', $data['error_message']);
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
