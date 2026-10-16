<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class MimsmsGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Mimsms', 'api_key');
        }
        if (empty($this->credentials['api_secret'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Mimsms', 'api_secret');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Mimsms', 'sender_id');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://api.mimsms.com/api';
        $ext = $this->credentials['api_send_url'] ?: 'SmsSending/Send';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'UserName' => $this->credentials['api_key'], // Unified API key acts as UserName
            'ApiKey' => $this->credentials['api_secret'], // Unified API secret acts as ApiKey
            'SenderName' => $this->credentials['sender_id'],
            'TransactionType' => 'T',
            'CampaignId' => 'null',
            'MobileNumber' => $this->getRecipientsAsString(),
            'Message' => $this->messageBody,
        ];

        $response = $this->httpClient()->asJson()->post($url, $payload);

        $data = $response->json() ?? [];
        if (($data['responseCode'] ?? 0) !== 200 && ($data['responseCode'] ?? '') !== '200') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('MimSms', $data['responseResult'] ?? 'MimSms API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
