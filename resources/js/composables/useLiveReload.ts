import { router } from '@inertiajs/vue3';
import { computed, onScopeDispose, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import {
    afterUserVisits,
    onReconnect,
    realtimeState,
    registerFallbackInterval,
    subscribe,
} from '@/lib/realtime';
import type { LiveChannel, RealtimePayload } from '@/lib/realtime';

export type LiveReloadOptions = {
    /** Channels and events that invalidate the page data. */
    channels: MaybeRefOrGetter<LiveChannel[]>;
    /** Props reloaded (Inertia partial reload). */
    only: string[];
    /** Refresh interval while real-time is unavailable (default 20 s). */
    fallbackIntervalMs?: number;
    /**
     * Slow safety refresh while connected (default 2 min, null disables it):
     * the WebSocket is never the only source of truth.
     */
    safetyIntervalMs?: number | null;
    /** Signals arriving within this window cause a single reload (default 400 ms). */
    debounceMs?: number;
    /**
     * Longest wait for a reload under a steady stream of signals (default
     * five times debounceMs, at least 2 s), so busy inboxes still refresh.
     */
    maxWaitMs?: number;
    /** Pause everything (for example while a dialog edits the data). */
    enabled?: MaybeRefOrGetter<boolean>;
    /** Ignore some signals (return false), e.g. those about other records. */
    filter?: (
        event: string,
        payload: RealtimePayload,
        channel: string,
    ) => boolean;
};

/**
 * Keeps the given props of the current page fresh:
 *
 * - any listed signal schedules one debounced partial reload (signals that
 *   arrive while a reload is running cause one more reload afterwards; a
 *   continuous stream still reloads at least every maxWaitMs);
 * - while the connection is down, real-time is disabled or there is no
 *   channel to listen to, the page refreshes every fallbackIntervalMs;
 * - when the connection comes back (or channels are authorized again after
 *   an access change) the page reloads once to recover missed signals;
 * - hidden tabs skip refreshes and catch up once when shown again;
 * - a reload waits for the user's own visit in flight (afterUserVisits), so
 *   it never races with the operation that caused the signal.
 */
export function useLiveReload(options: LiveReloadOptions): void {
    const fallbackIntervalMs = options.fallbackIntervalMs ?? 20_000;
    const safetyIntervalMs =
        options.safetyIntervalMs === undefined
            ? 120_000
            : options.safetyIntervalMs;
    const debounceMs = options.debounceMs ?? 400;
    const maxWaitMs = Math.max(
        options.maxWaitMs ?? debounceMs * 5,
        debounceMs,
        2_000,
    );

    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let firstScheduledAt: number | null = null;
    let intervalTimer: ReturnType<typeof setInterval> | null = null;
    let reloading = false;
    let pending = false;
    let missedWhileHidden = false;
    let disposed = false;

    const isEnabled = (): boolean => toValue(options.enabled) ?? true;

    function reload(): void {
        if (disposed || !isEnabled()) {
            return;
        }

        if (document.hidden) {
            missedWhileHidden = true;

            return;
        }

        if (reloading) {
            pending = true;

            return;
        }

        reloading = true;
        afterUserVisits(() => {
            if (disposed) {
                reloading = false;

                return;
            }

            router.reload({
                only: options.only,
                onFinish: () => {
                    reloading = false;

                    if (pending) {
                        pending = false;
                        schedule();
                    }
                },
            });
        });
    }

    function schedule(): void {
        const now = Date.now();

        if (debounceTimer) {
            // A steady stream of signals must not postpone the reload forever.
            if (
                firstScheduledAt !== null &&
                now - firstScheduledAt >= maxWaitMs
            ) {
                return;
            }

            clearTimeout(debounceTimer);
        } else {
            firstScheduledAt = now;
        }

        const elapsed = now - (firstScheduledAt ?? now);

        debounceTimer = setTimeout(
            () => {
                debounceTimer = null;
                firstScheduledAt = null;
                reload();
            },
            Math.min(debounceMs, Math.max(0, maxWaitMs - elapsed)),
        );
    }

    /* Subscriptions follow the (reactive) list of channels. */
    let stops: (() => void)[] = [];
    const channels = computed(() => toValue(options.channels));

    watch(
        () => JSON.stringify(channels.value),
        () => {
            stops.forEach((stop) => stop());
            stops = channels.value.flatMap((channel) =>
                channel.events.map((event) =>
                    subscribe(channel.name, event, (payload) => {
                        if (
                            !options.filter ||
                            options.filter(event, payload, channel.name)
                        ) {
                            schedule();
                        }
                    }),
                ),
            );
        },
        { immediate: true },
    );

    /* Periodic refresh: fallback while not live, safety net while live. */
    const isLive = computed(
        () => realtimeState.value === 'connected' && channels.value.length > 0,
    );
    let unregisterFallback: (() => void) | null = null;

    watch(
        isLive,
        (live) => {
            if (intervalTimer) {
                clearInterval(intervalTimer);
                intervalTimer = null;
            }

            unregisterFallback?.();
            unregisterFallback = live
                ? null
                : registerFallbackInterval(fallbackIntervalMs);

            const interval = live ? safetyIntervalMs : fallbackIntervalMs;

            if (interval !== null && interval > 0) {
                intervalTimer = setInterval(reload, interval);
            }
        },
        { immediate: true },
    );

    const stopReconnect = onReconnect(() => reload());

    const onVisibility = (): void => {
        if (!document.hidden && missedWhileHidden) {
            missedWhileHidden = false;
            reload();
        }
    };

    document.addEventListener('visibilitychange', onVisibility);

    onScopeDispose(() => {
        disposed = true;
        stops.forEach((stop) => stop());
        stopReconnect();
        unregisterFallback?.();
        document.removeEventListener('visibilitychange', onVisibility);

        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        if (intervalTimer) {
            clearInterval(intervalTimer);
        }
    });
}
