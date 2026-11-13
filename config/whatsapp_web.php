<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Web (QR)
    |--------------------------------------------------------------------------
    |
    | A phone with the regular WhatsApp app is linked by QR
    | ("Dispositivos vinculados") and a private Node service
    | (services/whatsapp-web) keeps the WhatsApp Web session. It never
    | replaces WhatsApp Cloud API: each connection chooses its modality with
    | connections.provider. Contract: docs/whatsapp-web-qr-contrato.md.
    |
    | "enabled" only shows the modality in the interface; without "secret"
    | both sides refuse every internal request, so nothing can be linked.
    |
    */

    'enabled' => (bool) env('WHATSAPP_WEB_ENABLED', false),

    // Where the Node service listens (loopback only; never published).
    'url' => rtrim((string) (env('WHATSAPP_WEB_URL') ?: 'http://127.0.0.1:3410'), '/'),

    // Shared secret of the internal HMAC signature (at least 32 characters);
    // the same value as WAWEB_SECRET in the service's environment.
    'secret' => (string) env('WHATSAPP_WEB_SECRET', ''),

    // How the service reaches this application, for the files it downloads
    // to send (the same value as WAWEB_LARAVEL_URL); APP_URL when empty.
    'callback_url' => rtrim((string) env('WHATSAPP_WEB_CALLBACK_URL', ''), '/'),

    // Optional hardening: addresses the service's own requests may come from
    // (events and file downloads), e.g. "127.0.0.1,::1" when it calls this
    // application through the loopback. The real remote address is compared,
    // never a forwarded header. Empty accepts any address: the HMAC
    // signature is always required anyway.
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_WEB_ALLOWED_IPS', ''))))),

    // Seconds Laravel waits for the service (connection and answer).
    'timeout' => max(1, (int) (env('WHATSAPP_WEB_TIMEOUT') ?: 15)),
    'connect_timeout' => 3,

    'signature' => [
        // Largest clock difference accepted, and how long a nonce is remembered.
        'tolerance_seconds' => 60,
        'nonce_ttl_seconds' => 300,
    ],

    'events' => [
        // Largest event body accepted (a text message with its descriptor is a few KB).
        'max_body_bytes' => (int) (env('WHATSAPP_WEB_EVENT_MAX_BYTES') ?: 512 * 1024),
        // Events per minute accepted from the service (all sessions together).
        'rate_limit_per_minute' => (int) (env('WHATSAPP_WEB_EVENT_RATE_LIMIT') ?: 1800),
        // How long a processed event id is remembered to discard repeats.
        'dedup_hours' => 48,
    ],

    'media' => [
        // Seconds the download of one inbound file may take.
        'download_timeout' => 110,
        // Seconds a signed URL of an outbound file stays valid for the service.
        'outbound_url_seconds' => 60,

        // What a file received through this modality may be, per kind of
        // message. The type is always the one detected in the content
        // (finfo), never the one WhatsApp declared. Wider than the Cloud API
        // table (config/whatsapp.php): a person's WhatsApp sends photos and
        // videos "as a document", longer videos and audio in other
        // containers. Nothing executable or interpretable by a browser
        // (HTML, SVG, scripts) is accepted; files are served from the
        // private disk, never from a public path.
        'types' => [
            'image' => [
                'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                'max_bytes' => (int) (env('WHATSAPP_WEB_IMAGE_MAX_BYTES') ?: 16 * 1024 * 1024),
            ],
            'sticker' => [
                'mimes' => ['image/webp'],
                'max_bytes' => 5 * 1024 * 1024,
            ],
            'audio' => [
                'mimes' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/webm', 'audio/3gpp', 'video/ogg', 'video/mp4', 'video/3gpp', 'video/webm'],
                'max_bytes' => (int) (env('WHATSAPP_WEB_AUDIO_MAX_BYTES') ?: 32 * 1024 * 1024),
            ],
            'video' => [
                'mimes' => ['video/mp4', 'video/3gpp', 'video/quicktime', 'video/webm'],
                'max_bytes' => (int) (env('WHATSAPP_WEB_VIDEO_MAX_BYTES') ?: 100 * 1024 * 1024),
            ],
            'document' => [
                'mimes' => [
                    'application/pdf', 'text/plain', 'text/csv', 'text/rtf', 'application/rtf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.ms-office',
                    'application/zip', 'application/x-rar', 'application/x-7z-compressed',
                    // Photos, videos and audio sent "as a document".
                    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif',
                    'video/mp4', 'video/3gpp', 'video/quicktime', 'video/webm',
                    'audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/opus', 'audio/wav',
                ],
                'max_bytes' => (int) (env('WHATSAPP_WEB_DOCUMENT_MAX_BYTES') ?: 100 * 1024 * 1024),
            ],
        ],
    ],

    'history' => [
        // Store the chat history the service hands over when a number is
        // linked (event history.batch). The service decides whether it asks
        // WhatsApp for it (WAWEB_SYNC_HISTORY); off here, what it sends is
        // discarded.
        'enabled' => (bool) env('WHATSAPP_WEB_HISTORY', true),
        // Files of imported messages are only fetched when the message is at
        // most this many days old: WhatsApp stops serving older files, and
        // the message is kept with the attachment marked "historical_missing".
        'media_days' => max(0, (int) (env('WHATSAPP_WEB_HISTORY_MEDIA_DAYS') ?? 30)),
        // Messages per queued import job.
        'job_size' => 100,
    ],

    // Seconds the answer of the service's status is reused by the pages.
    'status_cache_seconds' => 10,

];
