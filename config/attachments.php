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

    'link_max_bytes' => (int) (env('ATTACHMENT_LINK_MAX_BYTES') ?: 100 * 1024 * 1024),

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
    */
    'storage' => [
        'media_disk_required' => (bool) env('MEDIA_DISK_REQUIRED', false),
        'quota_bytes' => max(0, (int) env('ATTACHMENT_STORAGE_QUOTA_BYTES', 0)),
        'warning_percent' => (int) (env('ATTACHMENT_STORAGE_WARNING_PERCENT') ?: 80),
        'pending_stale_minutes' => max(5, (int) (env('ATTACHMENT_PENDING_STALE_MINUTES') ?: 60)),
        'inbound_available_days' => 7,
        'max_download_attempts' => 12,
        'recover_batch' => 200,
    ],

    /** The web chat serves the files itself: one limit for every type. */
    'web_chat' => [
        'max_bytes' => (int) (env('WEBCHAT_ATTACHMENT_MAX_BYTES') ?: 25 * 1024 * 1024),
    ],

];
