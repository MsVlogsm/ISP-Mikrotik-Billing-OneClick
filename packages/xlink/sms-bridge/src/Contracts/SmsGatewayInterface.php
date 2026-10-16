<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Contracts;

interface SmsGatewayInterface
{
    /**
     * Set the recipient(s).
     *
     * @param string|array $to
     * @return self
     */
    public function to($to): self;

    /**
     * Set the message content.
     *
     * @param string $message
     * @return self
     */
    public function message(string $message): self;

    /**
     * Send the SMS message using the fluent state or parameters.
     *
     * @param string|array|null $to
     * @param string|null $message
     * @return mixed
     */
    public function send($to = null, ?string $message = null);

    /**
     * Queue the SMS message for background processing.
     *
     * @param string|array|null $to
     * @param string|null $message
     * @return mixed
     */
    public function queue($to = null, ?string $message = null);

    /**
     * Set the gateway credentials dynamically.
     *
     * @param array $credentials
     * @return self
     */
    public function setCredentials(array $credentials): self;
}
