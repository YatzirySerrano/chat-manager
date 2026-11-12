import { computed, onScopeDispose, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { useUserChannel } from '@/composables/useUserChannel';
import {
    WhatsAppWebRequestError,
    fetchLinkState,
    isLiveState,
    runLinkAction,
} from '@/lib/whatsappWeb';
import type { LinkState, WhatsAppWebActionName } from '@/types';

/** While a QR is being drawn, scanned or a socket is coming back. */
const LIVE_INTERVAL_MS = 2_000;
/** A settled session (connected, stopped, logged out…). */
const IDLE_INTERVAL_MS = 10_000;

export type WhatsAppWebSessionOptions = {
    /** State the page already has (e.g. sent with the connection). */
    initial?: LinkState | null;
    /** Stops polling while false (a closed dialog, a deleted connection). */
    enabled?: MaybeRefOrGetter<boolean>;
};

/**
 * Live state of one WhatsApp Web (QR) session, read from Laravel
 * (GET connections/{id}/whatsapp-web/session):
 *
 * - polls every 2 s while the state changes by itself (starting, waiting
 *   for the scan, pairing, reconnecting) and every 10 s once settled;
 * - a hidden tab stops polling and catches up once when shown again;
 * - a ".connection.session" signal on the personal channel, when the server
 *   sends one for this connection, refreshes at once (polling stays as the
 *   source of truth, so nothing depends on the signal existing);
 * - `run(action)` posts one of the actions the server offers in
 *   `state.actions` and takes the state it answers with.
 *
 * `reachable` is about Laravel answering at all; whether the Node service
 * answers is `state.service_available`.
 */
export function useWhatsAppWebSession(
    connectionId: MaybeRefOrGetter<number | null | undefined>,
    options: WhatsAppWebSessionOptions = {},
) {
    const state = ref<LinkState | null>(options.initial ?? null);
    const loading = ref(false);
    const reachable = ref(true);
    const running = ref<WhatsAppWebActionName | null>(null);
    const actionError = ref<string | null>(null);

    /** When `state` was received, to count the QR down without the server clock. */
    const receivedAt = ref(Date.now());
    const now = ref(Date.now());

    const id = computed(() => toValue(connectionId) ?? null);
    const enabled = computed(
        () => id.value !== null && toValue(options.enabled ?? true),
    );

    let pollTimer: number | undefined;
    let clockTimer: number | undefined;
    let controller: AbortController | null = null;

    function accept(next: LinkState): void {
        state.value = next;
        receivedAt.value = Date.now();
        now.value = Date.now();
        reachable.value = true;
    }

    function schedule(): void {
        window.clearTimeout(pollTimer);

        if (!enabled.value || document.hidden) {
            return;
        }

        const live = state.value === null || isLiveState(state.value.state);

        pollTimer = window.setTimeout(
            () => void refresh(),
            live ? LIVE_INTERVAL_MS : IDLE_INTERVAL_MS,
        );
    }

    async function refresh(): Promise<void> {
        const connection = id.value;

        if (connection === null || !enabled.value) {
            return;
        }

        controller?.abort();
        controller = new AbortController();
        loading.value = state.value === null;

        try {
            accept(await fetchLinkState(connection, controller.signal));
        } catch (error) {
            if ((error as Error).name === 'AbortError') {
                return;
            }

            reachable.value = false;
        } finally {
            loading.value = false;
        }

        schedule();
    }

    /**
     * Runs an action and returns whether the server accepted it. The message
     * of a refusal stays in `actionError` until the next action.
     */
    async function run(action: WhatsAppWebActionName): Promise<boolean> {
        const connection = id.value;

        if (connection === null || running.value !== null) {
            return false;
        }

        running.value = action;
        actionError.value = null;
        // A poll answered after the action would bring back the old state.
        controller?.abort();
        window.clearTimeout(pollTimer);

        try {
            accept(await runLinkAction(connection, action));

            return true;
        } catch (error) {
            actionError.value =
                error instanceof WhatsAppWebRequestError
                    ? error.message
                    : 'No se pudo contactar al servidor. Revisa tu conexión e inténtalo de nuevo.';

            return false;
        } finally {
            running.value = null;
            schedule();
        }
    }

    /** Seconds the current QR is still valid, counted down in the browser. */
    const qrSecondsLeft = computed(() => {
        const qr = state.value?.qr;

        if (!qr) {
            return 0;
        }

        const elapsed = Math.floor((now.value - receivedAt.value) / 1000);

        return Math.max(0, qr.seconds_left - elapsed);
    });

    function onVisibilityChange(): void {
        if (document.hidden) {
            window.clearTimeout(pollTimer);

            return;
        }

        void refresh();
    }

    function stop(): void {
        window.clearTimeout(pollTimer);
        window.clearInterval(clockTimer);
        clockTimer = undefined;
        controller?.abort();
        document.removeEventListener('visibilitychange', onVisibilityChange);
    }

    watch(
        [enabled, id],
        ([isEnabled], previous) => {
            stop();

            if (previous && previous[1] !== id.value) {
                state.value = options.initial ?? null;
                actionError.value = null;
            }

            if (!isEnabled) {
                return;
            }

            document.addEventListener('visibilitychange', onVisibilityChange);
            clockTimer = window.setInterval(
                () => (now.value = Date.now()),
                500,
            );
            void refresh();
        },
        { immediate: true },
    );

    useUserChannel({
        '.connection.session': (payload) => {
            if (
                enabled.value &&
                (payload.connection_id === undefined ||
                    Number(payload.connection_id) === id.value)
            ) {
                void refresh();
            }
        },
    });

    onScopeDispose(stop);

    return {
        state,
        loading,
        reachable,
        running,
        actionError,
        qrSecondsLeft,
        refresh,
        run,
    };
}
