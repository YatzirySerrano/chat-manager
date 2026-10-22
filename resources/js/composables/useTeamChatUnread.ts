import { router, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useUserChannel } from '@/composables/useUserChannel';

/**
 * Unread internal chat messages for the sidebar badge (shared by the server
 * as `teamChatUnread`, muted chats excluded). A `.team-chat.changed` signal
 * on the personal channel refreshes only that prop; the chat page keeps its
 * own list fresh and refreshes the badge together with it.
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

        router.reload({ only: ['teamChatUnread'] });
    }, 800);

    useUserChannel({ 'team-chat.changed': () => void refresh() });

    return computed(() => {
        const value = page.props.teamChatUnread;

        return typeof value === 'number' ? value : null;
    });
}
