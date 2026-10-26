import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';
import { useUserChannel } from '@/composables/useUserChannel';
import { afterUserVisits } from '@/lib/realtime';
import {
    playNotificationSound,
    showBrowserNotification,
    unlockNotificationSound,
} from '@/lib/notifications/alerts';
import { currentPushSubscription } from '@/lib/notifications/push';
import {
    index as notificationsIndex,
    read,
    readAll,
} from '@/routes/notifications';
import { read as readConversation } from '@/routes/notifications/conversations';
import type {
    AppNotification,
    NotificationKindValue,
    NotificationListResponse,
    NotificationPreference,
    NotificationUnread,
} from '@/types';

/** Refresh period while real-time signals are not available. */
const FALLBACK_INTERVAL_MS = 60_000;

/** What the page does for one new notification. */
export type NotificationAlertPlan = {
    /** About the conversation on screen: marked read, never alerted. */
    markRead: boolean;
    sound: boolean;
    /** A system notification (the tab is hidden). */
    browser: boolean;
    toast: boolean;
};

/**
 * How the page alerts one new notification. The only input is a
 * notification the server already addressed to the viewer by its
 * notification rules (a conversation assigned or transferred to them, a
 * pending one they may accept, a customer message in one they attend...),
 * never the inbox signals: seeing a conversation (supervision) gives no
 * right to an alert about it, so a supervisor watching every conversation
 * hears nothing per message of other agents' conversations. One sound at
 * most per batch (`soundPlayed`).
 */
export function notificationAlertPlan(
    item: Pick<AppNotification, 'conversation_id' | 'toast'>,
    context: {
        viewedConversationId: number | null;
        attended: boolean;
        hidden: boolean;
        preference: NotificationPreference | undefined;
        soundPlayed: boolean;
    },
): NotificationAlertPlan {
    const none = {
        markRead: false,
        sound: false,
        browser: false,
        toast: false,
    };

    if (
        item.conversation_id !== null &&
        item.conversation_id === context.viewedConversationId &&
        context.attended
    ) {
        return { ...none, markRead: true };
    }

    if (!context.preference) {
        return none;
    }

    const browser = context.preference.browser && context.hidden;

    return {
        markRead: false,
        sound: context.preference.sound && !context.soundPlayed,
        browser,
        toast: !browser && item.toast,
    };
}

/**
 * State and actions of the notification bell. The list is always fetched
 * from the server (texts are rendered there after checking access); a
 * `.notification` signal on the personal channel, or the periodic refresh
 * when real-time is unavailable, triggers a new fetch. Notifications that
 * appear between two fetches may play a sound, show a system notification
 * or a toast, according to the user's preferences, except those about the
 * conversation the user is looking at (open, in a visible and focused tab),
 * which are marked read instead.
 *
 * Mounted once, in the persistent layout (NotificationBell): Inertia visits
 * keep it alive, so its listeners are never duplicated.
 */
