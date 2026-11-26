<?php

namespace App\Actions\WebChat;

use App\Enums\AuditAction;
use App\Exceptions\StorageUnavailable;
use App\Http\Requests\Admin\WebChatWidgetRequest;
use App\Models\User;
use App\Models\WebChatWidget;
use App\Services\AuditLogger;
use App\Services\Storage\LocalMedia;
use App\Services\Storage\MediaDisk;
use App\Services\Storage\MediaPaths;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves the widget settings of a web chat connection. Nothing is written
 * (no save, no audit entry) when the settings stay the same. The avatar and
 * the floating button's own icon (the same pipeline, self::IMAGES) are
 * kept in the private media disk (verified write, MediaDisk) and only
 * ever served inlined. The widget has no disk column: an avatar stored on
 * a disk other than "local" is recorded as "{disk}:{path}" in avatar_path
 * (avatarLocation()), so it keeps resolving from that disk after
 * MEDIA_DISK changes; older values (a bare path) are looked up on the
 * media disk, then on "local" (avatarDisk()).
 */
class UpdateWidgetSettings
{
    public function __construct(private AuditLogger $audit, private MediaDisk $mediaDisk) {}

    /**
     * The images of the widget: the form field, the column that keeps it,
     * the "remove" flag and the word used in its file name.
     *
     * @var array<string, array{column: string, remove: string, label: string}>
     */
    private const IMAGES = [
        'avatar' => ['column' => 'avatar_path', 'remove' => 'remove_avatar', 'label' => ''],
        'launcher_image' => ['column' => 'launcher_icon_path', 'remove' => 'remove_launcher_image', 'label' => ' boton'],
    ];

    /**
     * @param  array{settings: array<string, mixed>, avatar: UploadedFile|null, remove_avatar: bool, launcher_image?: UploadedFile|null, remove_launcher_image?: bool}  $data
     * @return bool Whether anything changed.
     */
    public function handle(WebChatWidget $widget, array $data, User $actor): bool
    {
        /** @var array<string, string> $stored New images written to the media disk, by field. */
        $stored = [];
        /** @var array<string, string|null> $previous The stored value of each image before saving. */
        $previous = [];

        try {
            foreach (self::IMAGES as $field => $image) {
                $previous[$field] = $widget->getAttribute($image['column']);
                $file = $data[$field] ?? null;

                if ($file instanceof UploadedFile) {
                    $stored[$field] = $this->storeImage($widget, $file, $field, $image['label']);
                }
            }

            $changed = $this->save($widget, $data, $actor, $stored);
        } catch (Throwable $exception) {
            foreach ($stored as $value) {
                $this->mediaDisk->discard(...self::avatarLocation($value));
            }

            throw $exception;
        }

        foreach (self::IMAGES as $field => $image) {
            $current = $widget->getAttribute($image['column']);

            if (isset($stored[$field]) && ! $changed) {
                $this->mediaDisk->discard(...self::avatarLocation($stored[$field]));
            }

            if ($changed && is_string($previous[$field]) && $previous[$field] !== $current) {
                $this->mediaDisk->discard(...self::avatarLocation($previous[$field]));
            }
        }

        return $changed;
    }

