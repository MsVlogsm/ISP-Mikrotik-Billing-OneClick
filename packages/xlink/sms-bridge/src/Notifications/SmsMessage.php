<?php

declare(strict_types=1);

namespace XLink\SmsBridge\Notifications;

class SmsMessage
{
    public string $content;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public function content(string $content): self
    {
        $this->content = $content;
        return $this;
    }
}
