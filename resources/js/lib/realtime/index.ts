import { router, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import { configureRealtime, resubscribeAll, subscribe } from './echo';
import type { RealtimeConfig } from './types';
import { afterUserVisits, trackUserVisits } from './visits';

export {
    configureRealtime,
    joinPresence,
    onReconnect,
    realtimeState,
    resubscribeAll,
    subscribe,
} from './echo';
export { fallbackSeconds, registerFallbackInterval } from './fallback';
export { afterUserVisits } from './visits';
export type * from './types';

const ACCESS_RELOAD_DEBOUNCE_MS = 300;

let personalChannel: string | null = null;
let stopAccessListener: (() => void) | null = null;
let accessTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * The user's role, permissions, departments or state changed: reload the
 * whole current page (permissions, navigation and data are authorized
 * again; a page that is no longer allowed redirects, a deleted account is
 * signed out) and then authorize every channel again. Prefetched pages kept
 * in memory carry the previous permissions, so they are dropped at once.
 */
function handleAccessChanged(): void {
    router.flushAll();

    if (accessTimer) {
        clearTimeout(accessTimer);
    }

    accessTimer = setTimeout(() => {
        accessTimer = null;
        afterUserVisits(() =>
            router.reload({ onFinish: () => resubscribeAll() }),
        );
    }, ACCESS_RELOAD_DEBOUNCE_MS);
}

function listenToAccessChanges(channel: string | null): void {
    if (channel === personalChannel) {
        return;
    }

    stopAccessListener?.();
    stopAccessListener = null;
    personalChannel = channel;

    if (channel) {
        stopAccessListener = subscribe(
            channel,
            'access.changed',
            handleAccessChanged,
        );
    }
}

function apply(config: RealtimeConfig | null): void {
    configureRealtime(config);
    listenToAccessChanges(config?.enabled ? config.user_channel : null);
}

/**
 * Connects the tab to the real-time server with the configuration shared by
 * the server, and keeps it in sync after every visit (signing out closes
 * the connection, an access change reloads and re-authorizes channels).
 */
export function initializeRealtime(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const page = usePage();
    trackUserVisits();

    watch(
        () => (page.props?.realtime ?? null) as RealtimeConfig | null,
        (config) => apply(config),
        { immediate: true, deep: true },
    );
}
