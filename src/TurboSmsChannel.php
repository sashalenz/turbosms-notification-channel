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

        try {
            $response = $client->send($this->sender, $recipient, $message->body);
        } catch (Throwable $exception) {
            Log::warning('TurboSms transport error', [
                'recipient' => $recipient,
                'notification' => $notification::class,
                'message' => $exception->getMessage(),
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
            ]);

            return;
        }

        // Envelope and per-recipient codes are operational signals from the
        // SMS gateway (bad recipient, balance, blacklist, …) — not faults in
        // this channel. Log at notice so Laravel keeps the trail but
        // Bugsnag's PSR logger (default threshold = warning) ignores them.
        // Transport / auth / HTTP errors above stay as warning because those
        // do indicate something worth paging on.
        $envelopeCode = (int) ($payload['response_code'] ?? -1);
        if (! $this->isSuccessCode($envelopeCode)) {
            Log::notice('TurboSms envelope error', [
                'recipient' => $recipient,
                'response_code' => $envelopeCode,
                'response_status' => $payload['response_status'] ?? null,
            ]);

            return;
        }

        foreach ($payload['response_result'] ?? [] as $result) {
            $code = (int) ($result['response_code'] ?? -1);
            if (! $this->isSuccessCode($code)) {
                Log::notice('TurboSms recipient error', [
                    'phone' => $result['phone'] ?? null,
                    'response_code' => $code,
                    'response_status' => $result['response_status'] ?? null,
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
