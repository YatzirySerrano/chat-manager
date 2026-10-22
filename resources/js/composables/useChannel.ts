import { onScopeDispose, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { subscribe } from '@/lib/realtime';
import type { RealtimeHandler } from '@/lib/realtime';

/**
 * Listens to events of a private channel while the component is mounted.
 * The channel name may be reactive (or null to listen to nothing): when it
 * changes the old channel is left. Event names may be written with or
 * without the leading dot (".inbox.changed" or "inbox.changed").
 *
 * The connection subscribes again by itself after a reconnection and after
 * an access change; payloads are invalidation signals, so handlers should
 * ask the server for the current data.
 */
export function useChannel(
    name: MaybeRefOrGetter<string | null | undefined>,
    handlers: Record<string, RealtimeHandler>,
): void {
    let stops: (() => void)[] = [];

    const stopAll = (): void => {
        stops.forEach((stop) => stop());
        stops = [];
    };

    watch(
        () => toValue(name) ?? null,
        (channel) => {
            stopAll();

            if (!channel) {
                return;
            }

            stops = Object.entries(handlers).map(([event, handler]) =>
                subscribe(channel, event, handler),
            );
        },
        { immediate: true },
    );

    onScopeDispose(stopAll);
}
