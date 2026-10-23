import { usePage } from '@inertiajs/vue3';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { joinPresence, realtimeState } from '@/lib/realtime';
import type { RealtimeConfig } from '@/lib/realtime';

/**
 * "En línea" in the internal chat: who has the application open right now,
 * learned from the presence channels the server lists for the user
 * (`realtime.presence_channels`). Each channel only gathers people who may
 * chat with each other and only reports ids, so names keep coming from the
 * pages. It is independent from the declared availability (Disponible /
 * No disponible), which is an operational state, not a connection.
 *
 * One connection per tab: useTeamPresenceConnection() runs in the
 * authenticated layout; components read it with useTeamPresence().
 */

const membersByChannel = ref<Record<string, number[]>>({});
const stops = new Map<string, () => void>();

const onlineIds = computed(
    () => new Set(Object.values(membersByChannel.value).flat()),
);

function sync(channels: string[]): void {
    stops.forEach((stop, channel) => {
        if (!channels.includes(channel)) {
            stop();
            stops.delete(channel);
            const { [channel]: _left, ...rest } = membersByChannel.value;
            membersByChannel.value = rest;
        }
    });

    channels
        .filter((channel) => !stops.has(channel))
        .forEach((channel) => {
            stops.set(
                channel,
                joinPresence(channel, (ids) => {
                    membersByChannel.value = {
                        ...membersByChannel.value,
                        [channel]: ids,
                    };
                }),
            );
        });
}

/**
 * Joins (and leaves) the presence channels that match the user's current
 * access, following the shared "realtime" prop after every visit.
 */
export function useTeamPresenceConnection(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const page = usePage();

    watch(
        () =>
            (
                (page.props.realtime as RealtimeConfig | null | undefined)
                    ?.presence_channels ?? []
            ).join('|'),
        (key) => sync(key === '' ? [] : key.split('|')),
        { immediate: true },
    );

    onScopeDispose(() => sync([]));
}

/**
 * Whether a person is online. Without a live connection nothing is known,
 * so nobody is shown as online.
 */
export function useTeamPresence(): { isOnline: (userId: number) => boolean } {
    return {
        isOnline: (userId: number): boolean =>
            realtimeState.value === 'connected' && onlineIds.value.has(userId),
    };
}
