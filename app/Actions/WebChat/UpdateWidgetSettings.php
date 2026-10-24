<?php

namespace App\Actions\WebChat;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\WebChatWidget;
use App\Services\AuditLogger;
use BackedEnum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves the widget settings of a web chat connection. Nothing is written
 * (no save, no audit entry) when the settings stay the same. The avatar is
 * kept in the private media disk and only ever served inlined.
 */
class UpdateWidgetSettings
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{settings: array<string, mixed>, avatar: UploadedFile|null, remove_avatar: bool}  $data
     * @return bool Whether anything changed.
     */
    public function handle(WebChatWidget $widget, array $data, User $actor): bool
    {
        $disk = Storage::disk(self::disk());
        $previousAvatar = $widget->avatar_path;
        $storedAvatar = null;

        if ($data['avatar'] !== null) {
            $extension = match ($data['avatar']->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $storedAvatar = $data['avatar']->storeAs('web-chat/avatars', $widget->id.'-'.Str::random(16).'.'.$extension, self::disk());
        }

        $changed = DB::transaction(function () use ($widget, $data, $actor, $storedAvatar): bool {
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

        if ($changed && $previousAvatar !== null && $previousAvatar !== $widget->avatar_path) {
            $disk->delete($previousAvatar);
        }

        return $changed;
    }

    public static function disk(): string
    {
        return (string) config('filesystems.media_disk', 'local');
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
