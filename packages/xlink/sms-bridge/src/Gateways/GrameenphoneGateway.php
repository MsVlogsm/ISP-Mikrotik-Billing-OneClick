<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class GrameenphoneGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Grameenphone', 'api_key');
        }
        if (empty($this->credentials['api_secret'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Grameenphone', 'api_secret');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Grameenphone', 'sender_id');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://gpcmp.grameenphone.com/ecmapigw/webresources';
        $ext = $this->credentials['api_send_url'] ?: 'ecmapigw.v2';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'username' => $this->credentials['api_key'], // Using unified key as username
            'password' => $this->credentials['api_secret'], // Using unified secret as password
            'apicode' => 1,
            'msisdn' => $this->getRecipientsAsString(),
            'countrycode' => '880',
            'cli' => $this->credentials['sender_id'] ?: '2222', // Using sender_id as CLI
            'messagetype' => 1,
            'messageid' => 0,
            'message' => $this->messageBody,
        ];

        $response = $this->httpClient()->acceptJson()->asJson()->post($url, $payload);

        $data = $response->json() ?? [];
        if (($data['status'] ?? 0) !== 200 && ($data['status'] ?? '') !== '200') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('Grameenphone', $data['statusDescription'] ?? 'Grameenphone API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
