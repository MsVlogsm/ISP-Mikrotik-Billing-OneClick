<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class ElitBuzzGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('ElitBuzz', 'api_key');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('ElitBuzz', 'sender_id');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        // Fallback for missing url
        $base = $this->credentials['url'] ?: 'https://msg.elitbuzz-bd.com';
        $ext = $this->credentials['api_send_url'] ?: 'smsapi';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'senderid' => $this->credentials['sender_id'],
            'type' => 'text',
            'contacts' => $this->getRecipientsAsString(),
            'msg' => $this->messageBody,
        ];

        $response = $this->httpClient()->post($url, $payload);

        if (trim($response->body()) !== 'OK') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('ElitBuzz', $response->body() ?: 'ElitBuzz API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
