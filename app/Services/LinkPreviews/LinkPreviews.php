<?php

namespace App\Services\LinkPreviews;

use App\Exceptions\StorageUnavailable;
use App\Models\LinkPreview;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Previews of the web addresses written in messages: the first address of
 * a message is read by the server (SafeFetcher: public addresses only, one
 * pinned connection per hop, bounded time and size), its Open Graph data
 * is parsed (OpenGraphParser) and kept in link_previews for every message
 * and viewer that shares it, until it expires (a failed one for less time:
 * negative cache). Its image and icon are re-encoded and copied to the
 * private media disk, in the system folder, and only ever served from
 * there (never hot-linked from the other site).
 *
 * Why on demand (an authorized request when a bubble with a link is
 * shown) and not when the message is stored: it also works for the
 * messages that already exist and for imported history, needs no queue
 * worker, never slows down or risks the intake of a message, and only
 * fetches what somebody actually looks at. The cache (one row per address,
 * a lock per address) means a site is asked once, not once per viewer.
 */
class LinkPreviews
{
    /** @var list<string> */
    public const PAGE_TYPES = ['text/html', 'application/xhtml+xml'];

    /** @var list<string> */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** SVG is never kept: it is a document, not a picture. @var list<string> */
    public const ICON_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon'];

    /** System folder of the copies, next to the chat thumbnails. */
    public const FOLDER = MediaPaths::THUMBNAILS.'/Vistas previas de enlaces';

    /** @var array<string, string> */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Widest side of a kept preview image, in pixels. */
    private const IMAGE_EDGE = 800;

    /** Images with more pixels than this are never decoded. */
    private const MAX_PIXELS = 25_000_000;

    /** Same pattern as the chat (WhatsAppText): http/https up to a space, without trailing punctuation. */
    private const URL_PATTERN = '/https?:\/\/[^\s<>"\'`]+[^\s<>"\'`.,;:!?)\]*_~]/iu';

    public function __construct(
        private SafeFetcher $fetcher,
        private OpenGraphParser $parser,
        private MediaDisk $media,
    ) {}

