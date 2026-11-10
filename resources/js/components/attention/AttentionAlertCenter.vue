<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { Volume2 } from '@lucide/vue';
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AttentionAlertCard from '@/components/attention/AttentionAlertCard.vue';
import { PRIORITY_RANK } from '@/components/attention/priority';
import {
    provideAttentionAlertActions,
    resetAttentionAlerts,
    useAttentionAlerts,
} from '@/composables/useAttentionAlerts';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';
import { useUserChannel } from '@/composables/useUserChannel';
import { Button } from '@/components/ui/button';
import {
    notificationSoundState,
    isAttentionAlertSounding,
    playAttentionAlertSound,
    prepareNotificationSound,
    showBrowserNotification,
    unlockNotificationSound,
} from '@/lib/notifications/alerts';
import type { AttentionAlertTone } from '@/lib/notifications/alerts';
import { claimNotificationSound } from '@/lib/notifications/tabs';
import { onReconnect } from '@/lib/realtime';
import { index as conversationsIndex } from '@/routes/conversations';
import {
    acknowledge as acknowledgeRoute,
    delivered as deliveredRoute,
    index as pendingRoute,
} from '@/routes/my-alerts';
import type { MyAttentionAlert } from '@/types';

/**
 * Attention alerts sent by administrators, on every page of the signed-in
 * person until they press «Entendido».
 *
 * The server is the source of truth: the pending alerts are asked for when
 * the application loads, after every signal of the personal channel, after
 * a reconnection, when the tab becomes visible again and periodically while
 * real-time is not connected, so an alert sent while the person was offline
 * is here when they come back.
 *
 * States, kept apart: an alert is registered when it is sent, available
 * while it is pending here (whether or not a screen got it), shown
 * (`delivered_at`) only once a visible tab put it on screen (a tab in the
 * background shows the system notification, but does not count it as
 * seen) and acknowledged with «Entendido».
 *
 * Sound, once per alert across the person's tabs and reloads (claimed in
 * claimNotificationSound): for an alert not yet shown, or shown less than
 * SOUND_WINDOW_MS ago (another tab of this person may have shown it with
 * its audio blocked). Only the most pressing alert of a batch sounds, so
 * alerts recovered after being offline sound once, not one after another,
 * and alert sounds never overlap. Audio is prepared without waiting for a
 * new click when the browser already allows it (the person interacted
 * with the page before); when it does not, the alerts stay on screen with
 * «Activar sonido», whose click is the gesture browsers ask for.
 *
 * The list is shared with the notification bell (useAttentionAlerts): the
 * bell shows the same alerts, with the same counter, and its «Entendido»
 * is this one, so acknowledging in either place removes the alert from
 * both (and from the person's other tabs, by the acknowledgement signal).
 * Opening the bell or reading an alert there acknowledges nothing.
 */
/*
 * Shown below the header and under menus and dialogs the person opens
 * (z-40), so the menu, the bell and the theme always stay usable.
 */
const page = usePage();
const signedIn = computed(() => Boolean(page.props.auth?.user));

const listHttp = useHttp<
    Record<string, never>,
    { data: MyAttentionAlert[]; total: number }
>({});
const deliveredHttp = useHttp<{ ids: number[] }, { updated: number }>({
    ids: [],
});
const acknowledgeHttp = useHttp<
    Record<string, never>,
    { acknowledged: boolean }
>({});

const { alerts, total, acknowledging, bellOpen, ordered, urgent } =
    useAttentionAlerts();
/** Alerts this tab already recorded as shown on screen. */
const shown = new Set<number>();
/** Alerts this tab already considered for sound or a system notification. */
const announced = new Set<number>();
/** A sound the browser blocked, played by «Activar sonido». */
const blockedSound = ref<{
    tone: AttentionAlertTone;
    keys: string[];
    rank: number;
} | null>(null);
/**
 * Alerts acknowledged here or in another tab: an answer of the list that
 * was already on its way never brings them back.
 */
const dismissed = new Set<number>();
let loading = false;
let loadAgain = false;

/** An alert shown this recently by another tab may still sound here. */
const SOUND_WINDOW_MS = 2 * 60_000;

const visible = computed(() => ordered.value.slice(0, 3));
const hidden = computed(() => Math.max(0, total.value - visible.value.length));
const soundBlocked = computed(
    () =>
        blockedSound.value !== null &&
        notificationSoundState.value !== 'unsupported',
);

