<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Sashalenz\TurboSms\TurboSmsChannel;
use Sashalenz\TurboSms\TurboSmsMessage;
use Illuminate\Notifications\Notification;

/*
 | TurboSmsChannel — REST replacement for the dead SOAP-based
 | laravel-notification-channels/turbosms package. Sandbox + missing
 | credentials short-circuit before any HTTP call. Auth, transport, and
 | per-recipient errors are swallowed (logged as warnings) so a flaky
 | provider can't drown queue workers in retries.
 */

function makeNotifiable(string $phone): object
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

function makeNotification(string $body): Notification
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

function makeChannel(
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

it('sends SMS with bearer token, alpha sender, and digits-only phone', function () {
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 0,
            'response_status' => 'OK',
            'response_result' => [
                ['phone' => '380501234567', 'response_code' => 0, 'response_status' => 'OK'],
            ],
        ]),
    ]);

    makeChannel()->send(makeNotifiable('+380501234567'), makeNotification('hello'));

    Http::assertSent(function (Request $req) {
        return str_ends_with($req->url(), '/message/send.json')
            && $req->hasHeader('Authorization', 'Bearer secret-token')
            && $req->data() === [
                'recipients' => ['380501234567'],
                'sms' => ['sender' => 'A20', 'text' => 'hello'],
            ];
    });
});

it('skips HTTP call in sandbox mode', function () {
    Http::fake();

    makeChannel(sandboxMode: true)->send(makeNotifiable('+380501234567'), makeNotification('hi'));

    Http::assertNothingSent();
});

it('skips HTTP call when api_key is empty', function () {
    Http::fake();

    makeChannel(apiKey: '')->send(makeNotifiable('+380501234567'), makeNotification('hi'));

    Http::assertNothingSent();
});

it('skips HTTP call when sender is empty', function () {
    Http::fake();

    makeChannel(sender: '')->send(makeNotifiable('+380501234567'), makeNotification('hi'));

    Http::assertNothingSent();
});

it('does not throw on auth error (401)', function () {
    Http::fake([
        '*/message/send.json' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    expect(fn () => makeChannel(apiKey: 'bad-token')->send(
        makeNotifiable('+380501234567'),
        makeNotification('hi'),
    ))->not->toThrow(Throwable::class);
});

it('does not throw on transport error', function () {
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    expect(fn () => makeChannel()->send(
        makeNotifiable('+380501234567'),
        makeNotification('hi'),
    ))->not->toThrow(Throwable::class);
});

it('does not throw on envelope error (response_code != 0)', function () {
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 103,
            'response_status' => 'INSUFFICIENT_FUNDS',
        ]),
    ]);

    expect(fn () => makeChannel()->send(
        makeNotifiable('+380501234567'),
        makeNotification('hi'),
    ))->not->toThrow(Throwable::class);
});

it('does not throw on per-recipient error within successful envelope', function () {
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 0,
            'response_status' => 'OK',
            'response_result' => [
                ['phone' => '380501234567', 'response_code' => 404, 'response_status' => 'INVALID_PHONE'],
            ],
        ]),
    ]);

    expect(fn () => makeChannel()->send(
        makeNotifiable('+380501234567'),
        makeNotification('hi'),
    ))->not->toThrow(Throwable::class);
});

it('skips when phone routes to empty string', function () {
    Http::fake();

    makeChannel()->send(makeNotifiable(''), makeNotification('hi'));

    Http::assertNothingSent();
});

it('skips when message body is empty', function () {
    Http::fake();

    makeChannel()->send(makeNotifiable('+380501234567'), makeNotification(''));

    Http::assertNothingSent();
});

it('resolves the channel from the container with config-driven defaults', function () {
    Http::fake([
        '*/message/send.json' => Http::response([
            'response_code' => 0,
            'response_status' => 'OK',
            'response_result' => [['phone' => '380501234567', 'response_code' => 0]],
        ]),
    ]);

    /** @var TurboSmsChannel $channel */
    $channel = app(TurboSmsChannel::class);
    $channel->send(makeNotifiable('+380501234567'), makeNotification('hi'));

    Http::assertSent(fn (Request $req) => $req->hasHeader('Authorization', 'Bearer test-token'));
});
