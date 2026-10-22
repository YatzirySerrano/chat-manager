<?php

namespace App\Enums\TeamChat;

/**
 * Changes of a group recorded as system lines inside the thread.
 */
enum TeamChatSystemEvent: string
{
    case GroupCreated = 'group_created';
    case GroupRenamed = 'group_renamed';
    case DescriptionChanged = 'description_changed';
    case ParticipantAdded = 'participant_added';
    case ParticipantRemoved = 'participant_removed';
    case ParticipantLeft = 'participant_left';
    case AdminGranted = 'admin_granted';
    case AdminRevoked = 'admin_revoked';
    case GroupArchived = 'group_archived';
    case GroupUnarchived = 'group_unarchived';

    /**
     * Sentence shown in the thread. The actor and target are names already
     * resolved for display ("Usuario eliminado" for deleted accounts).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function sentence(string $actor, ?string $target, array $metadata = []): string
    {
        $target ??= 'Usuario eliminado';

        return match ($this) {
            self::GroupCreated => "{$actor} creó el grupo.",
            self::GroupRenamed => "{$actor} cambió el nombre del grupo a «".((string) ($metadata['name'] ?? '')).'».',
            self::DescriptionChanged => "{$actor} actualizó la descripción del grupo.",
            self::ParticipantAdded => "{$actor} agregó a {$target}.",
            self::ParticipantRemoved => "{$actor} retiró a {$target} del grupo.",
            self::ParticipantLeft => "{$actor} salió del grupo.",
            self::AdminGranted => ($metadata['automatic'] ?? false) === true
                ? "{$target} ahora administra el grupo porque no quedaban administradores."
                : "{$actor} nombró a {$target} administrador del grupo.",
            self::AdminRevoked => "{$actor} quitó a {$target} como administrador del grupo.",
            self::GroupArchived => "{$actor} archivó el grupo. Ya no se pueden enviar mensajes.",
            self::GroupUnarchived => "{$actor} reactivó el grupo.",
        };
    }
}
