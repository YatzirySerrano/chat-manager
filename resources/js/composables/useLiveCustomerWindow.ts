import { computed, onScopeDispose, ref, toValue, watch } from 'vue';
import type { ComputedRef, MaybeRefOrGetter } from 'vue';
import type { CustomerWindow } from '@/types';

/** Same thresholds and texts as App\Services\Conversations\CustomerServiceWindow. */
const WARNING_SECONDS = 60 * 60;
const MAX_TICK_MS = 30_000;
const EXPIRED_REASON =
    'Pasaron más de 24 horas desde el último mensaje del cliente. Podrás responder cuando vuelva a escribir.';

/**
 * The customer service window kept current in the browser. The server sends
 * a snapshot (status and remaining seconds when the page was built); without
 * a new signal it would keep saying "quedan 10 min" after expiring. This
 * counts down from the snapshot's remaining seconds, measured against the
 * moment it arrived (so the device clock's offset does not matter), and
 * switches to "expiring" and to "expired" (free text blocked, templates
 * offered) at the right moment without reloading. The server checks the
 * window again on every send, so this only keeps the interface honest.
 */
export function useLiveCustomerWindow(
    source: MaybeRefOrGetter<CustomerWindow>,
): ComputedRef<CustomerWindow> {
    const now = ref(Date.now());
    const receivedAt = ref(Date.now());
    let timer: ReturnType<typeof setTimeout> | null = null;

    function stop(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function remainingSeconds(): number {
        const snapshot = toValue(source);
        const elapsed = Math.max(0, (now.value - receivedAt.value) / 1000);

        return Math.max(0, Math.ceil(snapshot.remaining_seconds - elapsed));
    }

    function counts(snapshot: CustomerWindow): boolean {
        return (
            (snapshot.status === 'open' || snapshot.status === 'expiring') &&
            snapshot.allows_free_form
        );
    }

    function schedule(): void {
        stop();

        if (!counts(toValue(source))) {
            return;
        }

        const remaining = remainingSeconds();

        if (remaining <= 0) {
            return;
        }

        const untilWarning = (remaining - WARNING_SECONDS) * 1000;
        const delay = Math.min(
            MAX_TICK_MS,
            remaining * 1000,
            untilWarning > 0 ? untilWarning : Number.POSITIVE_INFINITY,
        );

        timer = setTimeout(tick, Math.max(250, delay));
    }

    function tick(): void {
        now.value = Date.now();
        schedule();
    }

    function onVisibilityChange(): void {
        if (document.visibilityState === 'visible') {
            tick();
        }
    }

    watch(
        () => toValue(source),
        () => {
            receivedAt.value = Date.now();
            now.value = receivedAt.value;
            schedule();
        },
        { immediate: true },
    );

    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisibilityChange);
    }

    onScopeDispose(() => {
        stop();

        if (typeof document !== 'undefined') {
            document.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
        }
    });

    return computed<CustomerWindow>(() => {
        const snapshot = toValue(source);

        if (!counts(snapshot)) {
            return snapshot;
        }

        const remaining = remainingSeconds();

        if (remaining <= 0) {
            return {
                ...snapshot,
                status: 'expired',
                label: 'Ventana vencida',
                remaining_seconds: 0,
                allows_free_form: false,
                block_reason: EXPIRED_REASON,
            };
        }

        if (remaining <= WARNING_SECONDS) {
            return {
                ...snapshot,
                status: 'expiring',
                label: 'Por vencer',
                remaining_seconds: remaining,
            };
        }

        return { ...snapshot, remaining_seconds: remaining };
    });
}
