<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Response;

final readonly class SmsResponse
{
    public function __construct(
        public bool $successful,
        public string $message,
        public array $data = [],
        public ?string $transactionId = null
    ) {}

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function toArray(): array
    {
        return [
            'success'        => $this->successful,
            'message'        => $this->message,
            'transaction_id' => $this->transactionId,
            'data'           => $this->data,
        ];
    }
}
