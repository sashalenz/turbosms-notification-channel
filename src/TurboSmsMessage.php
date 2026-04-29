<?php

declare(strict_types=1);

namespace Sashalenz\TurboSms;

class TurboSmsMessage
{
    public function __construct(
        public readonly string $body,
    ) {}
}
