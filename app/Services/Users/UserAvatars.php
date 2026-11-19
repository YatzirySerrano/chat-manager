<?php

namespace App\Services\Users;

use App\Exceptions\StorageUnavailable;
use App\Models\User;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use App\Support\ProtectedAccounts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The profile picture each person chooses for themselves («Ajustes →
 * Perfil»). The upload is never kept as sent: it is decoded, cropped to a
 * centered square, scaled to SIZE px and written again as JPEG, so no
 * EXIF data (location, device) and nothing but pixels reaches the disk.
 * Stored on the private media disk under «Usuarios/{name} ({id})» and
 * served by UserAvatarController to signed-in people only.
 *
 * map(): every person's picture URL by id, shared with each page so any
 * avatar of a teammate (inbox, conversation, internal chat, members) can
 * show it from the id alone. Protected accounts are left out for viewers
 * who may not see them.
 */
class UserAvatars
{
    /** Side of the stored square, in pixels (shown at 56 px at most, sharp on 4x screens). */
    public const SIZE = 320;

    /** Largest upload accepted, in kilobytes. */
    public const MAX_UPLOAD_KB = 8192;

    /** Folder of the pictures on the media disk. */
    public const FOLDER = 'Usuarios';

    private const MAP_CACHE_KEY = 'user-avatars:map:v1';

    public function __construct(private MediaDisk $mediaDisk) {}

    /**
     * Replaces the person's picture with the uploaded image.
     *
     * @throws ValidationException When the file is not a usable image.
     * @throws StorageUnavailable When the media disk cannot keep it.
     */
    public function store(User $user, UploadedFile $file): void
    {
        $temporary = $this->normalized($file);

        try {
            $stored = $this->mediaDisk->putNamedFile(
                $temporary,
                self::FOLDER.'/'.$this->folderName($user),
                now()->format('Y-m-d H.i.s').' Foto de perfil',
                'jpg',
            );
        } finally {
            @unlink($temporary);
        }

        $previous = $user->hasAvatar() ? ['disk' => (string) $user->avatar_disk, 'path' => (string) $user->avatar_path] : null;

        $user->forceFill([
            'avatar_disk' => $stored['disk'],
            'avatar_path' => $stored['path'],
            'avatar_version' => Str::lower(Str::random(10)),
        ])->save();

        if ($previous !== null && $previous['path'] !== $stored['path']) {
            $this->mediaDisk->discard($previous['disk'], $previous['path']);
        }

        $this->forget();
    }

    /**
     * Removes the person's picture: their initials are shown again.
     */
    public function remove(User $user): void
    {
        if (! $user->hasAvatar()) {
            return;
        }

        $disk = (string) $user->avatar_disk;
        $path = (string) $user->avatar_path;

        $user->forceFill(['avatar_disk' => null, 'avatar_path' => null, 'avatar_version' => null])->save();
        $this->mediaDisk->discard($disk, $path);
        $this->forget();
    }

    /**
     * Picture URL of every person who has one, by id, as the viewer may see
     * them. Read from a short cache (cleared on every change).
     *
     * @return array<int, string>
     */
    public function map(?User $viewer): array
    {
        try {
            /** @var array<int, string> $all */
            $all = Cache::remember(self::MAP_CACHE_KEY, 600, fn (): array => User::query()
                ->whereNotNull('avatar_path')
                ->whereNotNull('avatar_disk')
                ->get(['id', 'avatar_disk', 'avatar_path', 'avatar_version'])
                ->mapWithKeys(fn (User $user): array => [$user->id => $user->avatarUrl()])
                ->filter()
                ->all());
        } catch (Throwable $exception) {
            // An extra of every page: never the reason a page fails (e.g. not migrated yet).
            report($exception);

            return [];
        }

        if ($all === []) {
            return [];
        }

        $protected = app(ProtectedAccounts::class);

        return array_filter(
            $all,
            fn (int $id): bool => ! $protected->hides($id, $viewer),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function forget(): void
    {
        Cache::forget(self::MAP_CACHE_KEY);
    }

    private function folderName(User $user): string
    {
        $name = MediaPaths::clean($user->name, 60);

        return ($name !== '' ? $name.' ' : '').'('.$user->id.')';
    }

    /**
     * The upload decoded, cropped square, scaled and written as a new JPEG
     * in a temporary file.
     *
     * @throws ValidationException
     */
    private function normalized(UploadedFile $file): string
    {
        $invalid = fn (): ValidationException => ValidationException::withMessages([
            'avatar' => 'No pudimos leer esa imagen. Usa una foto JPG, PNG o WebP.',
        ]);

        $path = $file->getRealPath();
        $info = $path === false ? false : @getimagesize($path);

        if ($info === false || $info[0] < 16 || $info[1] < 16 || $info[0] > 8000 || $info[1] > 8000) {
            throw $invalid();
        }

        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if ($source === false) {
            throw $invalid();
        }

        $source = $this->upright($source, $path, $info[2]);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);

        // Transparent areas become white instead of black.
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled(
            $target, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            self::SIZE, self::SIZE,
            $side, $side,
        );

        $temporary = tempnam(sys_get_temp_dir(), 'user-avatar-');

        if ($temporary === false || ! imagejpeg($target, $temporary, 86)) {
            throw $invalid();
        }

        return $temporary;
    }

    /**
     * A phone photo turned as its EXIF orientation says (it is dropped
     * afterwards, so the pixels must already be upright).
     */
    private function upright(\GdImage $image, string $path, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }
}
