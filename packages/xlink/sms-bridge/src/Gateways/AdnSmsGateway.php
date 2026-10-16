<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

final class AdnSmsGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('AdnSms', 'api_key');
        }
        if (empty($this->credentials['api_secret'])) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::missingConfig('AdnSms', 'api_secret');
        }
    }

    protected function processSend(): \XLink\SmsBridge\Response\SmsResponse
    {
        $base = ($this->credentials['url'] ?? '') ?: 'https://portal.adnsms.com/api/v1';
        $ext = ($this->credentials['api_send_url'] ?? '') ?: 'secure/send-sms';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');
        $recipients = $this->getRecipientsArray();
        $isBulk = count($recipients) > 1;

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'api_secret' => $this->credentials['api_secret'],
            'request_type' => $isBulk ? 'GENERAL_CAMPAIGN' : 'SINGLE_SMS',
            'message_type' => 'TEXT',
            'mobile' => $this->getRecipientsAsString(),
            'message_body' => $this->messageBody,
        ];

        if ($isBulk) {
            $payload['campaign_title'] = 'Campaign_' . time();
        }

        // Ensure we send api_secret as required by AdnSMS
        $response = $this->httpClient()->post($url, $payload);

        $data = $response->json() ?? [];
        if (($data['api_response_code'] ?? 0) !== 200) {
            throw \XLink\SmsBridge\Exceptions\SmsBridgeException::apiError('AdnSms', $data['api_response_message'] ?? 'ADN SMS API error');
        }

        return new \XLink\SmsBridge\Response\SmsResponse(
            $response->successful(),
            $response->successful() ? 'SMS response received' : 'Failed to send SMS',
            $response->json() ?? []
        );
    }


    public function checkBalance()
    {
        $base = ($this->credentials['url'] ?? '') ?: 'https://portal.adnsms.com/api/v1';
        $ext = ($this->credentials['api_balance_url'] ?? '') ?: 'secure/check-balance';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'api_secret' => $this->credentials['api_secret']
        ];
        $response = $this->httpClient()->post($url, $payload);
        $data = $response->json() ?? [];

        if (($data['api_response_code'] ?? 200) !== 200) {
            throw new \Exception($data['api_response_message'] ?? 'ADN SMS API Error');
        }

        if (isset($data['balance'])) {
            return (string) $data['balance'];
        }

        return null;
    }

    public function checkProfile()
    {
        $base = ($this->credentials['url'] ?? '') ?: 'https://portal.adnsms.com/api/v1';
        $ext = ($this->credentials['api_profile_url'] ?? '') ?: 'secure/profile';
        $url = rtrim($base, '/') . '/' . ltrim($ext, '/');

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'api_secret' => $this->credentials['api_secret']
        ];
        $response = $this->httpClient()->post($url, $payload);
        $data = $response->json() ?? [];

        if (($data['api_response_code'] ?? 200) !== 200) {
            throw new \Exception($data['api_response_message'] ?? 'ADN SMS API Error');
        }

        if (is_array($data)) {
            return $data;
        }

        return null;
    }
}
