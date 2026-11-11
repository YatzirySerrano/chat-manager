import { onScopeDispose, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * One clock for every countdown of the app (SLA deadlines, minimum wait of
 * the queue), anchored to the server.
 *
 * The server sends its current time with the data (`syncServerClock(iso)`);
 * from then on the clock advances with the device's monotonic timer, so a
 * wrong device clock or time zone never moves a deadline. A single shared
 * timer ticks once per second, only while some component is counting, and
 * no request is ever made to refresh a countdown.
 */

/** Server time (epoch ms) at the last synchronization. */
let anchorServer = Date.now();

/** Monotonic reading (performance.now()) taken at that same moment. */
let anchorMonotonic = monotonic();

const now = ref(anchorServer);

let subscribers = 0;
let timer: ReturnType<typeof setInterval> | null = null;
let listening = false;

function monotonic(): number {
    return typeof performance === 'undefined' ? Date.now() : performance.now();
}

function tick(): void {
    now.value = anchorServer + (monotonic() - anchorMonotonic);
}

function onVisibilityChange(): void {
    // Timers are throttled in background tabs: catch up at once on return.
    if (document.visibilityState === 'visible') {
        tick();
    }
}

function start(): void {
    if (typeof window === 'undefined' || timer !== null) {
        return;
    }

    tick();
    timer = setInterval(tick, 1000);

    if (!listening) {
        document.addEventListener('visibilitychange', onVisibilityChange);
        listening = true;
    }
}

function stop(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }

    if (listening) {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        listening = false;
    }
}

/**
 * Anchor the clock to the server time that came with fresh data (an ISO
 * 8601 string). Invalid or missing values are ignored.
 */
export function syncServerClock(serverTime: string | null | undefined): void {
    if (!serverTime) {
        return;
    }

    const parsed = Date.parse(serverTime);

    if (Number.isNaN(parsed)) {
        return;
    }

    anchorServer = parsed;
    anchorMonotonic = monotonic();
    tick();
}

/**
 * The current server time in epoch milliseconds, updated every second
 * while the calling component (or effect scope) is alive.
 */
export function useServerNow(): Readonly<Ref<number>> {
    subscribers++;
    start();

    onScopeDispose(() => {
        subscribers = Math.max(0, subscribers - 1);

        if (subscribers === 0) {
            stop();
        }
    });

    return now;
}

/**
 * Whole seconds from the server's "now" until the given instant (negative
 * once it passed); null when there is no instant.
 */
export function secondsUntil(
    instant: string | null | undefined,
    nowMs: number,
): number | null {
    if (!instant) {
        return null;
    }

    const target = Date.parse(instant);

    return Number.isNaN(target) ? null : Math.ceil((target - nowMs) / 1000);
}

/** `02:35`, or `1:02:35` from one hour on (never negative). */
export function formatCountdown(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const rest = total % 60;
    const pad = (value: number): string => String(value).padStart(2, '0');

    return hours > 0
        ? `${hours}:${pad(minutes)}:${pad(rest)}`
        : `${pad(minutes)}:${pad(rest)}`;
}
