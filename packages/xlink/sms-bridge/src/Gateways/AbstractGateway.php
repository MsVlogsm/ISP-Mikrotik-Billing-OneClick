<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Gateways;

use XLink\SmsBridge\Contracts\SmsGatewayInterface;
use XLink\SmsBridge\Exceptions\SmsBridgeException;
use XLink\SmsBridge\Response\SmsResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

abstract class AbstractGateway implements SmsGatewayInterface
{
    /**
     * @var array
     */
    protected array $credentials = [];

    /**
     * @var array
     */
    protected array $recipients = [];

    /**
     * @var string|null
     */
    protected ?string $messageBody = null;

    /**
     * Set the gateway credentials dynamically.
     *
     * @param array $credentials
     * @return self
     */
    public function setCredentials(array $credentials): self
    {
        $defaultCredentials = [
            'url' => '',
            'api_send_url' => '',
            'api_balance_url' => '',
            'api_profile_url' => '',
            'api_key' => '',
            'api_secret' => '',
            'username' => '',
            'password' => '',
            'sender_id' => '',
        ];

        $this->credentials = array_merge($defaultCredentials, $credentials);
        return $this;
    }

    /**
     * Set the recipient(s).
     *
     * @param string|array $to
     * @return self
     */
    public function to($to): self
    {
        if (is_string($to)) {
            $this->recipients = explode(',', $to);
        } elseif (is_array($to)) {
            $this->recipients = $to;
        }

        // Clean up empty values and trim spaces
        $this->recipients = array_filter(array_map('trim', $this->recipients));

        // Normalize BD Phone Numbers
        $this->recipients = array_map([$this, 'normalizePhoneNumber'], $this->recipients);

        return $this;
    }

    /**
     * Normalize BD Phone numbers to 8801XXXXXXXXX format.
     *
     * @param string $number
     * @return string
     */
    protected function normalizePhoneNumber(string $number): string
    {
        // Remove all non-numeric characters except leading '+'
        $number = preg_replace('/[^0-9+]/', '', $number);

        // If it starts with +880, remove the +
        if (strpos($number, '+880') === 0) {
            return ltrim($number, '+');
        }

        // If it starts with 880, it's already in 8801XXXXXXXXX format
        if (strpos($number, '880') === 0) {
            return $number;
        }

        // If it starts with 01, prepend 88
        if (strpos($number, '01') === 0) {
            return '88' . $number;
        }

        // If it starts with 1, it's likely missing 880
        if (strpos($number, '1') === 0 && strlen($number) === 10) {
            return '880' . $number;
        }

        return $number;
    }

    /**
     * Set the message content.
     *
     * @param string $message
     * @return self
     */
    public function message(string $message): self
    {
        $this->messageBody = $message;
        return $this;
    }

    /**
     * Send the SMS message.
     *
     * @param string|array|null $to
     * @param string|null $message
     * @return SmsResponse
     */
    public function send($to = null, ?string $message = null)
    {
        if ($to !== null) {
            $this->to($to);
        }

        if ($message !== null) {
            $this->message($message);
        }

        if (empty($this->recipients)) {
            throw SmsBridgeException::missingRecipient();
        }

        if (empty($this->messageBody)) {
            throw SmsBridgeException::missingMessage();
        }

        $this->validate();

        return $this->processSend();
    }

    /**
     * Queue the SMS message for background processing.
     *
     * @param string|array|null $to
     * @param string|null $message
     * @return SmsResponse
     */
    public function queue($to = null, ?string $message = null)
    {
        if ($to !== null) {
            $this->to($to);
        }

        if ($message !== null) {
            $this->message($message);
        }

        if (empty($this->recipients)) {
            throw SmsBridgeException::missingRecipient();
        }

        if (empty($this->messageBody)) {
            throw SmsBridgeException::missingMessage();
        }

        $this->validate();

        // Dispatch the job
        return \XLink\SmsBridge\Jobs\SendSmsJob::dispatch(
            $this->getRecipientsArray(),
            $this->messageBody
        );
    }

    /**
     * Get a comma separated string of all formatted recipients.
     *
     * @return string
     */
    protected function getRecipientsAsString(): string
    {
        return implode(',', $this->recipients);
    }

    /**
     * Get the recipients as an array
     *
     * @return array
     */
    protected function getRecipientsArray(): array
    {
        return $this->recipients;
    }

    /**
     * Get a pre-configured HTTP client with timeout and retry logic.
     *
     * @return \Illuminate\Http\Client\PendingRequest
     */
    protected function httpClient()
    {
        return Http::timeout(15)
            ->throw() // Throws a RequestException on 4xx or 5xx server errors
            ->retry(3, 200, function ($exception, $request) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                       ($exception instanceof \Illuminate\Http\Client\RequestException && $exception->response->serverError());
            });
    }

    /**
     * Validate the gateway credentials.
     * Override this method in concrete classes to enforce custom validation rules.
     *
     * @return void
     * @throws SmsBridgeException
     */
    protected function validate(): void
    {
        if (empty($this->credentials['api_key']) && empty($this->credentials['api_secret']) && empty($this->credentials['username'])) {
           // Optionally throw an error here, but some drivers don't have basic struct
           // It's safest to validate deeply in the native driver implementations.
        }
    }

    /**
     * Check the current balance of the gateway.
     * Returns a string representation of the balance, or null if unsupported.
     *
     * @return mixed
     */
    public function checkBalance()
    {
        return null;
    }

    /**
     * Check the profile info of the gateway.
     * Returns an array representation of the profile, or null if unsupported.
     *
     * @return mixed
     */
    public function checkProfile()
    {
        return null;
    }

    /**
     * Actually perform the gateway-specific HTTP request.
     *
     * @return SmsResponse
     */
    abstract protected function processSend(): SmsResponse;
}
