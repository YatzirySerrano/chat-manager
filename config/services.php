<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Meta Graph API used to verify WhatsApp Cloud API connections. Credentials
    | are stored encrypted per connection, never here. Empty values fall back
    | to the defaults below.
    */
    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION') ?: 'v26.0',
        'base_url' => env('META_GRAPH_BASE_URL') ?: 'https://graph.facebook.com',
        'timeout' => (int) (env('META_GRAPH_TIMEOUT') ?: 10),
        // Uploading a file to WhatsApp (POST /{phone-number-id}/media, up to
        // 100 MB, streamed from the media disk). Capped so the upload plus
        // the message send fit in one delivery attempt (140 s): at most
        // 140 - META_GRAPH_TIMEOUT - 5 - 10 s (115 s with the defaults).
        'media_upload_timeout' => (int) (env('META_MEDIA_UPLOAD_TIMEOUT') ?: 60),

        /*
        | Meta app used to authorize Facebook Messenger pages and Instagram
        | professional accounts (Facebook Login for Business) and to verify the
        | app-level webhook (webhooks/meta). WhatsApp connections keep their
        | own credentials per connection. Without these values the Messenger
        | and Instagram authorization is unavailable.
        */
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'login_config_id' => env('META_LOGIN_CONFIG_ID'),
        'instagram_app_id' => env('META_INSTAGRAM_APP_ID'),
        'instagram_app_secret' => env('META_INSTAGRAM_APP_SECRET'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),

        /*
        | Limits of the public Meta routes per source address: valid webhook
        | deliveries, invalid signatures (refused with 429 once exceeded) and
        | the signed media URLs Meta fetches for outbound attachments.
        */
        'webhook_rate_limit_per_minute' => (int) (env('META_WEBHOOK_RATE_LIMIT') ?: 6000),
        'webhook_invalid_signatures_per_minute' => (int) (env('META_WEBHOOK_INVALID_SIGNATURE_LIMIT') ?: 30),
        'media_rate_limit_per_minute' => (int) (env('META_MEDIA_RATE_LIMIT') ?: 1200),

        /*
        | Instagram API with Instagram Login hosts (docs/meta-messenger-instagram.md).
        | The HUMAN_AGENT message tag (7-day window for human replies) requires
        | Meta's approval of the Human Agent feature: it stays off by default.
        */
        'instagram_base_url' => env('META_INSTAGRAM_GRAPH_BASE_URL') ?: 'https://graph.instagram.com',
        'human_agent_tag' => (bool) env('META_HUMAN_AGENT_TAG', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
