<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class SmsnocGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Smsnoc', 'sender_id');
        }
        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Smsnoc', 'api_key');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = ($this->credentials['url'] ?? '') ?: 'https://app.smsnoc.com/api/v3';
        $ext = ($this->credentials['api_send_url'] ?? '') ?: 'sms/send';

        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'recipient' => $this->getRecipientsAsString(),
            'sender_id' => $this->credentials['sender_id'],
            'type' => 'plain',
            'message' => $this->messageBody,
        ];

        $response = $this->httpClient()->withToken($this->credentials['api_key']) // Unified API key acts as Token
            ->acceptJson()
            ->post($url, $payload)
            ;

        $data = $response->json() ?? [];
        if (($data['status'] ?? '') === 'error') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('Smsnoc', $data['message'] ?? 'Smsnoc API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }


    public function checkBalance()
    {
        $base = ($this->credentials['url'] ?? '') ?: 'https://app.smsnoc.com/api/v3';
        $ext = ($this->credentials['api_balance_url'] ?? '') ?: 'balance';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $response = $this->httpClient()->withToken($this->credentials['api_key'])->acceptJson()->get($url);
        $data = $response->json() ?? [];

        if (($data['status'] ?? '') === 'error') {
            throw new \Exception($data['message'] ?? 'SMSNOC API Error');
        }

        if (($data['status'] ?? '') === 'success') {
            return $data;
        }

        return null;
    }

    public function checkProfile()
    {
        $base = ($this->credentials['url'] ?? '') ?: 'https://app.smsnoc.com/api/v3';
        $ext = ($this->credentials['api_profile_url'] ?? '') ?: 'me';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $response = $this->httpClient()->withToken($this->credentials['api_key'])->acceptJson()->get($url);
        $data = $response->json() ?? [];

        if (($data['status'] ?? '') === 'error') {
            throw new \Exception($data['message'] ?? 'SMSNOC API Error');
        }

        if (is_array($data)) {
            return $data;
        }

        return null;
    }
}
