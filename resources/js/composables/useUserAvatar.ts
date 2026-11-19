import { usePage } from '@inertiajs/vue3';

/**
 * The profile picture a teammate chose («Ajustes → Perfil»), from the map
 * every page shares (`userAvatars`, id => URL). Null when the person has
 * none, or when the viewer may not see that account: the avatar then shows
 * the initials.
 */
export function userAvatarUrl(
    userId: number | null | undefined,
): string | null {
    if (userId === null || userId === undefined) {
        return null;
    }

    const avatars = usePage().props.userAvatars as
        | Record<string, string>
        | undefined;

    return avatars?.[String(userId)] ?? null;
}
