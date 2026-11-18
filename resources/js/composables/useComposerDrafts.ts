import { onBeforeUnmount, onMounted } from 'vue';

/** Pause in typing after which the text is saved on the server. */
const SAVE_DELAY_MS = 800;

export type SavedDraft = { body: string; savedAt: number };

type DraftsResponse = {
    drafts: Record<string, { body: string; updated_at: string | null }>;
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function headers(json: boolean): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrfToken(),
        ...(json ? { 'Content-Type': 'application/json' } : {}),
    };
}

/**
 * The composer's unsent text, kept on the server per user, chat and mode,
 * so an agent can start a reply on the phone and finish it on the computer
 * (or the other way around). Saving is best effort and never blocks
 * writing: a failed save only means the other device will not see the
 * latest words.
 *
 * - load() reads the saved drafts of the chat (by mode).
 * - save(mode, text) saves after a pause in typing; an empty text removes
 *   the draft.
 * - sent(mode) is called once the message left: the server already
 *   removed the draft with the send, so the pending save is dropped, and a
 *   save that was still on its way is followed by an empty one, so it can
 *   never bring the sent text back.
 * - Pending saves go out at once when the page is hidden (phone locked,
 *   app switched, tab closed) and when the composer goes away.
 *
 * Requests for one chat go out one after another, in order.
 */
export function useComposerDrafts(url: () => string) {
    const timers = new Map<string, ReturnType<typeof setTimeout>>();
    /* Text waiting for the pause in typing, with the chat it belongs to. */
    const pending = new Map<string, { target: string; body: string }>();
    const inFlight = new Map<string, number>();
    let chain: Promise<unknown> = Promise.resolve();

    function put(
        target: string,
        mode: string,
        body: string,
        keepalive = false,
    ): void {
        inFlight.set(mode, (inFlight.get(mode) ?? 0) + 1);
        chain = chain
            .then(() =>
                fetch(target, {
                    method: 'PUT',
                    credentials: 'same-origin',
                    keepalive,
                    headers: headers(true),
                    body: JSON.stringify({ mode, body }),
                }),
            )
            .catch(() => {
                // Best effort: the text stays in the composer of this device.
            })
            .finally(() => {
                inFlight.set(mode, Math.max(0, (inFlight.get(mode) ?? 1) - 1));
            });
    }

    function flushMode(mode: string, keepalive = false): void {
        const timer = timers.get(mode);

        if (timer) {
            clearTimeout(timer);
            timers.delete(mode);
        }

        const entry = pending.get(mode);

        if (entry) {
            pending.delete(mode);
            put(entry.target, mode, entry.body, keepalive);
        }
    }

    function flush(keepalive = false): void {
        for (const mode of [...pending.keys()]) {
            flushMode(mode, keepalive);
        }
    }

    function save(mode: string, text: string): void {
        pending.set(mode, { target: url(), body: text });

        const timer = timers.get(mode);

        if (timer) {
            clearTimeout(timer);
        }

        timers.set(
            mode,
            setTimeout(() => flushMode(mode), SAVE_DELAY_MS),
        );
    }

    function sent(mode: string): void {
        const timer = timers.get(mode);

        if (timer) {
            clearTimeout(timer);
            timers.delete(mode);
        }

        pending.delete(mode);

        if ((inFlight.get(mode) ?? 0) > 0) {
            put(url(), mode, '');
        }
    }

    /** The saved drafts by mode, with when each was saved (ms). */
    async function load(): Promise<Record<string, SavedDraft>> {
        try {
            const response = await fetch(url(), {
                credentials: 'same-origin',
                headers: headers(false),
            });

            if (!response.ok) {
                return {};
            }

            const data = (await response.json()) as DraftsResponse;

            return Object.fromEntries(
                Object.entries(data.drafts ?? {}).map(([mode, draft]) => [
                    mode,
                    {
                        body: draft.body,
                        savedAt: draft.updated_at
                            ? Date.parse(draft.updated_at)
                            : 0,
                    },
                ]),
            );
        } catch {
            return {};
        }
    }

    function onHidden(): void {
        if (document.visibilityState === 'hidden') {
            flush(true);
        }
    }

    function onPageHide(): void {
        flush(true);
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', onHidden);
        window.addEventListener('pagehide', onPageHide);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onHidden);
        window.removeEventListener('pagehide', onPageHide);
        flush(true);
    });

    return { load, save, sent, flush };
}
