<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class BanglalinkGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Banglalink', 'api_key');
        }
        if (empty($this->credentials['api_secret'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Banglalink', 'api_secret');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Banglalink', 'sender_id');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://vas.banglalink.net/sendSMS';
        $ext = $this->credentials['api_send_url'] ?: 'sendSMS';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'userID' => $this->credentials['api_key'], // Using unified key as userID
            'passwd' => $this->credentials['api_secret'], // Using unified secret as password
            'sender' => $this->credentials['sender_id'],
            'msisdn' => $this->getRecipientsAsString(),
            'message' => $this->messageBody,
        ];

        $response = $this->httpClient()->asForm()->post($url, $payload)->body();

        $body = $response->body();
        if (strpos($body, 'error') !== false || strpos(strtolower($body), 'failed') !== false) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('Banglalink', $body ?: 'Banglalink API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        ); // Or .json() depending on BL, original code uses asForm + post(/sendSMS)
    }
}
