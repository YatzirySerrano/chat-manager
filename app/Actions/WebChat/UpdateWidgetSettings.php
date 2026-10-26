<?php

namespace App\Actions\WebChat;

use App\Enums\AuditAction;
use App\Exceptions\StorageUnavailable;
use App\Models\User;
use App\Models\WebChatWidget;
use App\Services\AuditLogger;
use App\Services\Storage\MediaDisk;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves the widget settings of a web chat connection. Nothing is written
 * (no save, no audit entry) when the settings stay the same. The avatar is
 * kept in the private media disk (verified write, MediaDisk) and only
 * ever served inlined; avatars saved before MEDIA_DISK changed are still
 * found on "local" (avatarDisk()).
 */
class UpdateWidgetSettings
{
    public function __construct(private AuditLogger $audit, private MediaDisk $mediaDisk) {}

    /**
     * @param  array{settings: array<string, mixed>, avatar: UploadedFile|null, remove_avatar: bool}  $data
     * @return bool Whether anything changed.
     */
    public function handle(WebChatWidget $widget, array $data, User $actor): bool
    {
        $previousAvatar = $widget->avatar_path;
        $previousDisk = $previousAvatar !== null ? self::avatarDisk($previousAvatar) : null;
        $storedAvatar = null;

        if ($data['avatar'] !== null) {
            $extension = match ($data['avatar']->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            try {
                $storedAvatar = $this->mediaDisk->putFile((string) $data['avatar']->getRealPath(), 'web-chat/avatars', $widget->id.'-'.Str::random(16).'.'.$extension)['path'];
            } catch (StorageUnavailable $exception) {
                $exception->report();

                throw ValidationException::withMessages(['avatar' => 'No se pudo guardar la imagen porque el almacenamiento de archivos no está disponible. Inténtalo de nuevo en unos minutos.']);
            }
        }

        try {
            $changed = $this->save($widget, $data, $actor, $storedAvatar);
        } catch (Throwable $exception) {
            if (is_string($storedAvatar)) {
                $this->mediaDisk->discard($this->mediaDisk->name(), $storedAvatar);
            }

            throw $exception;
        }

        if (is_string($storedAvatar) && ! $changed) {
            $this->mediaDisk->discard($this->mediaDisk->name(), $storedAvatar);
        }

        if ($changed && $previousAvatar !== null && $previousDisk !== null && $previousAvatar !== $widget->avatar_path) {
            $this->mediaDisk->discard($previousDisk, $previousAvatar);
        }

        return $changed;
    }

    /**
     * @param  array{settings: array<string, mixed>, avatar: UploadedFile|null, remove_avatar: bool}  $data
     */
    private function save(WebChatWidget $widget, array $data, User $actor, ?string $storedAvatar): bool
    {

        return DB::transaction(function () use ($widget, $data, $actor, $storedAvatar): bool {
            $widget->fill($data['settings']);

            if (is_string($storedAvatar)) {
                $widget->avatar_path = $storedAvatar;
            } elseif ($data['remove_avatar']) {
                $widget->avatar_path = null;
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
     * Disk that holds an existing avatar. The widget stores no disk name:
     * an avatar saved before MEDIA_DISK changed is still on "local", so it
     * is looked up there when the media disk does not have it.
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
     * The avatar's bytes from whichever disk holds it, or null.
     */
    public static function avatarBytes(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk(self::avatarDisk($path));

            return $disk->exists($path) ? $disk->get($path) : null;
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
            if ($attribute === 'avatar_path') {
                $changes['avatar'] = $widget->avatar_path === null ? 'removed' : 'replaced';

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
