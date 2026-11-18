<?php

/*
| Previews of the web addresses shared in messages (App\Services\LinkPreviews).
| The server reads the page's Open Graph data on behalf of the viewer, only
| for addresses written in a message they may see, and keeps the result in
| the link_previews table:
|
| - enabled (LINK_PREVIEWS_ENABLED): false = links stay plain links.
| - Only http/https on ports 80/443; every host is resolved and refused when
|   any of its addresses is private, loopback, link-local or reserved, again
|   after each redirect (at most max_redirects). The connection goes to the
|   address that was checked (no second DNS lookup).
| - timeout_seconds per request, total_seconds for a whole preview (page,
|   image and icon); max_html_bytes of the page are read (the tags are in
|   its head), images above max_image_bytes are not kept.
| - ttl_hours for a preview that worked, failed_ttl_hours for one that did
|   not (negative cache).
*/

return [

    'enabled' => (bool) env('LINK_PREVIEWS_ENABLED', true),

    'timeout_seconds' => 4,

    'total_seconds' => 10,

    'max_redirects' => 3,

    'max_html_bytes' => 512 * 1024,

    'max_image_bytes' => 3 * 1024 * 1024,

    'max_icon_bytes' => 100 * 1024,

    'ttl_hours' => (int) (env('LINK_PREVIEWS_TTL_HOURS') ?: 24 * 7),

    'failed_ttl_hours' => (int) (env('LINK_PREVIEWS_FAILED_TTL_HOURS') ?: 6),

    'user_agent' => 'Mozilla/5.0 (compatible; LinkPreviewBot/1.0; +https://ogp.me)',

];
