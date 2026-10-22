<?php

use App\Jobs\Analytics\RefreshConversationMetrics;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SyncConnectionTemplates;

return [

    /*
    |--------------------------------------------------------------------------
    | Scheduler heartbeat
    |--------------------------------------------------------------------------
    |
    | `ops:heartbeat` runs every minute from the scheduler and stores the
    | current time in the cache. When the newest beat is older than
    | "stale_after_seconds" the scheduler is considered stopped.
    |
    */

    'scheduler' => [
        'heartbeat_key' => 'operations:scheduler-heartbeat',
        'stale_after_seconds' => (int) env('OPS_SCHEDULER_STALE_AFTER', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue backlog thresholds
    |--------------------------------------------------------------------------
    |
    | Pending jobs and age (seconds) of the oldest pending job per queue
    | responsibility (App\Support\Queues). "warning" is shown on the system
    | page; "critical" makes the readiness check fail and raises an alert.
    | Exports are slow by nature, so their age thresholds are wider.
    |
    */

    'queues' => [
        'default' => [
            'warning_pending' => (int) env('OPS_QUEUE_WARNING_PENDING', 500),
            'critical_pending' => (int) env('OPS_QUEUE_CRITICAL_PENDING', 5000),
            'warning_age' => (int) env('OPS_QUEUE_WARNING_AGE', 120),
            'critical_age' => (int) env('OPS_QUEUE_CRITICAL_AGE', 900),
        ],
        'overrides' => [
            'exports' => ['warning_pending' => 50, 'critical_pending' => 500, 'warning_age' => 900, 'critical_age' => 3600],
            'media' => ['warning_age' => 600, 'critical_age' => 3600],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed jobs
    |--------------------------------------------------------------------------
    |
    | "spike" failures within "spike_minutes" raise an alert. Only the job
    | classes listed in "retryable" (idempotent by design) can be sent back
    | to the queue from the system page; everything else can only be
    | discarded. Outbound WhatsApp messages are never retried from here: they
    | might already have reached Meta (use the conversation's retry action,
    | which knows the delivery state), and neither are late notifications
    | (they would announce stale events). An entry ending in "\" allows every
    | class of that namespace (queued listeners are identified by the
    | listener class).
    |
    */

    'failed_jobs' => [
        'warning_count' => (int) env('OPS_FAILED_JOBS_WARNING', 1),
        'critical_count' => (int) env('OPS_FAILED_JOBS_CRITICAL', 100),
        'spike' => (int) env('OPS_FAILED_JOBS_SPIKE', 10),
        'spike_minutes' => (int) env('OPS_FAILED_JOBS_SPIKE_MINUTES', 15),
        'page_size' => 25,
        'retryable' => [
            ProcessWhatsAppWebhook::class,
            RefreshConversationMetrics::class,
            SyncConnectionTemplates::class,
            'App\\Listeners\\Realtime\\',
            'App\\Listeners\\Analytics\\',
            'App\\Events\\Broadcasts\\',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp webhook backlog
    |--------------------------------------------------------------------------
    |
    | Stored deliveries still "received" after "grace_seconds" (they should
    | be processed within seconds) count as backlog.
    |
    */

    'webhooks' => [
        'grace_seconds' => (int) env('OPS_WEBHOOK_GRACE_SECONDS', 120),
        'warning_backlog' => (int) env('OPS_WEBHOOK_WARNING_BACKLOG', 50),
        'critical_backlog' => (int) env('OPS_WEBHOOK_CRITICAL_BACKLOG', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public readiness probe
    |--------------------------------------------------------------------------
    |
    | /health/ready is public: its verdict is reused for "cache_seconds" so
    | many monitors (or an abusive client) never multiply the health queries
    | and the Reverb TCP probe. 0 evaluates on every request.
    |
    */

    'readiness' => [
        'cache_seconds' => (int) env('OPS_READINESS_CACHE_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Real-time server probe
    |--------------------------------------------------------------------------
    |
    | When BROADCAST_CONNECTION=reverb, the health check opens a TCP
    | connection to the Reverb server (the address it listens on, usually
    | 127.0.0.1:8080 behind Nginx). It never sends application data.
    |
    */

    'reverb_probe' => [
        'host' => env('OPS_REVERB_PROBE_HOST'),
        'port' => env('OPS_REVERB_PROBE_PORT'),
        'timeout' => (float) env('OPS_REVERB_PROBE_TIMEOUT', 1.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recent errors
    |--------------------------------------------------------------------------
    |
    | The system page shows the last error-level lines of the application
    | log, reading at most "tail_bytes" from the end of the newest file (the
    | log is never loaded whole). Only the first line of each entry is
    | shown, with tokens, e-mail addresses and phone numbers masked.
    |
    */

    'recent_errors' => [
        'tail_bytes' => (int) env('OPS_RECENT_ERRORS_TAIL_BYTES', 262144),
        'limit' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | `ops:check-alerts` (every five minutes) evaluates the critical
    | conditions: failed jobs spike, scheduler stopped, webhook backlog and
    | critical queue backlog. Each alert is written to the "ops" log channel
    | and mailed to OPS_ALERT_EMAILS (comma separated) and, when
    | OPS_ALERT_NOTIFY_ADMINS=true, to the active users who can view the
    | system page. The same alert is repeated at most once per
    | "throttle_minutes".
    |
    */

    'alerts' => [
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_ALERT_EMAILS', ''))))),
        'notify_admins' => (bool) env('OPS_ALERT_NOTIFY_ADMINS', false),
        'throttle_minutes' => (int) env('OPS_ALERT_THROTTLE_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP hardening
    |--------------------------------------------------------------------------
    |
    | trusted_proxies: addresses of the reverse proxies allowed to set the
    | X-Forwarded-* headers ("*" only when the application port is reachable
    | exclusively through the proxy). force_https: generate https URLs in
    | production when APP_URL is https. The Content-Security-Policy is sent
    | when "csp" is enabled (default: production) and never while the Vite
    | development server is running.
    |
    */

    'http' => [
        'trusted_proxies' => env('TRUSTED_PROXIES'),
        'force_https' => (bool) env('FORCE_HTTPS', true),
        'csp' => env('SECURITY_CSP_ENABLED'),
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    ],

];
