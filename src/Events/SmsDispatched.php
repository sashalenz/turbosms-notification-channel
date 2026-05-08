<?php

declare(strict_types=1);

namespace Sashalenz\TurboSms\Events;

use Illuminate\Notifications\Notification;

/**
 * Fired by `TurboSmsChannel` after every meaningful send attempt:
 *
 *   • status='sent'      — gateway accepted the request (envelope_code
 *                          and the first per-recipient response_code are
 *                          both success codes).
 *   • status='failed'    — transport, auth, HTTP, envelope, per-recipient,
 *                          or "missing credentials" error.
 *   • status='sandbox'   — short-circuited because TURBOSMS_SANDBOX_MODE
 *                          is on. No HTTP request was made.
 *
 * The event is NOT fired when the channel skips because of an empty phone
 * or empty body — there was no attempt to record.
 *
 * Consumers (host application listeners) typically persist a row to their
 * own SMS-history table and later poll TurboSMS for delivery status using
 * `TurboSmsClient::checkStatus()`.
 */
final class SmsDispatched
{
    /**
     * @param  array<string,mixed>|null  $requestPayload  exact body sent to /message/send.json
     * @param  array<string,mixed>|null  $responsePayload  decoded gateway response (null on transport error)
     */
    public function __construct(
        public readonly object $notifiable,
        public readonly Notification $notification,
        public readonly string $phone,
        public readonly string $text,
        public readonly string $status,
        public readonly ?array $requestPayload = null,
        public readonly ?array $responsePayload = null,
        public readonly ?int $envelopeCode = null,
        public readonly ?int $recipientCode = null,
        public readonly ?string $gatewayMessageId = null,
        public readonly ?string $errorReason = null,
    ) {}
}