    /**
     * Writes an image of the widget to the media disk with a readable name
     * (the connection it belongs to and when it was set) and returns the
     * value to keep in its column ("{disk}:{path}" off the "local" disk).
     *
     * @throws ValidationException when the media disk cannot keep it.
     */
    private function storeImage(WebChatWidget $widget, UploadedFile $file, string $field, string $label): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        try {
            $owner = MediaPaths::clean($widget->connection()->first()?->name, 60);
            $stored = $this->mediaDisk->putNamedFile(
                (string) $file->getRealPath(),
                'Chat web/Imagenes del widget',
                ($owner !== '' ? $owner : 'Widget').$label.' '.now()->format('Y-m-d H.i.s'),
                $extension,
            );

            return $stored['disk'] === 'local' ? $stored['path'] : $stored['disk'].':'.$stored['path'];
        } catch (StorageUnavailable $exception) {
            $exception->report();

            throw ValidationException::withMessages([$field => 'No se pudo guardar la imagen porque el almacenamiento de archivos no está disponible. Inténtalo de nuevo en unos minutos.']);
        }
    }

    /**
     * @param  array{settings: array<string, mixed>, avatar: UploadedFile|null, remove_avatar: bool, launcher_image?: UploadedFile|null, remove_launcher_image?: bool}  $data
     * @param  array<string, string>  $stored
     */
    private function save(WebChatWidget $widget, array $data, User $actor, array $stored): bool
    {
        return DB::transaction(function () use ($widget, $data, $actor, $stored): bool {
            $widget->fill($data['settings']);

            foreach (self::IMAGES as $field => $image) {
                if (isset($stored[$field])) {
                    $widget->setAttribute($image['column'], $stored[$field]);
                } elseif (($data[$image['remove']] ?? false) === true) {
                    $widget->setAttribute($image['column'], null);
                }
            }

            $changes = $this->changes($widget);

            if ($changes === []) {
                return false;
            }

            $widget->save();

            $this->audit->record(AuditAction::WebChatWidgetUpdated, $widget->connection, [
                'changes' => $changes,
            ], $actor);

            return true;
        });
    }

    /**
     * Disk where new avatars are written (the media disk).
     */
    public static function disk(): string
    {
        return app(MediaDisk::class)->name();
    }

    /**
     * Disk and path of a stored avatar value: "{disk}:{path}" when the disk
     * was recorded, else the old lookup (avatarDisk()). Generated paths
     * ("web-chat/avatars/…") never contain a colon.
     *
     * @return array{0: string, 1: string}
     */
    public static function avatarLocation(string $value): array
    {
        if (preg_match('/^([A-Za-z0-9_.-]+):(.+)$/', $value, $match) === 1) {
            return [$match[1], $match[2]];
        }

        return [self::avatarDisk($value), $value];
    }

    /**
     * Disk that holds an avatar saved without its disk name (before it was
     * recorded): an avatar saved before MEDIA_DISK changed is still on
     * "local", so it is looked up there when the media disk does not have it.
     */
    public static function avatarDisk(string $path): string
    {
        $current = self::disk();

        try {
            if ($current === 'local' || Storage::disk($current)->exists($path)) {
                return $current;
            }
        } catch (Throwable) {
            return $current;
        }

        return Storage::disk('local')->exists($path) ? 'local' : $current;
    }

    /**
     * A stored image of the widget (avatar or button icon) inlined as a
     * data URI, or null when there is none or its bytes are not a PNG, JPEG
     * or WebP image.
     */
    public static function imageDataUri(?string $value): ?string
    {
        $bytes = self::avatarBytes($value);

        if ($bytes === null) {
            return null;
        }

        $type = WebChatWidgetRequest::imageTypeOf(substr($bytes, 0, 16));

        return $type === null ? null : 'data:'.$type.';base64,'.base64_encode($bytes);
    }

    /**
     * The avatar's bytes from whichever disk holds it, or null.
     */
    public static function avatarBytes(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            [$diskName, $path] = self::avatarLocation($path);
            $location = app(LocalMedia::class)->open($diskName, $path);

            return $location === null ? null : $location['filesystem']->get($location['path']);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Old and new values of the settings being changed (the avatar only says
     * whether it was replaced or removed).
     *
     * @return array<string, mixed>
     */
    private function changes(WebChatWidget $widget): array
    {
        $changes = [];

        foreach (array_keys($widget->getDirty()) as $attribute) {
            if ($attribute === 'avatar_path' || $attribute === 'launcher_icon_path') {
                $changes[$attribute === 'avatar_path' ? 'avatar' : 'launcher_image'] = $widget->getAttribute($attribute) === null ? 'removed' : 'replaced';

                continue;
            }

            if ($attribute === 'allowed_origins') {
                /** @var list<string> $old */
                $old = $widget->getOriginal('allowed_origins') ?? [];
                $new = $widget->allowed_origins;

                $added = array_values(array_diff($new, $old));
                $removed = array_values(array_diff($old, $new));

                if ($added === [] && $removed === []) {
                    continue;
                }

                $changes['allowed_origins'] = ['added' => $added, 'removed' => $removed];

                continue;
            }

            $old = $widget->getOriginal($attribute);
            $new = $widget->getAttribute($attribute);

            if ($old == $new) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $old instanceof BackedEnum ? $old->value : $old,
                'new' => $new instanceof BackedEnum ? $new->value : $new,
            ];
        }

        return $changes;
    }
}
