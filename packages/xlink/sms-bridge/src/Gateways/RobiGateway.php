<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class RobiGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Robi', 'api_key');
        }
        if (empty($this->credentials['api_secret'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Robi', 'api_secret');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://bmpws.robi.com.bd/ApacheGearWS';
        $ext = $this->credentials['api_send_url'] ?: 'SendTextMessage';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'username' => $this->credentials['api_key'], // Unified API key acts as username
            'password' => $this->credentials['api_secret'], // Unified API secret acts as password
            'To' => $this->getRecipientsAsString(),
            'Message' => $this->messageBody,
        ];

        $response = $this->httpClient()->asForm()->post($url, $payload)->body();

        if (trim($response->body()) !== 'SUCCESS') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('Robi', $response->body() ?: 'Robi API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        ); // Returns text response
    }
}
