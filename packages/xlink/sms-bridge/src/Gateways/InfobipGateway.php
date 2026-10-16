<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class InfobipGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['url'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Infobip', 'url');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Infobip', 'sender_id');
        }
        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Infobip', 'api_key');
        }
        if (empty($this->credentials['api_secret'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Infobip', 'api_secret');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        // Infobip explicitly requires the personalized Base URL
        $base = $this->credentials['url']; // Base URL is strictly required for Infobip as it's personalized
        $ext = $this->credentials['api_send_url'] ?: '/sms/2/text/advanced';

        // We do not define $url because HttpClient->baseUrl() uses $base and post() takes $ext

        $payload = [
            'messages' => [
                [
                    'from' => $this->credentials['sender_id'],
                    'destinations' => array_map(
                        function($number) { return ['to' => $number]; },
                        $this->getRecipientsArray()
                    ),
                    'text' => $this->messageBody,
                ],
            ],
        ];

        $response = $this->httpClient()->baseUrl($base)
            ->withBasicAuth($this->credentials['api_key'], $this->credentials['api_secret'])
            ->acceptJson()
            ->post('/' . ltrim($ext, '/'), $payload)
            ;

        $data = $response->json() ?? [];
        if (isset($data['requestError'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('Infobip', json_encode($data['requestError']));
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }
}
