<?php

declare(strict_types=1);

namespace Sashalenz\TurboSms;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;

class TurboSmsClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public function send(string $sender, string $recipient, string $text): Response
    {
        return $this->http()->post('/message/send.json', [
            'recipients' => [$recipient],
            'sms' => [
                'sender' => $sender,
                'text' => $text,
            ],
        ]);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson();
    }
}