async function load(): Promise<void> {
    if (!signedIn.value) {
        return;
    }

    if (loading) {
        loadAgain = true;

        return;
    }

    loading = true;

    try {
        const response = await listHttp.get(pendingRoute.url());
        const current = response.data.filter(
            (alert) => !dismissed.has(alert.alert_id),
        );
        alerts.value = current;
        total.value = Math.max(
            current.length,
            response.total - (response.data.length - current.length),
        );

        if (
            blockedSound.value &&
            !current.some((alert) =>
                blockedSound.value?.keys.includes(soundKey(alert)),
            )
        ) {
            blockedSound.value = null;
        }

        await announce(current.filter((alert) => isNew(alert)));
        await recordShown();
    } catch {
        // Kept as they were; the next signal or check tries again.
    } finally {
        loading = false;

        if (loadAgain) {
            loadAgain = false;
            void load();
        }
    }
}

function soundKey(alert: MyAttentionAlert): string {
    return `attention-alert-${alert.id}`;
}

/** Not yet shown anywhere, or shown so recently that it may not have sounded. */
function isNew(alert: MyAttentionAlert): boolean {
    if (announced.has(alert.id)) {
        return false;
    }

    return (
        alert.delivered_at === null ||
        Date.now() - new Date(alert.delivered_at).getTime() < SOUND_WINDOW_MS
    );
}

/**
 * Records as shown the alerts this tab has on screen, only while the tab
 * is visible: a tab in the background has shown nothing yet (the next
 * visibility change loads them again and records them then).
 */
let recording: Promise<void> = Promise.resolve();

/** One recording at a time (they share one request helper). */
function recordShown(): Promise<void> {
    recording = recording.then(recordShownNow, recordShownNow);

    return recording;
}

async function recordShownNow(): Promise<void> {
    if (document.visibilityState !== 'visible') {
        return;
    }

    // Only the cards on screen: the rest are the «N alertas más» line and
    // count as shown when a card makes room for them.
    const fresh = visible.value.filter(
        (alert) => alert.delivered_at === null && !shown.has(alert.id),
    );

    if (fresh.length === 0) {
        return;
    }

    fresh.forEach((alert) => shown.add(alert.id));

    try {
        deliveredHttp.ids = fresh.map((alert) => alert.id);
        await deliveredHttp.post(deliveredRoute.url());
    } catch {
        // Recorded on the next load.
        fresh.forEach((alert) => shown.delete(alert.id));
    }
}

/**
 * New alerts reaching this tab: a system notification while the tab is in
 * the background (title only: the message may be long) and one sound for
 * the batch, the most pressing alert's.
 */
async function announce(fresh: MyAttentionAlert[]): Promise<void> {
    if (fresh.length === 0) {
        return;
    }

    fresh.forEach((alert) => announced.add(alert.id));

    if (document.visibilityState !== 'visible') {
        fresh.forEach((alert) =>
            showBrowserNotification(
                `attention-${alert.id}`,
                `${alert.priority_label}: ${alert.title}`,
                `De ${alert.sender}`,
                () => undefined,
            ),
        );
    }

    const loudest = [...fresh].sort(
        (a, b) =>
            PRIORITY_RANK[b.priority] - PRIORITY_RANK[a.priority] ||
            b.id - a.id,
    )[0];

    if (loudest.sound === 'none') {
        return;
    }

    await sound(
        loudest.sound,
        fresh.map(soundKey),
        PRIORITY_RANK[loudest.priority],
    );
}

/**
 * Plays the sound of a batch once across tabs. Only a tab that can play
 * right now claims it, so a tab with blocked audio never silences another
 * one; when this one cannot, «Activar sonido» keeps the sound for later.
 */
async function sound(
    tone: AttentionAlertTone,
    keys: string[],
    rank: number,
): Promise<void> {
    try {
        if (notificationSoundState.value === 'unsupported') {
            return;
        }

        if (!(await prepareNotificationSound())) {
            // The most pressing blocked sound is the one kept.
            const held = blockedSound.value;

            blockedSound.value =
                held && held.rank > rank
                    ? { ...held, keys: [...new Set([...held.keys, ...keys])] }
                    : {
                          tone,
                          keys: [...new Set([...(held?.keys ?? []), ...keys])],
                          rank,
                      };

            return;
        }

        blockedSound.value = null;

        // Another alert sounding in this tab: one at a time, and the claim
        // is not spent, so this one is not silenced for good elsewhere.
        if (isAttentionAlertSounding()) {
            return;
        }

        if (await claimNotificationSound(keys, true)) {
            await playAttentionAlertSound(tone);
        }
    } catch {
        // Sound is a courtesy; the alert is on screen.
    }
}

/** «Activar sonido»: the click is the gesture; the held sound plays now. */
function enableSound(): void {
    const held = blockedSound.value;

    unlockNotificationSound();

    if (held) {
        void sound(held.tone, held.keys, held.rank);
    }
}

