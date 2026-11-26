<?php

/*
| Files agents attach to replies, beyond each provider's own limits
| (App\Support\Attachments\ChannelAttachmentPolicy; WhatsApp limits live in
| config/whatsapp.php "media.types").
|
| Link delivery: a file the channel cannot carry natively (ZIP on WhatsApp,
| Messenger and Instagram) stays on the private media disk and the customer
| receives a text with a signed download URL that expires after
| "link_ttl_days" days.
*/

return [

    'link_ttl_days' => max(1, (int) (env('ATTACHMENT_LINK_TTL_DAYS') ?: 7)),

    'link_max_bytes' => (int) (env('ATTACHMENT_LINK_MAX_BYTES') ?: 100_000_000),

    /** Downloads per minute and address on the public link route. */
    'link_downloads_per_minute' => (int) (env('ATTACHMENT_LINK_DOWNLOADS_PER_MINUTE') ?: 30),

    /** Link delivery for Meta channels other than WhatsApp (see whatsapp.media.link_fallback). */
    'link_fallback' => [
        'messenger' => (bool) env('MESSENGER_MEDIA_LINK_FALLBACK', true),
        'instagram' => (bool) env('INSTAGRAM_MEDIA_LINK_FALLBACK', true),
    ],

    /*
    | Persistent copies of conversation media (App\Services\Storage\MediaDisk).
    | The files live on filesystems.media_disk (MEDIA_DISK): "local" keeps them
    | on this server; an S3-compatible private bucket ("s3" disk, AWS_* keys)
    | keeps them outside it. The database only stores metadata.
    |
    | - media_disk_required (MEDIA_DISK_REQUIRED): true = files are only kept on
    |   an external disk; while MEDIA_DISK is "local" or the external disk is
    |   not ready, uploads are refused with a clear message (never written to
    |   the server's disk instead).
    | - quota_bytes (ATTACHMENT_STORAGE_QUOTA_BYTES, 0 = no quota) and
    |   warning_percent: the system page warns when the recorded usage
    |   (SUM of size_bytes) reaches the percent of the quota.
    | - Customer media still "pending" for pending_stale_minutes is retried by
    |   attachments:recover-stale while Meta keeps it (inbound_available_days;
    |   WhatsApp media ids from webhooks expire after 7 days), at most
    |   max_download_attempts downloads in total and recover_batch rows per run.
    | - mount (only for a mounted media disk, MEDIA_DISK=nas): the health probe
    |   (storage:mount-health, every minute) runs in a separate process killed
    |   after probe_timeout_seconds; its result is trusted for
    |   health_ttl_seconds. verify_hash re-reads each written file and compares
    |   its SHA-256 with the one computed while writing.
    */
    'storage' => [
        'media_disk_required' => (bool) env('MEDIA_DISK_REQUIRED', false),
        'quota_bytes' => max(0, (int) env('ATTACHMENT_STORAGE_QUOTA_BYTES', 0)),
        'warning_percent' => (int) (env('ATTACHMENT_STORAGE_WARNING_PERCENT') ?: 80),
        'pending_stale_minutes' => max(5, (int) (env('ATTACHMENT_PENDING_STALE_MINUTES') ?: 60)),
        'inbound_available_days' => 7,
        'max_download_attempts' => 12,
        'recover_batch' => 200,
        // Disk the «Guardar archivo» copies are written to (VideoFolders);
        // empty = the media disk (MEDIA_DISK, the NAS when mounted).
        'video_exports_disk' => env('VIDEO_EXPORTS_DISK'),
        'mount' => [
            'health_ttl_seconds' => (int) env('MEDIA_MOUNT_HEALTH_TTL', 90),
            'probe_timeout_seconds' => (float) env('MEDIA_MOUNT_PROBE_TIMEOUT', 10),
            'verify_hash' => (bool) env('MEDIA_MOUNT_VERIFY_HASH', true),
            // A probe slower than this marks the NAS "degraded".
            'slow_ms' => (int) env('MEDIA_MOUNT_SLOW_MS', 3000),
        ],
        /*
        | Local first (App\Services\Storage\LocalMedia): new files of a
        | mounted disk (the NAS) are written to the "media_local" disk of this
        | server first, served from there and copied to the NAS by the
        | media-sync workers (verified by size and SHA-256). The NAS state only
        | gates the remote copy, never sending or receiving files.
        |
        | - pending_max_bytes: files not yet on the NAS (never deleted here);
        |   past it new files go straight to the NAS when it answers.
        | - cache_max_bytes / retention_days: synchronized copies kept here to
        |   be served without touching the NAS; pruned by media:prune-local.
        | - min_free_bytes: free space always left on the server's disk.
        | - sync_*: batch per minute, retry backoff (seconds, with jitter),
        |   consecutive failures that open the circuit and its pause.
        | - remote_reads: HTTP may stream a file that is only on the NAS while
        |   its last health check passed; it is also copied here
        |   (cache_on_read_max_bytes) so the next view is local.
        */
        'local_first' => [
            'enabled' => (bool) env('MEDIA_LOCAL_FIRST', true),
            'disk' => 'media_local',
            'pending_max_bytes' => (int) env('MEDIA_LOCAL_PENDING_MAX_BYTES', 80 * 1024 ** 3),
            'cache_max_bytes' => (int) env('MEDIA_LOCAL_CACHE_MAX_BYTES', 20 * 1024 ** 3),
            'retention_days' => (int) env('MEDIA_LOCAL_RETENTION_DAYS', 7),
            'min_free_bytes' => (int) env('MEDIA_LOCAL_MIN_FREE_BYTES', 40 * 1024 ** 3),
            'sync_batch' => (int) env('MEDIA_SYNC_BATCH', 20),
            'sync_lease_seconds' => (int) env('MEDIA_SYNC_LEASE_SECONDS', 900),
            'sync_backoff_base' => (int) env('MEDIA_SYNC_BACKOFF_BASE', 30),
            'sync_backoff_max' => (int) env('MEDIA_SYNC_BACKOFF_MAX', 3600),
            'breaker_failures' => (int) env('MEDIA_SYNC_BREAKER_FAILURES', 5),
            'breaker_seconds' => (int) env('MEDIA_SYNC_BREAKER_SECONDS', 300),
            'remote_reads' => (bool) env('MEDIA_REMOTE_READS', true),
            'cache_on_read_max_bytes' => (int) env('MEDIA_CACHE_ON_READ_MAX_BYTES', 16 * 1024 ** 2),
            // Admission control of the NAS (NasGate): concurrent transfers at
            // most, for the workers (copies and fetches) and for web requests
            // streaming a file only on the NAS; and their maximum speed.
            'nas_slots' => [
                // Copies of new files (media-sync) and fetches of old ones
                // (media-fetch) have separate turns: fetches never starve sync.
                'sync' => (int) env('MEDIA_NAS_SYNC_SLOTS', 1),
                'fetch' => (int) env('MEDIA_NAS_FETCH_SLOTS', 1),
                'http' => (int) env('MEDIA_NAS_HTTP_SLOTS', 3),
            ],
            'nas_max_bytes_per_second' => (int) env('MEDIA_NAS_MAX_BYTES_PER_SECOND', 4 * 1024 ** 2),
            'sync_timeout_seconds' => (int) env('MEDIA_SYNC_TIMEOUT', 600),
            // nginx "internal" location aliased to MEDIA_LOCAL_ROOT (e.g. /_media-local/); empty = PHP sends the file.
            'x_accel_prefix' => env('MEDIA_X_ACCEL_PREFIX', ''),
        ],
    ],

    /** The web chat serves the files itself: one limit for every type. */
    'web_chat' => [
        'max_bytes' => (int) (env('WEBCHAT_ATTACHMENT_MAX_BYTES') ?: 25_000_000),
    ],

];
