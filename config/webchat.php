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

    /*
    | Files visitors send from the widget (paperclip), when the widget allows
    | it (web_chat_widgets.allow_visitor_files). The type is read from the
    | file's bytes (App\Services\WebChat\VisitorFiles): images, PDF, Office,
    | TXT/CSV, audio and video; never archives or executables. Sizes are in
    | decimal bytes (1 MB = 1,000,000) like the composer's limits; videos have
    | their own, larger cap. The PHP upload limits of the server
    | (upload_max_filesize, post_max_size) may lower both: the widget is told
    | the effective limit. Each upload also counts as a message for the
    | message limits above.
    */
    'visitor_files' => [
        'max_bytes' => (int) (env('WEBCHAT_VISITOR_FILE_MAX_BYTES') ?: 25_000_000),
        'video_max_bytes' => (int) (env('WEBCHAT_VISITOR_VIDEO_MAX_BYTES') ?: 64_000_000),
        'per_session_per_minute' => (int) env('WEBCHAT_VISITOR_FILES_PER_SESSION_PER_MINUTE', 6),
        'per_ip_per_minute' => (int) env('WEBCHAT_VISITOR_FILES_PER_IP_PER_MINUTE', 20),
        /** Longest caption sent with a file. */
        'caption_max_length' => 1000,
    ],

    /** Lifetime of the signed URLs of the files shown in the widget. */
    'attachments' => [
        'url_ttl_seconds' => 600,
    ],

    /** Expired or revoked sessions are deleted after this many days. */
    'prune_after_days' => 30,

    /** Example host page (GET /widget/v1/demo/{publicKey}); only ever registered in local. */
    'demo_enabled' => (bool) env('WEBCHAT_DEMO_ENABLED', false),

];