async function acknowledge(alert: MyAttentionAlert): Promise<void> {
    if (acknowledging.value !== null) {
        return;
    }

    acknowledging.value = alert.id;

    try {
        await acknowledgeHttp.post(acknowledgeRoute.url(alert.id));
        dismissed.add(alert.alert_id);
        // The signal of this same acknowledgement may have removed it already.
        if (alerts.value.some((item) => item.id === alert.id)) {
            alerts.value = alerts.value.filter((item) => item.id !== alert.id);
            total.value = Math.max(0, total.value - 1);
        }
    } catch {
        toast.error('No se pudo confirmar la alerta. Inténtalo de nuevo.');
    } finally {
        acknowledging.value = null;
    }

    if (alerts.value.length === 0 && total.value > 0) {
        void load();
    }
}

function viewPending(): void {
    router.visit(conversationsIndex({ query: { view: 'mine' } }));
}

/* A new alert, or one acknowledged in another tab. */
useUserChannel({
    'attention.alert': (payload) => {
        if (payload.acknowledged === true) {
            const before = alerts.value.length;
            dismissed.add(Number(payload.alert_id));
            alerts.value = alerts.value.filter(
                (alert) => alert.alert_id !== Number(payload.alert_id),
            );

            if (alerts.value.length < before) {
                total.value = Math.max(0, total.value - 1);
            }

            // More were waiting beyond the first page: bring them in.
            if (alerts.value.length === 0 && total.value > 0) {
                void load();
            }

            return;
        }

        void load();
    },
});

/* A card acknowledged makes room for the next one: it is shown now. */
watch(
    () => visible.value.map((alert) => alert.id).join(),
    () => void recordShown(),
);

const stopReconnect = onReconnect(() => void load());
onScopeDispose(stopReconnect);

const visibility = useDocumentVisibility();
watch(visibility, (state) => state === 'visible' && void load());

/* Without real-time, the alerts are checked every minute. */
const { state: realtimeState } = useRealtimeStatus();
const fallback = useIntervalFn(
    () => visibility.value === 'visible' && void load(),
    60_000,
    { immediate: false },
);

watch(
    () => realtimeState.value === 'connected',
    (live) => (live ? fallback.pause() : fallback.resume()),
    { immediate: true },
);

const stopActions = provideAttentionAlertActions({ acknowledge, reload: load });
onScopeDispose(stopActions);

watch(signedIn, (value) => {
    if (value) {
        void load();

        return;
    }

    // Signed out: nothing of this person stays on the page.
    resetAttentionAlerts();
    shown.clear();
    announced.clear();
    dismissed.clear();
    blockedSound.value = null;
});

onMounted(() => {
    unlockNotificationSound();
    void load();
});
</script>

<template>
    <section
        v-if="visible.length > 0 && !bellOpen"
        class="pointer-events-none fixed inset-x-0 top-16 z-40 flex justify-center px-4 sm:top-[4.5rem] sm:right-4 sm:left-auto sm:justify-end sm:px-0"
        aria-label="Alertas de atención"
        :aria-live="urgent ? 'assertive' : 'polite'"
        data-attention-alerts
    >
        <div
            class="pointer-events-auto grid max-h-[calc(100dvh-5rem)] w-full max-w-sm gap-2 overflow-y-auto overscroll-contain p-1 sm:max-h-[calc(100dvh-6rem)]"
        >
            <AttentionAlertCard
                v-for="alert in visible"
                :key="alert.id"
                :title="alert.title"
                :body="alert.body"
                :priority="alert.priority"
                :priority-label="alert.priority_label"
                :color="alert.color"
                :sender="alert.sender"
                :sent-at="alert.sent_at"
                :link-pending="alert.action === 'pending_conversations'"
                :acknowledging="acknowledging === alert.id"
                @acknowledge="acknowledge(alert)"
                @view-pending="viewPending"
            />
            <p
                v-if="hidden > 0"
                class="rounded-lg border bg-card px-3 py-1.5 text-center text-xs text-muted-foreground shadow-elevated"
            >
                {{
                    hidden === 1
                        ? '1 alerta más pendiente'
                        : `${hidden} alertas más pendientes`
                }}
            </p>
            <div
                v-if="soundBlocked"
                class="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-xs text-muted-foreground shadow-elevated"
                role="status"
                data-attention-sound-blocked
            >
                <span>El navegador bloqueó el sonido de la alerta.</span>
                <Button
                    size="sm"
                    variant="outline"
                    class="shrink-0 pointer-coarse:h-10"
                    data-attention-enable-sound
                    @click="enableSound"
                >
                    <Volume2 aria-hidden="true" />
                    Activar sonido
                </Button>
            </div>
        </div>
    </section>
</template>
