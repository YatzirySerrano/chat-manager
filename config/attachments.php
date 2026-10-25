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

    /** The web chat serves the files itself: one limit for every type. */
    'web_chat' => [
        'max_bytes' => (int) (env('WEBCHAT_ATTACHMENT_MAX_BYTES') ?: 25 * 1024 * 1024),
    ],

];
