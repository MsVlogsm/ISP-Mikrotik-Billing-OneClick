<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class EsmsGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Esms', 'sender_id');
        }
        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Esms', 'api_key');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://login.esms.com.bd/api/v3';
        $ext = $this->credentials['api_send_url'] ?: 'sms/send';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'recipient' => $this->getRecipientsAsString(),
            'sender_id' => $this->credentials['sender_id'],
            'type' => 'plain',
            'message' => $this->messageBody,
        ];

        $response = $this->httpClient()->withToken($this->credentials['api_key']) // ESMS uses token auth
            ->acceptJson()
            ->post($url, $payload)
            ;

        $data = $response->json() ?? [];
        if (($data['status'] ?? '') !== 'SUCCESS') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('ESMS', $data['message'] ?? 'ESMS API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
