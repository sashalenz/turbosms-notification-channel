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

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): Response
    {
        return $this->http()->post('/message/send.json', $payload);
    }

    /**
     * Poll TurboSMS for delivery status of previously-sent messages by
     * their gateway-issued `message_id`. Up to 100 IDs per call (gateway
     * limit). Response shape mirrors `/message/send.json`:
     *
     *   {
     *     "response_code": 0,
     *     "response_status": "OK",
     *     "response_result": [
     *       {"message_id": "...", "phone": "...", "status": "Delivered"|"NotDelivered"|"Sent"|"Enroute"|...},
     *       ...
     *     ]
     *   }
     *
     * @param  array<int, string>  $messageIds
     */
    public function checkStatus(array $messageIds): Response
    {
        return $this->http()->post('/message/status.json', [
            'messages' => array_values($messageIds),
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
