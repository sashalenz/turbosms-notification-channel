# TurboSMS notification channel for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sashalenz/turbosms-notification-channel.svg?style=flat-square)](https://packagist.org/packages/sashalenz/turbosms-notification-channel)
[![Total Downloads](https://img.shields.io/packagist/dt/sashalenz/turbosms-notification-channel.svg?style=flat-square)](https://packagist.org/packages/sashalenz/turbosms-notification-channel)
[![License](https://img.shields.io/packagist/l/sashalenz/turbosms-notification-channel.svg?style=flat-square)](LICENSE)

A Laravel notification channel for the [TurboSMS](https://turbosms.ua) REST API.

This is a drop-in replacement for the abandoned
[`laravel-notification-channels/turbosms`](https://github.com/laravel-notification-channels/turbosms)
package, which still talks to the long-deprecated SOAP endpoint and fails on
modern PHP with `SoapClient::__construct(): 'location' and 'uri' options are
required in nonWSDL mode`.

## Why this package

- Talks to the **current** TurboSMS REST API (`https://api.turbosms.ua`), not
  the dead SOAP endpoint.
- **Won't crash your queue.** Auth, transport, and per-recipient errors are
  logged at `warning` level instead of being thrown — so a flaky provider or a
  missing API key cannot drown your worker in retries.
- **Sandbox mode** that short-circuits before any HTTP call — useful for local
  dev where you don't want to spend credits.
- **Auto-normalises phones** — `+380501234567`, `380501234567`,
  `+38 (050) 123-45-67` all become `380501234567` before hitting the API.

## Installation

```bash
composer require sashalenz/turbosms-notification-channel
```

The service provider is registered automatically via package discovery.

Publish the config (optional — env-driven defaults work out of the box):

```bash
php artisan vendor:publish --tag="turbosms-config"
```

## Configuration

Set the following keys in your `.env`:

```dotenv
TURBOSMS_API_KEY=your-bearer-token-from-the-dashboard
TURBOSMS_SENDER=YourAlpha

# Optional
TURBOSMS_SANDBOX_MODE=false
TURBOSMS_DEBUG=false
TURBOSMS_BASE_URL=https://api.turbosms.ua
TURBOSMS_TIMEOUT=10
```

The Bearer token is issued in the [TurboSMS dashboard](https://my.turbosms.ua/api).

## Usage

### 1. Notification

Add the channel to your notification's `via()` and implement `toTurboSms`:

```php
use Illuminate\Notifications\Notification;
use Sashalenz\TurboSms\TurboSmsChannel;
use Sashalenz\TurboSms\TurboSmsMessage;

class OrderShipped extends Notification
{
    public function via(object $notifiable): array
    {
        return [TurboSmsChannel::class];
    }

    public function toTurboSms(object $notifiable): TurboSmsMessage
    {
        return new TurboSmsMessage("Your order #{$notifiable->id} has shipped.");
    }
}
```

> Method names in PHP are case-insensitive, so legacy notifications declaring
> `toTurboSMS` will keep working without modification.

### 2. Notifiable

Provide the recipient phone via `routeNotificationForTurbosms` (or the
case-insensitive equivalent `routeNotificationForTurboSMS`). Any common
format works — the channel strips non-digit characters before sending:

```php
class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForTurbosms(): string
    {
        return $this->phone; // '+380501234567' → '380501234567' on the wire
    }
}
```

### 3. Send

```php
$user->notify(new OrderShipped($order));
```

## Sandbox mode

Set `TURBOSMS_SANDBOX_MODE=true` to short-circuit every send. No HTTP request
is made; if `TURBOSMS_DEBUG=true` the would-be payload is logged at `info`
level. Useful in local/staging environments.

## Failure semantics

The channel is intentionally non-throwing — every failure path logs a warning
and returns:

| Scenario | Result | Log level |
|---|---|---|
| `sandbox_mode = true` | no-op | `info` (only if `debug = true`) |
| Empty `api_key` or `sender` | no-op | `warning` |
| Empty recipient phone or message body | no-op | none |
| Transport error (timeout, DNS, refused) | no-op | `warning` |
| HTTP 401 / 403 | no-op | `warning` |
| Other non-2xx | no-op | `warning` |
| Envelope `response_code != 0` (e.g. insufficient funds) | no-op | `warning` |
| Per-recipient `response_code != 0` (e.g. invalid phone) | no-op | `warning` |
| Successful send | sent | `info` (only if `debug = true`) |

This matches the design constraint that **a misconfigured SMS provider should
not block delivery, fiscalisation, or any other queued workflow** that happens
to dispatch an SMS as a side-effect.

## Testing

```bash
composer test
```

Tests use `Http::fake()` and Orchestra Testbench — no real HTTP calls are
made.

## License

MIT — see [LICENSE](LICENSE).
