<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "mercure", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // The application publishes signals to Reverb over HTTP; a slow
                // or stopped server must never hold a worker for long.
                'timeout' => (float) env('REVERB_CLIENT_TIMEOUT', 5),
                'connect_timeout' => (float) env('REVERB_CLIENT_CONNECT_TIMEOUT', 2),
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'mercure' => [
            'driver' => 'mercure',
            'url' => env('MERCURE_URL'),
            'public_url' => env('MERCURE_PUBLIC_URL'),
            'secret' => env('MERCURE_JWT_SECRET'),
            'encryption_key' => env('MERCURE_ENCRYPTION_KEY'),
            'claims' => [
                'iss' => env('MERCURE_JWT_ISSUER'),
                'client_id' => env('APP_NAME'),
            ],
            'cookie_name' => env('MERCURE_COOKIE_NAME'),
            'subscribe_expiration' => (int) env('MERCURE_SUBSCRIBE_EXPIRATION', 5),
            'async' => (bool) env('MERCURE_ASYNC', false),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Chat Manager real-time signals
    |--------------------------------------------------------------------------
    |
    | Domain events are turned into small invalidation signals (identifiers
    | only) by queued listeners in app/Listeners/Realtime. "queue" and
    | "queue_connection" choose where those listeners run: a dedicated,
    | fast queue keeps signals from waiting behind exports or imports.
    |
    | "client" describes how browsers reach the Reverb server, which behind
    | a reverse proxy usually differs from the address the application uses
    | to publish (REVERB_HOST / REVERB_PORT). Only public values live here:
    | the application key is public by design, the secret never leaves the
    | server.
    |
    */

    'realtime' => [
        'queue' => env('REALTIME_QUEUE'),
        'queue_connection' => env('REALTIME_QUEUE_CONNECTION'),
        'client' => [
            'host' => env('REVERB_CLIENT_HOST', env('REVERB_HOST')),
            'port' => env('REVERB_CLIENT_PORT', env('REVERB_PORT', 443)),
            'scheme' => env('REVERB_CLIENT_SCHEME', env('REVERB_SCHEME', 'https')),
            'path' => env('REVERB_CLIENT_PATH', env('REVERB_SERVER_PATH', '')),
        ],
    ],

];
