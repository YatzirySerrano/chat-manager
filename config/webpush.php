<?php

/*
| Web Push (system notifications with the app closed). Pushes carry NO
| payload: the browser's push service only learns "wake up", and the service
| worker (public/sw.js) asks this server for the unread counter with the
| user's own session. Only a VAPID signature (ES256 via ext-openssl) is
| needed; there is no message encryption and no paid service.
|
| Generate the keys with `php artisan webpush:vapid` and copy them to .env.
| Without both keys Web Push is off and the preferences page says so.
*/

return [

    /*
    | Uncompressed P-256 public key (65 bytes) and private scalar (32 bytes),
    | both base64url without padding (the usual Web Push format).
    */
    'public_key' => env('VAPID_PUBLIC_KEY'),

    'private_key' => env('VAPID_PRIVATE_KEY'),

    /*
    | Contact of the operator for the push services: "mailto:" or an https
    | URL. Empty falls back to APP_URL.
    */
    'subject' => env('VAPID_SUBJECT') ?: env('APP_URL'),

    /*
    | Seconds a push service keeps an undelivered push (offline device).
    */
    'ttl' => (int) env('WEBPUSH_TTL', 3600),

    /*
    | At most one push per user within this many seconds: a burst of
    | notifications wakes each browser once, and it then shows the current
    | unread counter.
    */
    'throttle_seconds' => (int) env('WEBPUSH_THROTTLE_SECONDS', 60),

    /*
    | Short timeout of each request to a push service (seconds).
    */
    'timeout' => (int) env('WEBPUSH_TIMEOUT', 5),

    /*
    | Subscriptions without a registration or successful push in this many
    | days are deleted by notifications:prune.
    */
    'retention_days' => (int) env('WEBPUSH_RETENTION_DAYS', 120),

    /*
    | Hosts (or parent domains) of the push services accepted as endpoints,
    | so a subscription can never make the server call an arbitrary URL.
    */
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'WEBPUSH_ALLOWED_HOSTS',
        'fcm.googleapis.com,android.googleapis.com,push.services.mozilla.com,push.apple.com,notify.windows.com',
    ))))),

];
