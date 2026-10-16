<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class GreenwebGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Greenweb', 'api_key');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://api.greenweb.com.bd';
        $ext = $this->credentials['api_send_url'] ?: 'api.php';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'json' => '',
            'token' => $this->credentials['api_key'], // Unified API key acts as token
            'to' => $this->getRecipientsAsString(),
            'message' => $this->messageBody,
        ];

        $response = $this->httpClient()->acceptJson()->get($url, $payload);

        // Greenweb can return JSON with 'error' key or array with error message
        $data = $response->json() ?? [];
        if (isset($data['error']) || (isset($data[0]) && strpos(strtolower($data[0]), 'error') !== false)) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('Greenweb', $data['error'] ?? $data[0] ?? 'GreenWeb API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
