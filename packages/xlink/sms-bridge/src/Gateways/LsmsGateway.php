<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Exceptions\SmsBridgeException;
use XLink\SmsBridge\Response\SmsResponse;

final class LsmsGateway extends AbstractGateway
{
    protected function validate(): void
    {
        parent::validate();

        if (empty($this->credentials['api_key'])) {
            throw SmsBridgeException::missingConfig('LSMS', 'api_key');
        }

        if (empty($this->credentials['sender_id'])) {
            throw SmsBridgeException::missingConfig('LSMS', 'sender_id');
        }
    }

    protected function processSend(): SmsResponse
    {
        $base = rtrim($this->credentials['url'] ?: 'https://sms.lsms.bd/api/v1', '/');
        $ext = trim($this->credentials['api_send_url'] ?: 'smsapi', '/');
        $url = str_ends_with($base, '/' . $ext) ? $base : $base . '/' . $ext;

        $payload = [
            'api_key' => $this->credentials['api_key'],
            'senderid' => $this->credentials['sender_id'],
            'numbers' => $this->getRecipientsAsString(),
            'msg' => $this->messageBody,
            'type' => preg_match('/[^\x00-\x7F]/', $this->messageBody) ? 'unicode' : 'text',
        ];

        $response = $this->httpClient()
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        $data = $response->json();
        if (!is_array($data)) {
            throw SmsBridgeException::apiError('LSMS', 'Invalid JSON response; submission status is unknown.');
        }

        $hasError = filter_var($data['error'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$response->successful() || $hasError || !array_key_exists('error', $data)) {
            $message = $data['error_message']
                ?? $data['message']
                ?? ('LSMS returned HTTP ' . $response->status());

            throw SmsBridgeException::apiError('LSMS', (string) $message);
        }

        return new SmsResponse(
            true,
            $data['message'] ?? 'SMS submitted to LSMS',
            $data,
            isset($data['message_id']) ? (string) $data['message_id'] : null
        );
    }
}
