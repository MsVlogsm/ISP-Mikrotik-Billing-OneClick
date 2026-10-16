<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class SslGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Ssl', 'api_key');
        }
        if (empty($this->credentials['sender_id'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('Ssl', 'sender_id');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = $this->credentials['url'] ?: 'https://smsplus.sslwireless.com/api/v3';
        $ext = $this->credentials['api_send_url'] ?: 'send-sms';

        $recipients = $this->getRecipientsArray();
        $isBulk = count($recipients) > 1;

        if ($isBulk) {
            // SSL v3 Bulk Endpoint requires /bulk and a JSON array of SMS objects
            $ext = str_replace('send-sms', 'send-sms/bulk', $ext);
            $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

            $smsData = [];
            foreach ($recipients as $index => $number) {
                $smsData[] = [
                    'msisdn' => $number,
                    'text' => $this->messageBody,
                    'csms_id' => uniqid('smsbridge_') . $index,
                ];
            }

            $payload = [
                'api_token' => $this->credentials['api_key'],
                'sid' => $this->credentials['sender_id'],
                'sms' => $smsData,
            ];
        } else {
            // Single SMS Endpoint
            $url = rtrim($base, '/') . '/' . ltrim($ext, '/');
            $payload = [
                'api_token' => $this->credentials['api_key'], // Unified API key acts as Token
                'sid' => $this->credentials['sender_id'],
                'msisdn' => $this->getRecipientsAsString(),
                'sms' => $this->messageBody,
                'csms_id' => uniqid('smsbridge_'),
            ];
        }

        $response = $this->httpClient()->acceptJson()->asJson()->post($url, $payload);

        $data = $response->json() ?? [];
        if (($data['status'] ?? '') !== 'SUCCESS') {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('SSL Wireless', $data['error_message'] ?? $data['status_message'] ?? 'SSL Wireless API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }


    public function checkBalance()
    {
        $base = $this->credentials['url'] ?: 'https://smsplus.sslwireless.com/api/v3';
        $ext = $this->credentials['api_balance_url'] ?: 'balance';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = ['api_token' => $this->credentials['api_key']];
        $response = $this->httpClient()->acceptJson()->asJson()->post($url, $payload);
        $data = $response->json() ?? [];

        if (($data['status'] ?? '') === 'FAILED' || isset($data['error_message'])) {
            throw new \Exception($data['error_message'] ?? 'SSL API Error');
        }

        if (isset($data['balance'])) {
            return (string) $data['balance'];
        }

        return null;
    }
    public function checkProfile()
    {
        $base = $this->credentials['url'] ?: 'https://smsplus.sslwireless.com/api/v3';
        $ext = $this->credentials['api_profile_url'] ?: 'profile';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = ['api_token' => $this->credentials['api_key']];
        $response = $this->httpClient()->acceptJson()->asJson()->post($url, $payload);
        $data = $response->json() ?? [];

        if (($data['status'] ?? '') === 'FAILED' || isset($data['error_message'])) {
            throw new \Exception($data['error_message'] ?? 'SSL API Error');
        }

        if (is_array($data)) {
            return $data;
        }

        return null;
    }
}
