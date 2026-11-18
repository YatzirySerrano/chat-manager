<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The preview of one web address shared in messages (App\Services\LinkPreviews\LinkPreviews).
 * One row per address (url_hash = SHA-256 of the normalized address), shared
 * by every message that mentions it; "failed" rows are the negative cache.
 *
 * @property int $id
 * @property string $url_hash
 * @property string $url
 * @property string|null $final_url Where the redirects ended.
 * @property string $status ready|failed
 * @property string|null $error Why it failed (blocked_address, timeout, not_html...).
 * @property string|null $title
 * @property string|null $description
 * @property string|null $site_name
 * @property string|null $latitude A Google Maps address that names a point.
 * @property string|null $longitude
 * @property string|null $image_disk
 * @property string|null $image_path
 * @property string|null $image_mime
 * @property int|null $image_width
 * @property int|null $image_height
 * @property string|null $icon_disk
 * @property string|null $icon_path
 * @property string|null $icon_mime
 * @property Carbon|null $fetched_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LinkPreview extends Model
{
    public const READY = 'ready';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'image_width' => 'integer',
            'image_height' => 'integer',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function isFresh(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function hasImage(): bool
    {
        return $this->image_disk !== null && $this->image_path !== null;
    }

    public function hasIcon(): bool
    {
        return $this->icon_disk !== null && $this->icon_path !== null;
    }
}
