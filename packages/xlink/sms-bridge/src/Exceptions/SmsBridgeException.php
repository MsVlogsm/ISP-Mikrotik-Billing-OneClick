<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Exceptions;

use Exception;

class SmsBridgeException extends Exception
{
    public static function invalidNumber(string $number): self
    {
        return new self("Invalid Bangladeshi mobile number: {$number}");
    }

    public static function missingRecipient(): self
    {
        return new self('Recipient number is required. Call ->to() before ->send().');
    }

    public static function missingMessage(): self
    {
        return new self('Message content is required. Call ->message() before ->send().');
    }

    public static function missingConfig(string $gateway, string $key): self
    {
        return new self("Please set {$key} for {$gateway} in config/sms_gateways.json.");
    }

    public static function apiError(string $gateway, string $error): self
    {
        return new self("{$gateway} API Error: {$error}");
    }
}
