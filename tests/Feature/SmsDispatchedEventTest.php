<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Sashalenz\TurboSms\Events\SmsDispatched;
use Sashalenz\TurboSms\TurboSmsChannel;
use Sashalenz\TurboSms\TurboSmsMessage;
use Illuminate\Notifications\Notification;

/*
 | Asserts that TurboSmsChannel dispatches an `SmsDispatched` event on
 | every meaningful exit path so host applications can persist SMS
 | delivery history. The channel itself stays stateless — it logs the
 | operational signals and emits the event; persistence is the host's
 | responsibility via a listener.
 |
 | Empty-phone / empty-body short-circuits explicitly do NOT emit (no
 | attempt = nothing to record).
 */

function makeNotifiableForEvent(string $phone): object
{
    return new class($phone)
    {
        public function __construct(public string $phone) {}

        public function routeNotificationFor(string $channel, ?Notification $notification = null): string
        {
            return $this->phone;
        }
    };
}

function makeNotificationForEvent(string $body): Notification
{
    return new class($body) extends Notification
    {
        public function __construct(public string $body) {}

        public function toTurboSms($notifiable): TurboSmsMessage
        {
            return new TurboSmsMessage($this->body);
        }
    };
}

function makeChannelForEvent(
    string $apiKey = 'secret-token',
    string $sender = 'A20',
    bool $sandboxMode = false,
    bool $debug = false,
): TurboSmsChannel {
    return new TurboSmsChannel(
        apiKey: $apiKey,
        sender: $sender,
        sandboxMode: $sandboxMode,
        debug: $debug,
        baseUrl: 'https://api.turbosms.ua',
        timeout: 10,
    );
}

it('emits SmsDispatched(status=sent) with message_id on full success', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 0,
            'response_status' => 'OK',
            'response_result' => [
                ['phone' => '380501234567', 'response_code' => 0, 'response_status' => 'OK', 'message_id' => 'msg-uuid-1'],
            ],
        ]),
    ]);

    makeChannelForEvent()->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hello'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_SENT
            && $event->phone === '380501234567'
            && $event->text === 'hello'
            && $event->envelopeCode === 0
            && $event->recipientCode === 0
            && $event->gatewayMessageId === 'msg-uuid-1'
            && $event->errorReason === null
            && $event->requestPayload === [
                'recipients' => ['380501234567'],
                'sms' => ['sender' => 'A20', 'text' => 'hello'],
            ]
            && is_array($event->responsePayload);
    });
});

it('emits status=sandbox without making any HTTP call', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake();

    makeChannelForEvent(sandboxMode: true)->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Http::assertNothingSent();
    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_SANDBOX
            && $event->phone === '380501234567'
            && $event->responsePayload === null;
    });
});

it('emits status=failed when api_key missing', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake();

    makeChannelForEvent(apiKey: '')->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_FAILED
            && $event->errorReason === 'api_key or sender not configured';
    });
});

it('emits status=failed on transport error', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    makeChannelForEvent()->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_FAILED
            && str_starts_with((string) $event->errorReason, 'transport: ')
            && $event->responsePayload === null;
    });
});

it('emits status=failed on auth error 401', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake([
        '*/message/send.json' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    makeChannelForEvent(apiKey: 'bad-token')->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_FAILED
            && $event->errorReason === 'auth: HTTP 401';
    });
});

it('emits status=failed on HTTP 5xx', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake([
        '*/message/send.json' => Http::response(['error' => 'gateway timeout'], 504),
    ]);

    makeChannelForEvent()->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_FAILED
            && $event->errorReason === 'http: 504';
    });
});

it('emits status=failed on envelope error with code', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 103,
            'response_status' => 'INSUFFICIENT_FUNDS',
        ]),
    ]);

    makeChannelForEvent()->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_FAILED
            && $event->envelopeCode === 103
            && $event->errorReason === 'envelope: INSUFFICIENT_FUNDS';
    });
});

it('emits status=failed on per-recipient error within OK envelope', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 0,
            'response_status' => 'OK',
            'response_result' => [
                ['phone' => '380501234567', 'response_code' => 404, 'response_status' => 'INVALID_PHONE'],
            ],
        ]),
    ]);

    makeChannelForEvent()->send(
        makeNotifiableForEvent('+380501234567'),
        makeNotificationForEvent('hi'),
    );

    Event::assertDispatched(SmsDispatched::class, function (SmsDispatched $event) {
        return $event->status === TurboSmsChannel::STATUS_FAILED
            && $event->envelopeCode === 0
            && $event->recipientCode === 404
            && $event->gatewayMessageId === null
            && $event->errorReason === 'recipient: INVALID_PHONE';
    });
});

it('does NOT emit when phone is empty', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake();

    makeChannelForEvent()->send(makeNotifiableForEvent(''), makeNotificationForEvent('hi'));

    Event::assertNotDispatched(SmsDispatched::class);
});

it('does NOT emit when body is empty', function () {
    Event::fake([SmsDispatched::class]);
    Http::fake();

    makeChannelForEvent()->send(makeNotifiableForEvent('+380501234567'), makeNotificationForEvent(''));

    Event::assertNotDispatched(SmsDispatched::class);
});
