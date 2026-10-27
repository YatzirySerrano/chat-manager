/**
 * Coordination of notification alerts between the tabs of this browser
 * (same origin, same storage). Every tab keeps its own bell and toasts, but:
 *
 * - the sound of a notification plays once, in the first tab able to play
 *   it that claims it (a tab whose audio is still blocked never claims, so
 *   it cannot silence the others), and no tab plays another non-urgent one
 *   within SOUND_COOLDOWN_MS (a burst after a reconnection, or two tabs
 *   refreshing at once, sound once);
 * - an internal chat the user is looking at in one tab (visible and
 *   focused) silences the alerts of that same chat in the others.
 *
 * The state lives in localStorage and is updated under a Web Lock when the
 * browser has them, so two tabs never claim the same sound. Without storage
 * (private mode, blocked site data) each tab simply behaves on its own.
 */

const SOUND_KEY = 'chat-manager.notification-sound';
const VIEWED_THREADS_KEY = 'chat-manager.viewed-team-threads';
const LOCK_NAME = 'chat-manager.notification-alerts';

/** No second non-urgent sound, from any tab, within this time of the previous one. */
const SOUND_COOLDOWN_MS = 2_000;
/** How long a claimed notification version is remembered. */
const CLAIM_TTL_MS = 10 * 60_000;
/** A viewed chat not confirmed for this long belongs to a closed or crashed tab. */
const VIEWED_TTL_MS = 90_000;

/** How often a tab showing a chat confirms it (well below VIEWED_TTL_MS). */
export const VIEWED_THREAD_HEARTBEAT_MS = 30_000;

type SoundLedger = { at: number; keys: Record<string, number> };
/** The chat each tab is showing to the user, keyed by tab. */
type ViewedThreads = Record<string, { thread: number; at: number }>;

const tabId = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

function read<T>(key: string): T | null {
    try {
        const raw = window.localStorage.getItem(key);

        return raw ? (JSON.parse(raw) as T) : null;
    } catch {
        return null;
    }
}

function write(key: string, value: unknown): void {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, JSON.stringify(value));
        }
    } catch {
        // Storage unavailable: this tab decides alone.
    }
}

function exclusively<T>(task: () => T): Promise<T> {
    const locks =
        typeof navigator !== 'undefined' && 'locks' in navigator
            ? navigator.locks
            : null;

    if (!locks) {
        return Promise.resolve(task());
    }

    return locks.request(LOCK_NAME, () => task()).catch(() => task());
}

/**
 * Whether this tab may play the sound for a batch of fresh notifications,
 * identified by their versions. Only call it from a tab that can play
 * sound right now; the versions are then recorded either way, so another
 * tab handling the same batch stays silent. An urgent batch is not held
 * back by the cooldown (only by a sound already played for it).
 */
export function claimNotificationSound(
    keys: string[],
    urgent = false,
): Promise<boolean> {
    return exclusively(() => {
        const now = Date.now();
        const ledger = read<SoundLedger>(SOUND_KEY);
        const claimed: Record<string, number> = {};

        Object.entries(ledger?.keys ?? {}).forEach(([key, at]) => {
            if (now - at < CLAIM_TTL_MS) {
                claimed[key] = at;
            }
        });

        const alreadyClaimed = keys.some((key) => key in claimed);
        const coolingDown =
            !urgent && now - (ledger?.at ?? 0) < SOUND_COOLDOWN_MS;
        const play = !alreadyClaimed && !coolingDown;

        keys.forEach((key) => {
            claimed[key] = now;
        });
        write(SOUND_KEY, {
            at: play ? now : (ledger?.at ?? 0),
            keys: claimed,
        });

        return play;
    });
}

/** Entries of tabs still alive (confirmed within VIEWED_TTL_MS). */
function liveViewedThreads(now: number): ViewedThreads {
    const live: ViewedThreads = {};

    Object.entries(read<ViewedThreads>(VIEWED_THREADS_KEY) ?? {}).forEach(
        ([tab, entry]) => {
            if (
                typeof entry?.thread === 'number' &&
                now - entry.at < VIEWED_TTL_MS
            ) {
                live[tab] = entry;
            }
        },
    );

    return live;
}

/**
 * The internal chat this tab shows to the user (visible and focused), or
 * null when it shows none or the user is elsewhere. Each tab only updates
 * its own entry; entries of dead tabs expire.
 */
export function announceViewedThread(threadId: number | null): void {
    const now = Date.now();
    const viewed = liveViewedThreads(now);

    if (threadId === null) {
        delete viewed[tabId];
    } else {
        viewed[tabId] = { thread: threadId, at: now };
    }

    write(VIEWED_THREADS_KEY, Object.keys(viewed).length > 0 ? viewed : null);
}

/** Whether another tab shows this internal chat to the user. */
export function isThreadViewedInAnotherTab(threadId: number): boolean {
    return Object.entries(liveViewedThreads(Date.now())).some(
        ([tab, entry]) => tab !== tabId && entry.thread === threadId,
    );
}
