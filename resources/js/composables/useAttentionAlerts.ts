import { computed, ref } from 'vue';
import { PRIORITY_RANK } from '@/components/attention/priority';
import type { MyAttentionAlert } from '@/types';

/**
 * The signed-in person's pending attention alerts, shared by every place
 * of the page that shows them: the floating cards (AttentionAlertCenter)
 * and the notification bell. There is one list, fetched from the server
 * (my-alerts) by AttentionAlertCenter, which also owns sound, system
 * notifications, the «shown» record and «Entendido»; the bell only reads
 * this state and asks for those actions, so an alert is one logical record
 * wherever it appears: one counter, one acknowledgement, one sound.
 *
 * Module state (one per page): both components live in the persistent
 * layout, so Inertia visits keep it.
 */
const alerts = ref<MyAttentionAlert[]>([]);
/** Pending alerts on the server (the list holds the first page). */
const total = ref(0);
/** The recipient row being acknowledged right now (one at a time). */
const acknowledging = ref<number | null>(null);
/**
 * The bell is open and lists the alerts itself: the floating cards step
 * aside meanwhile, so the same alert is never shown twice, one on top of
 * the other (on a phone they would overlap).
 */
const bellOpen = ref(false);

type Actions = {
    acknowledge: (alert: MyAttentionAlert) => Promise<void>;
    reload: () => Promise<void>;
};

let actions: Actions | null = null;

/** Most pressing first, then the oldest. */
const ordered = computed(() =>
    [...alerts.value].sort(
        (a, b) =>
            PRIORITY_RANK[b.priority] - PRIORITY_RANK[a.priority] ||
            a.id - b.id,
    ),
);

const urgent = computed(() =>
    alerts.value.some((alert) => alert.priority === 'urgent'),
);

/** Used by AttentionAlertCenter, the only one that talks to the server. */
export function provideAttentionAlertActions(provided: Actions): () => void {
    actions = provided;

    return () => {
        if (actions === provided) {
            actions = null;
        }
    };
}

/** Forgets everything (the person signed out). */
export function resetAttentionAlerts(): void {
    alerts.value = [];
    total.value = 0;
    acknowledging.value = null;
    bellOpen.value = false;
}

export function useAttentionAlerts() {
    return {
        alerts,
        total,
        acknowledging,
        bellOpen,
        ordered,
        urgent,
        acknowledge: (alert: MyAttentionAlert): Promise<void> =>
            actions?.acknowledge(alert) ?? Promise.resolve(),
        reload: (): Promise<void> => actions?.reload() ?? Promise.resolve(),
    };
}
