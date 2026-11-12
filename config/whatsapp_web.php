<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Web (QR)
    |--------------------------------------------------------------------------
    |
    | Unofficial modality: a phone with the regular WhatsApp app is linked by
    | QR ("Dispositivos vinculados") and a private Node service
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
    ],

    // Seconds the answer of the service's status is reused by the pages.
    'status_cache_seconds' => 10,

];
