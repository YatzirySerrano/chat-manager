import { router } from '@inertiajs/vue3';

/**
 * Reloads caused by real-time signals wait for the user's own visit in
 * flight (an operation such as accepting or transferring, or a navigation).
 *
 * The server announces a change right after committing it, so the signal
 * usually reaches the tab while the request that made the change is still
 * finishing. A partial reload sent at that moment races with the redirect of
 * that request for the one-time session flash (the success toast) and for
 * the page state: the toast could be shown twice or lost. Waiting for the
 * visit to finish avoids the race; the reload still runs right after it.
 */

const MAX_WAIT_MS = 10_000;

let activeVisits = 0;
let installed = false;
let waiting: (() => void)[] = [];
let waitTimer: ReturnType<typeof setTimeout> | null = null;

function flush(): void {
    if (waitTimer) {
        clearTimeout(waitTimer);
        waitTimer = null;
    }

    const callbacks = waiting;
    waiting = [];
    callbacks.forEach((callback) => callback());
}

/** Starts following the user's visits (called once when the app boots). */
export function trackUserVisits(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;

    router.on('start', (event) => {
        if (!event.detail.visit.async) {
            activeVisits++;
        }
    });

    router.on('finish', (event) => {
        if (event.detail.visit.async) {
            return;
        }

        activeVisits = Math.max(0, activeVisits - 1);

        if (activeVisits === 0) {
            flush();
        }
    });
}

/**
 * Runs the callback now, or as soon as the user's visit in flight finishes
 * (at most MAX_WAIT_MS later, so a stuck visit never blocks updates).
 */
export function afterUserVisits(callback: () => void): void {
    trackUserVisits();

    if (activeVisits === 0) {
        callback();

        return;
    }

    waiting.push(callback);

    if (!waitTimer) {
        waitTimer = setTimeout(() => {
            activeVisits = 0;
            flush();
        }, MAX_WAIT_MS);
    }
}
