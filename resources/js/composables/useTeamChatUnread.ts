import { router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, onScopeDispose } from 'vue';
import type { ComputedRef } from 'vue';
import { useUserChannel } from '@/composables/useUserChannel';
import { afterUserVisits, onReconnect } from '@/lib/realtime';

/**
 * Unread internal chat messages for the sidebar badge (shared by the server
 * as `teamChatUnread`, muted chats excluded). A `.team-chat.changed` signal
 * on the personal channel refreshes only that prop; the chat page keeps its
 * own list fresh and refreshes the badge together with it.
 *
 * Signals sent while the connection was down are lost, so the badge is
 * refreshed once when it comes back (or after channels are authorized
 * again), and a refresh waits for the user's own visit in flight.
 */
export function useTeamChatUnread(): ComputedRef<number | null> {
    const page = usePage();

    const refresh = useDebounceFn(() => {
        if (
            page.component === 'team-chat/Index' ||
            page.props.teamChatUnread === null
        ) {
            return;
        }

        afterUserVisits(() => router.reload({ only: ['teamChatUnread'] }));
    }, 800);

    useUserChannel({ 'team-chat.changed': () => void refresh() });
    onScopeDispose(onReconnect(() => void refresh()));

    return computed(() => {
        const value = page.props.teamChatUnread;

        return typeof value === 'number' ? value : null;
    });
}
