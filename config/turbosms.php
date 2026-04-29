<?php

declare(strict_types=1);

return [
    /*
     * Bearer API key issued in the TurboSMS dashboard
     * (https://my.turbosms.ua/api).
     */
    'api_key' => env('TURBOSMS_API_KEY'),

    /*
     * Registered alpha-name (sender) — must be approved in your
     * TurboSMS account before messages are accepted.
     */
    'sender' => env('TURBOSMS_SENDER'),

    /*
     * When true the channel short-circuits before any HTTP request.
     * Useful for local/dev environments where you don't want to
     * spend credits or hit the production endpoint.
     */
    'sandbox_mode' => env('TURBOSMS_SANDBOX_MODE', false),

    /*
     * Verbose logging of outgoing requests and successful responses.
     * Errors and warnings are always logged regardless of this flag.
     */
    'debug' => env('TURBOSMS_DEBUG', false),

    'base_url' => env('TURBOSMS_BASE_URL', 'https://api.turbosms.ua'),

    'timeout' => env('TURBOSMS_TIMEOUT', 10),
];
