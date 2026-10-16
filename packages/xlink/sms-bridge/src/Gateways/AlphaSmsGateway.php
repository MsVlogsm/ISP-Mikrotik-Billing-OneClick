<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class AlphaSmsGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('AlphaSms', 'api_key');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        // Fallback for missing url
        $base = $this->credentials['url'] ?: 'https://api.sms.net.bd';
        $ext = $this->credentials['api_send_url'] ?: 'sendsms';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'msg' => $this->messageBody,
            'to' => $this->getRecipientsAsString(),
        ];

        $response = $this->httpClient()->post($url, $payload);

        $data = $response->json() ?? [];
        if (($data['error'] ?? 0) !== 0) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('AlphaSms', $data['msg'] ?? 'Alpha SMS API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
