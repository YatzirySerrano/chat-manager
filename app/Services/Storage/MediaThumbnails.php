<?php

namespace App\Services\Storage;

use GdImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Small previews of the images of a conversation, so the chat does not
 * download every original (several megabytes for a camera photo) just to
 * paint a bubble. The original is only fetched when it is opened.
 *
 * A preview is made the first time it is asked for and kept on the same
 * private disk, in the system folder that mirrors the path of the original
 * (MediaPaths::thumbnailFor: "Miniaturas del sistema/{path}.jpg"), never
 * next to it: the folders of the contacts only hold what people sent, in
 * the format they sent it. Previews are always JPEG. They are served and
 * authorized exactly like the original and MediaDisk::discard() removes
 * both. Nothing here is required: without GD, with an unusual image or with
 * a disk that does not answer, the caller falls back to the original.
 */
class MediaThumbnails
{
    /** Longest side of a preview, in pixels (a bubble shows up to 288 px; 2x screens). */
    public const EDGE = 640;

    /** Images that need no preview (already small, transparent, unusual). */
    private const WITHOUT_PREFIX = 'media-thumbnails:none:';

    /**
     * Previews written next to the original by an earlier version (WebP
     * where GD had it). They are no longer made or served; they are only
     * listed so they are removed together with their original.
     *
     * @var list<string>
     */
    private const LEGACY_SUFFIXES = ['.thumb.webp', '.thumb.jpg'];

    /** Originals above this size are not decoded. */
    private const MAX_SOURCE_BYTES = 25 * 1024 * 1024;

    /** Originals above this many pixels are not decoded (GD needs ~5 bytes per pixel). */
    private const MAX_SOURCE_PIXELS = 16_000_000;

    /** An original this light and small is its own preview. */
    private const SMALL_ENOUGH_BYTES = 60 * 1024;

    /**
     * Animated formats (GIF) and WebP (stickers, usually transparent) are
     * never reduced: a still frame or a white background would change what
     * the message shows. A PNG with transparency keeps its original too.
     *
     * @var list<string>
     */
    private const TYPES = ['image/jpeg', 'image/png'];

    /**
     * Every place a preview of the file may be (to remove them with it).
     *
     * @return list<string>
     */
    public static function pathsFor(string $path): array
    {
        return [MediaPaths::thumbnailFor($path), ...self::legacyPathsFor($path)];
    }

    /**
     * Previews an earlier version left next to the original.
     *
     * @return list<string>
     */
    public static function legacyPathsFor(string $path): array
    {
        return array_map(fn (string $suffix): string => $path.$suffix, self::LEGACY_SUFFIXES);
    }

    public function supports(string $mime, ?int $sizeBytes): bool
    {
        return function_exists('imagecreatetruecolor')
            && in_array(strtolower($mime), self::TYPES, true)
            && ($sizeBytes === null || $sizeBytes <= self::MAX_SOURCE_BYTES);
    }

