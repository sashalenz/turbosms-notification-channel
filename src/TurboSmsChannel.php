<?php

declare(strict_types=1);

namespace Sashalenz\TurboSms;

use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Notification;

class TurboSmsChannel
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $sender,
        private readonly bool $sandboxMode,
        private readonly bool $debug,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toTurboSms')) {
            return;
        }

        $message = $notification->toTurboSms($notifiable);
        if (is_string($message)) {
            $message = new TurboSmsMessage($message);
        }

        $rawRecipient = $notifiable->routeNotificationFor('turbosms', $notification);
        $recipient = $this->normalizePhone((string) $rawRecipient);

        if ($recipient === '' || $message->body === '') {
            return;
        }

        if ($this->sandboxMode) {
            if ($this->debug) {
                Log::info('TurboSms [sandbox] would send', [
                    'recipient' => $recipient,
                    'sender' => $this->sender,
                    'body' => $message->body,
                ]);
            }

            return;
        }

        if ($this->apiKey === null || $this->apiKey === '' || $this->sender === null || $this->sender === '') {
            Log::warning('TurboSms skipped: api_key or sender not configured', [
                'recipient' => $recipient,
                'notification' => $notification::class,
            ]);

            return;
        }

        $client = new TurboSmsClient($this->apiKey, $this->baseUrl, $this->timeout);

        $sentBody = [
            'recipients' => [$recipient],
            'sms' => [
                'sender' => $this->sender,
                'text' => $message->body,
            ],
        ];

        try {
            $response = $client->send($sentBody);
        } catch (Throwable $exception) {
            Log::warning('TurboSms transport error', [
                'recipient' => $recipient,
                'notification' => $notification::class,
                'message' => $exception->getMessage(),
                'sent_body' => $sentBody,
            ]);

            return;
        }

        $payload = $response->json();

        if ($response->status() === 401 || $response->status() === 403) {
            Log::warning('TurboSms auth error — check TURBOSMS_API_KEY', [
                'status' => $response->status(),
                'body' => $payload,
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('TurboSms HTTP error', [
                'recipient' => $recipient,
                'status' => $response->status(),
                'body' => $payload,
                'sent_body' => $sentBody,
            ]);

            return;
        }

        // Envelope and per-recipient codes are operational signals from the
        // SMS gateway (bad recipient, balance, blacklist, …). They surface to
        // Bugsnag at warning level; `context.title` carries the gateway-side
        // `response_status` so Bugsnag groups errors by reason rather than
        // collapsing every gateway issue into one bucket. `sent_body` carries
        // the exact JSON we POSTed so a future investigation can prove the
        // payload format end-to-end without re-deriving it from the channel.
        $envelopeCode = (int) ($payload['response_code'] ?? -1);
        if (! $this->isSuccessCode($envelopeCode)) {
            $envelopeStatus = $payload['response_status'] ?? null;
            Log::warning('TurboSms envelope error', [
                'title' => 'TurboSms envelope: '.($envelopeStatus ?? 'code '.$envelopeCode),
                'recipient' => $recipient,
                'response_code' => $envelopeCode,
                'response_status' => $envelopeStatus,
                'sent_body' => $sentBody,
            ]);

            return;
        }

        foreach ($payload['response_result'] ?? [] as $result) {
            $code = (int) ($result['response_code'] ?? -1);
            if (! $this->isSuccessCode($code)) {
                $status = $result['response_status'] ?? null;
                Log::warning('TurboSms recipient error', [
                    'title' => 'TurboSms recipient: '.($status ?? 'code '.$code),
                    'phone' => $result['phone'] ?? null,
                    'response_code' => $code,
                    'response_status' => $status,
                ]);
            }
        }

        if ($this->debug) {
            Log::info('TurboSms sent', [
                'recipient' => $recipient,
                'response' => $payload,
            ]);
        }
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function isSuccessCode(int $code): bool
    {
        return $code === 0 || ($code >= 800 && $code < 900);
    }
}
