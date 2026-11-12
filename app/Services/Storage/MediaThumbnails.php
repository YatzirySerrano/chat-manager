<?php

namespace App\Services\Storage;

use GdImage;
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
     * The preview of a stored image, made now if it does not exist yet.
     * Null when the original should be used instead.
     *
     * @return array{path: string, mime: string, width: int|null, height: int|null}|null
     */
    public function ensure(string $disk, string $path, string $mime, ?int $sizeBytes = null): ?array
    {
        if (! $this->supports($mime, $sizeBytes)) {
            return null;
        }

        $type = 'image/jpeg';
        $target = MediaPaths::thumbnailFor($path);
        $temporary = null;

        try {
            $filesystem = Storage::disk($disk);

            if ($filesystem->exists($target)) {
                return ['path' => $target, 'mime' => $type, 'width' => null, 'height' => null];
            }

            $local = config("filesystems.disks.{$disk}.driver") === 'local';
            $source = $local ? $filesystem->path($path) : ($temporary = $this->download($filesystem->readStream($path)));

            if ($source === null || ! is_file($source)) {
                return null;
            }

            $made = $this->make($source, strtolower($mime));

            if ($made === null) {
                return null;
            }

            [$bytes, $width, $height] = $made;

            if (! $filesystem->put($target, $bytes, ['visibility' => 'private'])) {
                return null;
            }

            return ['path' => $target, 'mime' => $type, 'width' => $width, 'height' => $height];
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
