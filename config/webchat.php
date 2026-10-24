<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web chat channel
    |--------------------------------------------------------------------------
    |
    | Limits and lifetimes of the embeddable web chat widget and its visitor
    | sessions. The idle lifetime of a session is configured per widget
    | (web_chat_widgets.session_idle_days); it never extends beyond the
    | absolute cap below, counted from the session's creation.
    |
    */

    'session' => [
        'absolute_days' => (int) env('WEBCHAT_SESSION_ABSOLUTE_DAYS', 90),
    ],

    'limits' => [
        'sessions_per_ip_per_minute' => (int) env('WEBCHAT_SESSIONS_PER_IP_PER_MINUTE', 10),
        // Counts only sessions started with a valid frame ticket.
        'sessions_per_widget_per_minute' => (int) env('WEBCHAT_SESSIONS_PER_WIDGET_PER_MINUTE', 300),
        'messages_per_session_per_minute' => (int) env('WEBCHAT_MESSAGES_PER_SESSION_PER_MINUTE', 20),
        'messages_per_ip_per_minute' => (int) env('WEBCHAT_MESSAGES_PER_IP_PER_MINUTE', 60),
        // Cost ceiling shared by every visitor of a connection (0 disables
        // it). Kept high on purpose: a low shared cap lets a few addresses
        // deny the chat to everyone else.
        'messages_per_connection_per_minute' => (int) env('WEBCHAT_MESSAGES_PER_CONNECTION_PER_MINUTE', 6000),
        'frame_per_minute' => (int) env('WEBCHAT_FRAME_PER_MINUTE', 120),
        'reads_per_minute' => (int) env('WEBCHAT_READS_PER_MINUTE', 60),
        'broadcasting_per_minute' => (int) env('WEBCHAT_BROADCASTING_PER_MINUTE', 30),
    ],

    /** Maximum length of a visitor message. */
    'message_max_length' => 2000,

    /** Signed identities: tolerated clock skew of `iat` and lifetime of a used nonce. */
    'identity' => [
        'max_clock_skew_seconds' => 300,
        'nonce_ttl_seconds' => 600,
    ],

    /** Lifetime of the ticket that binds a session to an authorized frame load. */
    'frame_ticket_ttl_seconds' => 600,

    /** Polling fallback when the realtime connection is unavailable. */
    'poll' => [
        'visible_seconds' => 4,
        'hidden_seconds' => 20,
    ],

    /** Lifetime of the signed URLs of attachments sent to the visitor. */
    'attachments' => [
        'url_ttl_seconds' => 600,
    ],

    /** Expired or revoked sessions are deleted after this many days. */
    'prune_after_days' => 30,

    /** Example host page (GET /widget/v1/demo/{publicKey}); only ever registered in local. */
    'demo_enabled' => (bool) env('WEBCHAT_DEMO_ENABLED', false),

];
