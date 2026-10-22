import { usePage } from '@inertiajs/vue3';
import { useChannel } from '@/composables/useChannel';
import type { RealtimeConfig, RealtimeHandler } from '@/lib/realtime';

/**
 * Personal channel of the signed-in user ("users.{id}"): inbox changes of
 * their conversations, notifications, finished exports and imports, access
 * and availability changes.
 */
export function useUserChannel(
    handlers: Record<string, RealtimeHandler>,
): void {
    const page = usePage();

    useChannel(() => {
        const config = page.props.realtime as RealtimeConfig | undefined;

        return config?.enabled ? config.user_channel : null;
    }, handlers);
}

/** Name of the personal channel, or null when real-time is disabled. */
export function userChannelName(): string | null {
    const config = usePage().props.realtime as RealtimeConfig | undefined;

    return config?.enabled ? config.user_channel : null;
}

/**
 * Inbox channels matching the user's current inbox (global, their
 * departments or the queues they may accept from), as decided by the
 * server for their permissions; empty when real-time is disabled.
 */
export function inboxChannelNames(): string[] {
    const config = usePage().props.realtime as RealtimeConfig | undefined;

    return config?.enabled ? config.inbox_channels : [];
}
