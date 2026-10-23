<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Cloud API
|--------------------------------------------------------------------------
|
| Runtime behavior of the official WhatsApp Cloud API integration. Graph API
| version, base URL and timeout live in config/services.php ("meta");
| credentials are stored encrypted per connection, never here.
|
*/

return [

    /*
    | Outbound transport:
    | - "meta": real WhatsApp Cloud API (production).
    | - "simulated": LOCAL DEVELOPMENT ONLY. Nothing leaves the server; messages
    |   receive "sim." ids and are labeled "Simulación". It refuses to run in
    |   production.
    | - "unavailable": every send fails explicitly (default when not configured).
    */
    'transport' => env('WHATSAPP_TRANSPORT') ?: 'unavailable',

    /*
    | Optional controlled pilot of the real transport: when this list (WhatsApp
    | numbers, digits only, comma separated; a Mexican mobile may be written
    | with or without the legacy "1" after 52) is not empty,
    | the "meta" transport only sends to these recipients; any other message
    | fails explicitly ("transport_unavailable") without calling Meta. Empty
    | means no restriction.
    */
    'pilot' => [
        'recipients' => array_values(array_filter(array_map(
            fn (string $number): string => (string) preg_replace('/\D+/', '', $number),
            explode(',', (string) env('WHATSAPP_PILOT_RECIPIENTS', '')),
        ))),
    ],

    'simulation' => [
        // Simulated transport only: report sent → delivered (→ read) through the
        // same status processing used for Meta webhooks.
        'status_progression' => (bool) env('WHATSAPP_SIMULATE_STATUSES', true),
        'simulate_read' => (bool) env('WHATSAPP_SIMULATE_READ', false),
    ],

    'webhooks' => [
        // Largest accepted webhook body. Meta batches up to ~1000 updates per
        // delivery; 3 MB leaves margin.
        'max_body_bytes' => (int) (env('WHATSAPP_WEBHOOK_MAX_BYTES') ?: 3 * 1024 * 1024),

        // Deliveries accepted per minute and webhook URL (connection).
        'rate_limit_per_minute' => (int) (env('WHATSAPP_WEBHOOK_RATE_LIMIT') ?: 1200),

        // Worker attempts for a stored delivery and the waits between them (seconds).
        'tries' => 5,
        'backoff' => [10, 30, 120, 600],

        // Connection errors and signals older than this (days, by the entry
        // time Meta reports) are replays and are not applied again. Meta
        // retries a failed delivery for up to 7 days.
        'max_age_days' => (int) (env('WHATSAPP_WEBHOOK_MAX_AGE_DAYS') ?: 7),

        // Retention of the raw webhook log (days).
        'retention_days' => (int) (env('WHATSAPP_WEBHOOK_RETENTION_DAYS') ?: 30),
        'failed_retention_days' => (int) (env('WHATSAPP_WEBHOOK_FAILED_RETENTION_DAYS') ?: 90),
    ],

    'delivery' => [
        // Automatic attempts only for errors proven not to have reached Meta
        // (connection refused, DNS, connect timeout) or explicit throttling.
        'max_attempts' => (int) (env('WHATSAPP_DELIVERY_MAX_ATTEMPTS') ?: 5),
        'backoff' => [10, 30, 90, 300],

        // A message still "sending" after this many minutes, without evidence
        // from Meta, is marked failed with code delivery_unknown (never re-sent).
        'reconcile_after_minutes' => (int) (env('WHATSAPP_RECONCILE_AFTER_MINUTES') ?: 15),
    ],

    'templates' => [
        'page_size' => 100,
        'max_pages' => 50,
    ],

    /*
    | Media accepted from and sent to WhatsApp Cloud API. Types and sizes mirror
    | Meta's documented limits; "max_bytes" may be lowered for this server.
    | The real type is always detected from the content (finfo).
    */
    'media' => [
        'download_tries' => 4,
        'download_backoff' => [30, 120, 600],
        'types' => [
            'image' => [
                'mimes' => ['image/jpeg', 'image/png'],
                'max_bytes' => 5 * 1024 * 1024,
            ],
            'sticker' => [
                'mimes' => ['image/webp'],
                'max_bytes' => 500 * 1024,
            ],
            'audio' => [
                'mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus', 'video/ogg'],
                'max_bytes' => 16 * 1024 * 1024,
            ],
            'video' => [
                'mimes' => ['video/mp4', 'video/3gpp'],
                'max_bytes' => 16 * 1024 * 1024,
            ],
            'document' => [
                'mimes' => [
                    'application/pdf',
                    'text/plain',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/zip',
                    'application/vnd.ms-office',
                ],
                // Meta accepts up to 100 MB; this server keeps a lower cap.
                'max_bytes' => (int) (env('WHATSAPP_DOCUMENT_MAX_BYTES') ?: 16 * 1024 * 1024),
            ],
        ],
    ],

];