    /** The first http/https address written in a text, or null. */
    public static function firstUrl(?string $text): ?string
    {
        if ($text === null || preg_match(self::URL_PATTERN, $text, $match) !== 1) {
            return null;
        }

        return strlen($match[0]) <= 2048 ? $match[0] : null;
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    /** The address without its fragment (never sent to the site). */
    public static function normalize(string $url): string
    {
        return explode('#', trim($url), 2)[0];
    }

    /**
     * The preview of an address, fetched now when there is none or it
     * expired. Null when previews are off or the address is being fetched
     * by another request for too long.
     */
    public function forUrl(string $url): ?LinkPreview
    {
        $url = self::normalize($url);
        $hash = self::hash($url);
        $known = LinkPreview::query()->where('url_hash', $hash)->first();

        if ($known?->isFresh() || ! config('link_previews.enabled', true)) {
            return $known;
        }

        try {
            return Cache::lock('link-preview:'.$hash, 30)->block(12, function () use ($url, $hash): LinkPreview {
                $current = LinkPreview::query()->where('url_hash', $hash)->first();

                return $current?->isFresh() ? $current : $this->refresh($current, $url, $hash);
            });
        } catch (LockTimeoutException) {
            return $known;
        }
    }

    /**
     * What the frontend draws; image and icon as stable signed addresses
     * of this server (link-previews.image), never the other site's.
     *
     * @return array<string, mixed>|null
     */
    public static function present(?LinkPreview $preview): ?array
    {
        if ($preview === null) {
            return null;
        }

        $located = $preview->latitude !== null && $preview->longitude !== null;
        $host = strtolower((string) parse_url($preview->final_url ?? $preview->url, PHP_URL_HOST));

        return [
            'url' => $preview->url,
            'status' => $preview->status,
            'domain' => preg_replace('/^www\./', '', $host),
            'title' => $preview->title,
            'description' => $preview->description,
            'site_name' => $preview->site_name,
            'maps' => MapsLink::is($preview->url) || MapsLink::is($preview->final_url ?? ''),
            'location' => $located ? ['latitude' => (float) $preview->latitude, 'longitude' => (float) $preview->longitude] : null,
            'image_url' => $preview->hasImage() ? URL::signedRoute('link-previews.image', ['linkPreview' => $preview->id, 'variant' => 'image', 'v' => $preview->fetched_at?->getTimestamp()]) : null,
            'image_width' => $preview->image_width,
            'image_height' => $preview->image_height,
            'icon_url' => $preview->hasIcon() ? URL::signedRoute('link-previews.image', ['linkPreview' => $preview->id, 'variant' => 'icon', 'v' => $preview->fetched_at?->getTimestamp()]) : null,
        ];
    }

    private function refresh(?LinkPreview $preview, string $url, string $hash): LinkPreview
    {
        $preview ??= new LinkPreview;
        $preview->forceFill(['url_hash' => $hash, 'url' => $url]);
        $deadline = microtime(true) + (float) config('link_previews.total_seconds', 10);
        $previousImage = [$preview->image_disk, $preview->image_path];
        $previousIcon = [$preview->icon_disk, $preview->icon_path];
        $fields = ['final_url' => null, 'title' => null, 'description' => null, 'site_name' => null, 'latitude' => null, 'longitude' => null,
            'image_disk' => null, 'image_path' => null, 'image_mime' => null, 'image_width' => null, 'image_height' => null,
            'icon_disk' => null, 'icon_path' => null, 'icon_mime' => null, 'error' => null];

        try {
            $page = $this->fetcher->fetch($url, self::PAGE_TYPES, (int) config('link_previews.max_html_bytes', 512 * 1024), $deadline, truncate: true);
            $data = $this->parser->parse($page['body'], $page['url'], $page['charset']);
            $fields = [...$fields, 'final_url' => $page['url'], 'title' => $data['title'], 'description' => $data['description'], 'site_name' => $data['site_name']];
            $status = LinkPreview::READY;
        } catch (BlockedAddress $exception) {
            $data = ['image' => null, 'icon' => null];
            $status = LinkPreview::FAILED;
            $fields['error'] = $exception->reason;
        }

        // A Google Maps address names its point (after the redirects of a
        // short link); it is a place even when the page could not be read.
        $maps = MapsLink::is($url) || MapsLink::is((string) $fields['final_url']);

        if ($maps && $fields['error'] !== 'blocked_address') {
            $point = MapsLink::coordinates((string) $fields['final_url']) ?? MapsLink::coordinates($url);

            if ($point !== null) {
                $fields = [...$fields, 'latitude' => $point['latitude'], 'longitude' => $point['longitude']];
                $fields['title'] ??= MapsLink::placeName((string) $fields['final_url']) ?? MapsLink::placeName($url);
                $status = LinkPreview::READY;
            }
        }

        if ($status === LinkPreview::READY && $data['image'] !== null) {
            $fields = [...$fields, ...$this->keepImage($data['image'], $hash, $deadline)];
        }

        if ($status === LinkPreview::READY && $fields['image_path'] === null && $data['icon'] !== null) {
            $fields = [...$fields, ...$this->keepIcon($data['icon'], $hash, $deadline)];
        }

        $hours = $status === LinkPreview::READY ? (int) config('link_previews.ttl_hours', 168) : (int) config('link_previews.failed_ttl_hours', 6);
        $preview->forceFill([...$fields, 'status' => $status, 'fetched_at' => now(), 'expires_at' => now()->addHours(max(1, $hours))]);

        try {
            $preview->save();
        } catch (UniqueConstraintViolationException) {
            // Another server process stored it first: theirs is as good.
            return LinkPreview::query()->where('url_hash', $hash)->firstOrFail();
        }

        foreach ([$previousImage, $previousIcon] as [$disk, $path]) {
            if ($disk !== null && $path !== null && $path !== $preview->image_path && $path !== $preview->icon_path) {
                $this->media->discard($disk, $path);
            }
        }

        return $preview;
    }

    /**
     * Downloads the page's image, checks it really is one, reduces it to
     * a JPEG of at most IMAGE_EDGE pixels and keeps it on the media disk.
     *
     * @return array<string, mixed>
     */
    private function keepImage(string $url, string $hash, float $deadline): array
    {
        try {
            $file = $this->fetcher->fetch($url, self::IMAGE_TYPES, (int) config('link_previews.max_image_bytes', 3 * 1024 * 1024), $deadline);
        } catch (BlockedAddress) {
            return [];
        }

        $size = @getimagesizefromstring($file['body']);

        if (! is_array($size) || ! in_array($size['mime'], self::IMAGE_TYPES, true) || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return [];
        }

        [$body, $mime, $width, $height] = $this->reduce($file['body'], $size[0], $size[1]) ?? [$file['body'], $size['mime'], $size[0], $size[1]];
        $stored = $this->store($body, substr($hash, 0, 32), self::EXTENSIONS[$mime] ?? 'img');

        return $stored === null ? [] : [
            'image_disk' => $stored['disk'],
            'image_path' => $stored['path'],
            'image_mime' => $mime,
            'image_width' => min($width, 65535),
            'image_height' => min($height, 65535),
        ];
    }

    /**
     * The page icon, when there is no image: kept as received after
     * checking it is a real picture (an ICO by its signature).
     *
     * @return array<string, mixed>
     */
    private function keepIcon(string $url, string $hash, float $deadline): array
    {
        try {
            $file = $this->fetcher->fetch($url, self::ICON_TYPES, (int) config('link_previews.max_icon_bytes', 100 * 1024), $deadline);
        } catch (BlockedAddress) {
            return [];
        }

        if (str_starts_with($file['body'], "\x00\x00\x01\x00")) {
            $mime = 'image/x-icon';
            $extension = 'ico';
        } else {
            $size = @getimagesizefromstring($file['body']);

            if (! is_array($size) || ! in_array($size['mime'], self::IMAGE_TYPES, true)) {
                return [];
            }

            $mime = $size['mime'];
            $extension = self::EXTENSIONS[$mime];
        }

        $stored = $this->store($file['body'], substr($hash, 0, 32).' icono', $extension);

        return $stored === null ? [] : ['icon_disk' => $stored['disk'], 'icon_path' => $stored['path'], 'icon_mime' => $mime];
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: int}|null
     */
    private function reduce(string $body, int $width, int $height): ?array
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $source = @imagecreatefromstring($body);

            if ($source === false) {
                return null;
            }

            $scale = min(1, self::IMAGE_EDGE / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            // Transparent pictures (PNG logos) are drawn on white, as a JPEG has no alpha.
            imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            ob_start();
            imagejpeg($target, null, 82);
            $jpeg = (string) ob_get_clean();

            return $jpeg === '' ? null : [$jpeg, 'image/jpeg', $targetWidth, $targetHeight];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{disk: string, path: string}|null
     */
    private function store(string $body, string $name, string $extension): ?array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'lnk');

        if ($temporary === false) {
            return null;
        }

        try {
            file_put_contents($temporary, $body);

            return $this->media->putFile($temporary, self::FOLDER, $name.'.'.$extension);
        } catch (StorageUnavailable $exception) {
            Log::info('Link preview image not kept: media storage unavailable.', ['reason' => $exception->getMessage()]);

            return null;
        } finally {
            @unlink($temporary);
        }
    }
}