export function useNotificationCenter() {
    const page = usePage();
    const listHttp = useHttp<Record<string, never>, unknown>({});
    const actionHttp = useHttp<Record<string, never>, unknown>({});
    const { state: realtimeState } = useRealtimeStatus();

    const items = ref<AppNotification[]>([]);
    const unread = ref<NotificationUnread>({ urgent: 0, total: 0 });
    const preferences = ref<
        Partial<Record<NotificationKindValue, NotificationPreference>>
    >({});
    const nextBefore = ref<string | null>(null);
    const nextBeforeId = ref<string | null>(null);
    const loaded = ref(false);
    const loading = ref(false);
    const failed = ref(false);
    const onlyUnread = ref(false);

    /**
     * Version (updated_at and occurrences) of every notification seen, to
     * detect fresh ones: timestamps have second precision, so a folded
     * occurrence within the same second only changes the counter.
     */
    const seen = new Map<string, string>();
    /**
     * Toast id of every notification whose toast is on screen in this tab.
     * A closed toast is forgotten as soon as it starts closing (onAutoClose,
     * onDismiss): vue-sonner keeps the id while the toast plays its exit
     * animation, and reusing it then would update a toast that is about to
     * disappear, losing the alert. A new occurrence after that gets a new
     * toast under a new id.
     */
    const toasted = new Map<string, string>();
    let toastSequence = 0;
    let requestCounter = 0;
    let timer: ReturnType<typeof setInterval> | null = null;

    const viewedConversationId = computed<number | null>(() => {
        if (!page.component.startsWith('conversations/')) {
            return null;
        }

        const props = page.props as Record<string, unknown>;
        const conversation = props.conversation as
            | { id?: number }
            | null
            | undefined;

        if (typeof conversation?.id === 'number') {
            return conversation.id;
        }

        return typeof props.selectedId === 'number' ? props.selectedId : null;
    });

    function version(item: AppNotification): string {
        return `${item.updated_at ?? ''}|${item.occurrences}`;
    }

    function remember(list: AppNotification[]): AppNotification[] {
        const fresh: AppNotification[] = [];

        for (const item of list) {
            const current = version(item);

            if (
                loaded.value &&
                item.read_at === null &&
                seen.get(item.id) !== current
            ) {
                fresh.push(item);
            }

            seen.set(item.id, current);
        }

        return fresh;
    }

    /**
     * Whether the user is actually looking at this tab. A conversation left
     * open in a hidden tab or an unfocused window is not "on screen".
     */
    function pageIsAttended(): boolean {
        return document.visibilityState === 'visible' && document.hasFocus();
    }

    /**
     * `more` appends the next page; `silent` refreshes without alerting (a
     * filter change reveals older unread items that are not news).
     */
    async function load(
        options: { more?: boolean; silent?: boolean } = {},
    ): Promise<void> {
        const request = ++requestCounter;
        const query: Record<string, string | number> = {};

        if (onlyUnread.value) {
            query.unread = 1;
        }

        if (options.more && nextBefore.value) {
            query.before = nextBefore.value;

            if (nextBeforeId.value) {
                query.before_id = nextBeforeId.value;
            }
        }

        loading.value = true;

        try {
            const response = (await listHttp.get(
                notificationsIndex.url({ query }),
            )) as NotificationListResponse;

            if (request !== requestCounter) {
                return;
            }

            const fresh = options.more ? [] : remember(response.data);

            if (options.more || options.silent) {
                response.data.forEach((item) =>
                    seen.set(item.id, version(item)),
                );
            }

            items.value = options.more
                ? [...items.value, ...response.data]
                : response.data;
            unread.value = response.unread;
            preferences.value = response.preferences;
            nextBefore.value = response.next_before;
            nextBeforeId.value = response.next_before_id;
            failed.value = false;
            loaded.value = true;

            // A notification read elsewhere (another tab, or by the server
            // when the conversation left the user's reach) drops its toast.
            // With "Only unread" a read notification is not listed at all, so
            // a toast missing from the (newest) unread page is dropped too.
            if (!options.more) {
                const listed = new Set(
                    response.data
                        .filter((item) => item.read_at === null)
                        .map((item) => item.id),
                );

                for (const [id, toastId] of toasted) {
                    const isGone = query.unread === 1 && !listed.has(id);
                    const isRead = response.data.some(
                        (item) => item.id === id && item.read_at !== null,
                    );

                    if (isGone || isRead) {
                        toast.dismiss(toastId);
                        toasted.delete(id);
                    }
                }
            }

            if (!options.silent) {
                alert(fresh);
            }
        } catch {
            if (request === requestCounter) {
                failed.value = true;
            }
        } finally {
            if (request === requestCounter) {
                loading.value = false;
            }
        }
    }

    /**
     * With Web Push active in this browser the service worker already shows
     * the (generic) alert for the same notification, so the page stays
     * silent to avoid a duplicate. The worker cannot be the one to skip it:
     * subscriptions use userVisibleOnly, and Chrome shows its own "updated in
     * the background" notice when a push to a hidden page shows nothing.
     */
    async function showUnlessPushed(item: AppNotification): Promise<void> {
        if (await currentPushSubscription()) {
            return;
        }

        showBrowserNotification(item.id, item.title, item.body, () =>
            open(item),
        );
    }

    function alert(fresh: AppNotification[]): void {
        let played = false;

        for (const item of fresh) {
            const plan = notificationAlertPlan(item, {
                viewedConversationId: viewedConversationId.value,
                attended: pageIsAttended(),
                hidden: document.visibilityState === 'hidden',
                preference: preferences.value[item.kind],
                soundPlayed: played,
            });

            if (plan.markRead && item.conversation_id !== null) {
                void markConversationRead(item.conversation_id);
                continue;
            }

            if (plan.sound) {
                playNotificationSound(item.level === 'urgent');
                played = true;
            }

            if (plan.browser) {
                void showUnlessPushed(item);
            } else if (plan.toast) {
                // One toast per notification: a new occurrence of an
                // aggregated one replaces its toast instead of stacking.
                const toastId =
                    toasted.get(item.id) ??
                    `notification-${item.id}-${++toastSequence}`;
                const forget = () => {
                    if (toasted.get(item.id) === toastId) {
                        toasted.delete(item.id);
                    }
                };
                toasted.set(item.id, toastId);
                toast.info(item.title, {
                    id: toastId,
                    description: item.body ?? undefined,
                    action: item.url
                        ? {
                              label:
                                  item.kind === 'export.finished'
                                      ? 'Descargar'
                                      : 'Abrir',
                              onClick: () => open(item),
                          }
                        : undefined,
                    // Closing (the X, a swipe, the timer) only hides the
                    // toast; the notification stays unread in the bell.
                    onDismiss: forget,
                    onAutoClose: forget,
                });
            }
        }
    }

    async function markRead(item: AppNotification): Promise<void> {
        if (item.read_at !== null) {
            return;
        }

        item.read_at = new Date().toISOString();

        try {
            const response = (await actionHttp.post(read.url(item.id))) as {
                unread: NotificationUnread;
            };
            unread.value = response.unread;
        } catch {
            item.read_at = null;
        }
    }

    async function markAllRead(): Promise<void> {
        try {
            const response = (await actionHttp.post(readAll.url())) as {
                unread: NotificationUnread;
            };
            unread.value = response.unread;
            items.value.forEach((item) => {
                item.read_at ??= new Date().toISOString();
            });
        } catch {
            toast.error(
                'No se pudieron marcar como leídas. Inténtalo de nuevo.',
            );
        }
    }

    async function markConversationRead(conversationId: number): Promise<void> {
        try {
            const response = (await actionHttp.post(
                readConversation.url(conversationId),
            )) as { unread: NotificationUnread };
            unread.value = response.unread;
            items.value.forEach((item) => {
                if (item.conversation_id === conversationId) {
                    item.read_at ??= new Date().toISOString();
                }
            });
        } catch {
            // The next refresh shows the real state.
        }
    }

    function open(item: AppNotification): void {
        void markRead(item);

        if (item.url) {
            router.visit(item.url);
        }
    }

    function startFallback(): void {
        stopFallback();
        timer = setInterval(() => void load(), FALLBACK_INTERVAL_MS);
    }

    function stopFallback(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    // After the user's own visit in flight: a request in between would
    // consume the one-time flash (toast) of the operation being finished.
    useUserChannel({
        '.notification': () => afterUserVisits(() => void load()),
    });

    watch(
        realtimeState,
        (state, previous) => {
            if (state === 'connected') {
                stopFallback();

                // Recover what may have been missed while disconnected.
                if (previous !== undefined && previous !== 'connected') {
                    void load();
                }
            } else {
                startFallback();
            }
        },
        { immediate: true },
    );

    /** The conversation on screen has nothing new for the user any more. */
    function readViewedConversation(): void {
        const conversationId = viewedConversationId.value;

        if (
            conversationId !== null &&
            pageIsAttended() &&
            items.value.some(
                (item) =>
                    item.conversation_id === conversationId &&
                    item.read_at === null,
            )
        ) {
            void markConversationRead(conversationId);
        }
    }

    watch(viewedConversationId, readViewedConversation);

    watch(onlyUnread, () => void load({ silent: true }));

    // Back to the tab with the conversation open: what arrived meanwhile
    // was alerted and is now seen.
    function onAttentionChange(): void {
        if (pageIsAttended()) {
            readViewedConversation();
        }
    }

    onMounted(() => {
        unlockNotificationSound();
        document.addEventListener('visibilitychange', onAttentionChange);
        window.addEventListener('focus', onAttentionChange);
        void load();
    });

    onBeforeUnmount(() => {
        stopFallback();
        document.removeEventListener('visibilitychange', onAttentionChange);
        window.removeEventListener('focus', onAttentionChange);
    });

    return {
        items,
        unread,
        loaded,
        loading,
        failed,
        onlyUnread,
        hasMore: computed(() => nextBefore.value !== null),
        load,
        loadMore: () => load({ more: true }),
        markRead,
        markAllRead,
        open,
    };
}