    /**
     * The preview of an image, made the first time it is asked for. Null
     * when none applies (unsupported type, already small, transparent PNG,
     * a disk that does not answer): the caller serves the original.
     *
     * Previews of a mounted disk (the NAS) are made and kept on this server
     * (LocalMedia, "{disk}/Miniaturas del sistema/..."), from the local copy
     * of the original. A web request never reads the NAS to make one: with
     * only a NAS copy it serves the NAS's own earlier preview, if any, and
     * the file is brought here in the background (CacheMediaLocally), which
     * makes the preview. "No preview needed" is remembered, so a small photo
     * is not opened again on every view.
     *
     * @return array{disk: string, path: string, mime: string, width: int|null, height: int|null}|null
     */
    public function ensure(string $disk, string $path, string $mime, ?int $sizeBytes = null): ?array
    {
        if (! $this->supports($mime, $sizeBytes) || $this->knownWithout($disk, $path)) {
            return null;
        }

        if (MountHealth::settings($disk) !== null) {
            return $this->ensureForMount($disk, $path, strtolower($mime));
        }

        $type = 'image/jpeg';
        $target = MediaPaths::thumbnailFor($path);
        $temporary = null;

        try {
            $filesystem = Storage::disk($disk);

            if ($filesystem->exists($target)) {
                return ['disk' => $disk, 'path' => $target, 'mime' => $type, 'width' => null, 'height' => null];
            }

            $local = config("filesystems.disks.{$disk}.driver") === 'local';
            $source = $local ? $filesystem->path($path) : ($temporary = $this->download($filesystem->readStream($path)));

            if ($source === null || ! is_file($source)) {
                return null;
            }

            $made = $this->make($source, strtolower($mime));

            if ($made === null) {
                $this->rememberWithout($disk, $path);

                return null;
            }

            [$bytes, $width, $height] = $made;

            if (! $filesystem->put($target, $bytes, ['visibility' => 'private'])) {
                return null;
            }

            return ['disk' => $disk, 'path' => $target, 'mime' => $type, 'width' => $width, 'height' => $height];
        } catch (Throwable $exception) {
            Log::warning('Could not make the preview of an image.', ['disk' => $disk, 'exception' => $exception::class]);

            return null;
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array{disk: string, path: string, mime: string, width: int|null, height: int|null}|null
     */
    private function ensureForMount(string $disk, string $path, string $mime): ?array
    {
        $local = app(LocalMedia::class);
        $thumbnail = MediaPaths::thumbnailFor($path);
        $target = $local->absolute($disk, $thumbnail);

        if (is_file($target)) {
            return ['disk' => $local->diskName(), 'path' => $local->localPath($disk, $thumbnail), 'mime' => 'image/jpeg', 'width' => null, 'height' => null];
        }

        if (! $local->hasLocal($disk, $path)) {
            // Only on the NAS: its earlier preview (made there by an older
            // version) while it answers, never the original read here.
            try {
                if ($local->remoteReadable($disk) && Storage::disk($disk)->exists($thumbnail)) {
                    return ['disk' => $disk, 'path' => $thumbnail, 'mime' => 'image/jpeg', 'width' => null, 'height' => null];
                }
            } catch (Throwable) {
                // The original is served instead.
            }

            return null;
        }

        try {
            $made = $this->make($local->absolute($disk, $path), $mime);

            if ($made === null) {
                $this->rememberWithout($disk, $path);

                return null;
            }

            [$bytes, $width, $height] = $made;

            if (! $local->filesystem()->put($local->localPath($disk, $thumbnail), $bytes, ['visibility' => 'private'])) {
                return null;
            }

            return ['disk' => $local->diskName(), 'path' => $local->localPath($disk, $thumbnail), 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height];
        } catch (Throwable $exception) {
            Log::warning('Could not make the preview of an image.', ['disk' => $disk, 'exception' => $exception::class]);

            return null;
        }
    }

    private function knownWithout(string $disk, string $path): bool
    {
        try {
            return Cache::has(self::WITHOUT_PREFIX.sha1($disk.'|'.$path));
        } catch (Throwable) {
            return false;
        }
    }

    private function rememberWithout(string $disk, string $path): void
    {
        try {
            Cache::put(self::WITHOUT_PREFIX.sha1($disk.'|'.$path), 1, 86400 * 30);
        } catch (Throwable) {
            // Only saves work.
        }
    }

    /**
     * Width and height of an image as it is shown (EXIF orientation
     * applied), or null. Reads the local copy when there is one; a file only
     * on a NAS that is not answering is not measured.
     *
     * @return array{width: int, height: int}|null
     */
    public function measure(string $disk, string $path, string $mime): ?array
    {
        $mime = strtolower($mime);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return null;
        }

        try {
            $file = app(LocalMedia::class)->open($disk, $path, cacheOnRead: false);

            if ($file === null) {
                return null;
            }

            if ($file['absolute'] !== null) {
                $source = $file['absolute'];
                $info = is_file($source) ? @getimagesize($source) : false;
                $orientation = $info !== false && $mime === 'image/jpeg' ? $this->orientation($source) : 1;
            } else {
                $stream = $file['filesystem']->readStream($file['path']);
                $head = is_resource($stream) ? (string) stream_get_contents($stream, 256 * 1024) : '';

                if (is_resource($stream)) {
                    fclose($stream);
                }

                $info = $head === '' ? false : @getimagesizefromstring($head);
                // Without the whole file the EXIF block cannot be trusted:
                // a camera photo is only measured when it has none.
                $orientation = $mime === 'image/jpeg' && str_contains(substr($head, 0, 65536), "Exif\0\0") ? null : 1;
            }
        } catch (Throwable) {
            return null;
        }

        if ($info === false || $orientation === null || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $rotated = in_array($orientation, [5, 6, 7, 8], true);

        return ['width' => $rotated ? $info[1] : $info[0], 'height' => $rotated ? $info[0] : $info[1]];
    }

    /**
     * @return array{string, int, int}|null Preview bytes and the size of the ORIGINAL in pixels (upright).
     */
    private function make(string $source, string $mime): ?array
    {
        $info = @getimagesize($source);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS) {
            return null;
        }

        [$width, $height] = $info;
        $orientation = $mime === 'image/jpeg' ? $this->orientation($source) : 1;

        if ($orientation === null) {
            return null;
        }

        if (max($width, $height) <= self::EDGE && (int) filesize($source) <= self::SMALL_ENOUGH_BYTES) {
            return null;
        }

        // Colour type of the PNG header: 4 and 6 carry an alpha channel.
        if ($mime === 'image/png' && in_array(ord((string) @file_get_contents($source, false, null, 25, 1)), [4, 6], true)) {
            return null;
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            default => false,
        };

        if (! $image instanceof GdImage) {
            return null;
        }

        $scale = min(1, self::EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $canvas instanceof GdImage) {
            return null;
        }

        // JPEG has no transparency: flatten on white.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $canvas = $this->upright($canvas, $orientation);

        ob_start();
        $written = imagejpeg($canvas, null, 80);
        $bytes = (string) ob_get_clean();

        if (! $written || $bytes === '') {
            return null;
        }

        // The size the browser will lay out: the original's, upright.
        $rotated = in_array($orientation, [5, 6, 7, 8], true);

        return [$bytes, $rotated ? $height : $width, $rotated ? $width : $height];
    }

    /**
     * EXIF orientation of a JPEG (1 when it has none). Null when the file
     * carries EXIF data that cannot be read here: a preview could come out
     * rotated, so the original is used.
     */
    private function orientation(string $source): ?int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $value = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

            return $value >= 1 && $value <= 8 ? $value : 1;
        }

        $head = (string) @file_get_contents($source, false, null, 0, 65536);

        return str_contains($head, "Exif\0\0") ? null : 1;
    }

    private function upright(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $degrees = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * Copies a remote original to a temporary file (GD reads files).
     *
     * @param  resource|null  $stream
     */
    private function download($stream): ?string
    {
        if (! is_resource($stream)) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'thumb');
        $target = $temporary === false ? false : fopen($temporary, 'wb');

        if ($temporary === false || $target === false) {
            fclose($stream);

            return null;
        }

        stream_copy_to_stream($stream, $target, self::MAX_SOURCE_BYTES + 1);
        fclose($stream);
        fclose($target);

        return $temporary;
    }
}
