import { router, useHttp, usePage } from '@inertiajs/vue3';
import { ping as presencePing } from '@/routes/presence';
import {
    computed,
    defineComponent,
    h,
    markRaw,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import NotificationKindIcon from '@/components/notifications/NotificationKindIcon.vue';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';
import { syncServerClock } from '@/composables/useServerNow';
import { useUserChannel } from '@/composables/useUserChannel';
import { afterUserVisits } from '@/lib/realtime';
import {
    askNotificationPermissionOnFirstGesture,
    browserNotificationPermission,
    notificationSoundState,
    playNotificationSound,
    showBrowserNotification,
    unlockNotificationSound,
} from '@/lib/notifications/alerts';
import { notificationTone } from '@/lib/notifications/kinds';
import { ensurePushSubscription } from '@/lib/notifications/push';
import {
    VIEWED_THREAD_HEARTBEAT_MS,
    announceViewedThread,
    claimNotificationSound,
    isThreadViewedInAnotherTab,
} from '@/lib/notifications/tabs';
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

/** «En sesión» heartbeat (presence.online_minutes is 15 by default). */
const PRESENCE_HEARTBEAT_MS = 4 * 60_000;

/**
 * A notification whose latest occurrence is older than this when it is
 * fetched (after a long disconnection or a sleeping device) is listed in
 * the bell without a toast or a sound: it is no longer news.
 */
const STALE_ALERT_MS = 5 * 60_000;

/** Internal chat kinds (their own, softer sound). */
const TEAM_CHAT_KINDS: NotificationKindValue[] = [
    'team-chat.message',
    'team-chat.desk-request',
    'team-chat.mention',
];

/**
 * The toast's leading icon: the kind's icon on its tinted square, the same
 * one the bell shows for the notification.
 */
function toastIcon(item: AppNotification) {
    return markRaw(
        defineComponent({
            name: 'NotificationToastIcon',
            setup: () => () => h(NotificationKindIcon, { item, size: 'sm' }),
        }),
    );
}

/** What the page does for one new notification. */
export type NotificationAlertPlan = {
    /** About the conversation or internal chat on screen: marked read, never alerted. */
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
 *
 * An internal chat the user is looking at (open in this tab while it is
 * visible and focused, `attended`, like customer conversations; or in
 * another tab: `threadViewedElsewhere`) is being read, so its messages
 * neither sound nor show a toast; the chat itself updates as usual. Open
 * in a window that is visible but not focused (next to another app), the
 * message still sounds and stays unread (no toast: the chat is on screen).
 * A notification that was already old when fetched (`stale`) is only
 * listed in the bell.
 */
export function notificationAlertPlan(
    item: Pick<AppNotification, 'conversation_id' | 'team_thread_id' | 'toast'>,
    context: {
        viewedConversationId: number | null;
        attended: boolean;
        hidden: boolean;
        preference: NotificationPreference | undefined;
        soundPlayed: boolean;
        viewedThreadId?: number | null;
        visible?: boolean;
        threadViewedElsewhere?: boolean;
        stale?: boolean;
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

    let onScreen = false;

    if (item.team_thread_id !== null) {
        if (item.team_thread_id === context.viewedThreadId) {
            if (context.attended) {
                return { ...none, markRead: true };
            }

            onScreen = Boolean(context.visible);
        }

        if (context.threadViewedElsewhere) {
            return none;
        }
    }

    if (!context.preference || context.stale) {
        return none;
    }

    const browser = context.preference.browser && context.hidden;

    return {
        markRead: false,
        sound: context.preference.sound && !context.soundPlayed,
        browser,
        toast: !browser && item.toast && !onScreen,
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
            { id?: number } | null | undefined;

        if (typeof conversation?.id === 'number') {
            return conversation.id;
        }

        return typeof props.selectedId === 'number' ? props.selectedId : null;
    });

    /** The internal chat open on this page (team-chat/Index with a thread). */
    const viewedThreadId = computed<number | null>(() => {
        if (page.component !== 'team-chat/Index') {
            return null;
        }

        const thread = (page.props as Record<string, unknown>).activeThread as
            { id?: number } | null | undefined;

        return typeof thread?.id === 'number' ? thread.id : null;
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
            // Every page has the bell: its list keeps the shared countdown
            // clock (SLA deadlines) anchored to the server.
            syncServerClock(response.server_time);

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
                alert(fresh, response.server_time);
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
     * One system notification (a Windows toast) per new message. The service
     * worker shows the same notification under the same tag and version when
     * a Web Push arrives, so whichever comes first wins and the other one is
     * skipped: never two alerts for one message, and never none when the
     * push is slow or the page's refresh is late.
     */
    function showSystemNotification(item: AppNotification): void {
        // A system notification can show outside the app (lock screen,
        // notification history): internal chat ones say who wrote, never
        // what they wrote.
        void showBrowserNotification(
            item.id,
            item.title,
            isTeamChat(item) ? null : item.body,
            () => open(item),
            { version: version(item), url: item.url },
        );
    }

    function isTeamChat(item: AppNotification): boolean {
        return TEAM_CHAT_KINDS.includes(item.kind);
    }

    function isStale(item: AppNotification, serverTime: string): boolean {
        const now = Date.parse(serverTime);
        const at = Date.parse(item.updated_at ?? '');

        return (
            Number.isFinite(now) &&
            Number.isFinite(at) &&
            now - at > STALE_ALERT_MS
        );
    }

    let soundHintShown = false;

    /**
     * Pause of a tab the user is not looking at before it claims the sound,
     * so the tab in use (whose audio is surely unlocked) plays it first.
     */
    const BACKGROUND_CLAIM_DELAY_MS = 300;

    /**
     * One sound for the batch, unless another tab already played it (or one
     * just played): see claimNotificationSound(). A tab whose audio is still
     * blocked never claims it (another tab can play it) and, when visible,
     * says once how to enable it. Never throws.
     */
    async function playBatchSound(
        item: AppNotification,
        batch: AppNotification[],
    ): Promise<void> {
        try {
            if (notificationSoundState.value !== 'ready') {
                if (
                    notificationSoundState.value === 'locked' &&
                    !soundHintShown &&
                    document.visibilityState === 'visible'
                ) {
                    // Browsers keep audio blocked until the first click or
                    // key on the page after opening or reloading it.
                    soundHintShown = true;
                    toast('Sonido de avisos en pausa', {
                        id: 'notification-sound-locked',
                        description:
                            'El navegador lo activa cuando haces clic o pulsas una tecla en esta página.',
                    });
                }

                return;
            }

            if (!pageIsAttended()) {
                await new Promise((resolve) =>
                    setTimeout(resolve, BACKGROUND_CLAIM_DELAY_MS),
                );
            }

            const keys = batch.map((fresh) => `${fresh.id}|${version(fresh)}`);
            const urgent = batch.some(
                (fresh) => fresh.level === 'urgent' && !isTeamChat(fresh),
            );

            if (!(await claimNotificationSound(keys, urgent))) {
                return;
            }

            playNotificationSound(
                item.level === 'urgent',
                isTeamChat(item) ? 'team-chat' : 'default',
            );
        } catch {
            // Sound is a courtesy: never break the page for it.
        }
    }

    function alert(fresh: AppNotification[], serverTime: string): void {
        let played = false;
        const visible = document.visibilityState === 'visible';
        // Not looking at the platform (minimized, another tab, another app
        // in front): the alert goes to the operating system, when allowed.
        const systemAlerts =
            !pageIsAttended() && browserNotificationPermission() === 'granted';

        for (const item of fresh) {
            const plan = notificationAlertPlan(item, {
                viewedConversationId: viewedConversationId.value,
                attended: pageIsAttended(),
                hidden: systemAlerts,
                preference: preferences.value[item.kind],
                soundPlayed: played,
                viewedThreadId: viewedThreadId.value,
                visible,
                threadViewedElsewhere:
                    item.team_thread_id !== null &&
                    isThreadViewedInAnotherTab(item.team_thread_id),
                stale: isStale(item, serverTime),
            });

            if (plan.markRead) {
                if (item.conversation_id !== null) {
                    void markConversationRead(item.conversation_id);
                } else {
                    // The listed (reactive) copy, so the bell updates too.
                    void markRead(
                        items.value.find((listed) => listed.id === item.id) ??
                            item,
                    );
                }

                continue;
            }

            if (plan.sound) {
                void playBatchSound(item, fresh);
                played = true;
            }

            if (plan.browser) {
                showSystemNotification(item);
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
                    icon: toastIcon(item),
                    class: `notification-toast tone-${notificationTone(item)}`,
                    classes: { description: 'line-clamp-2' },
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
        announceThread();

        if (pageIsAttended()) {
            readViewedConversation();
        }
    }

    /**
     * Tells the other tabs which internal chat the user is looking at in
     * this one (visible and focused, the same rule as conversations).
     */
    function announceThread(): void {
        announceViewedThread(pageIsAttended() ? viewedThreadId.value : null);
    }

    watch(viewedThreadId, announceThread);

    let viewedHeartbeat: ReturnType<typeof setInterval> | null = null;
    let presenceHeartbeat: ReturnType<typeof setInterval> | null = null;

    /*
     * «En sesión»: while the app is open, a tiny request every few minutes
     * keeps the session's last activity fresh, so the automatic distribution
     * keeps counting this person as working (AgentPresence).
     */
    const pingPresence = (): void => {
        const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

        void fetch(presencePing.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': match ? decodeURIComponent(match[1]) : '',
            },
        }).catch(() => undefined);
    };
    const forgetThread = (): void => announceViewedThread(null);

    onMounted(() => {
        unlockNotificationSound();
        // System notifications stay on without any setup: the permission is
        // asked once (first click) and this browser subscribes to Web Push
        // on every sign-in, so alerts arrive even with the tab closed.
        askNotificationPermissionOnFirstGesture(
            () =>
                void ensurePushSubscription(
                    (page.props as { webPushKey?: string | null }).webPushKey ??
                        null,
                ),
        );
        document.addEventListener('visibilitychange', onAttentionChange);
        window.addEventListener('focus', onAttentionChange);
        window.addEventListener('blur', onAttentionChange);
        window.addEventListener('pagehide', forgetThread);
        announceThread();
        viewedHeartbeat = setInterval(() => {
            if (viewedThreadId.value !== null) {
                announceThread();
            }
        }, VIEWED_THREAD_HEARTBEAT_MS);
        void load();
        pingPresence();
        presenceHeartbeat = setInterval(pingPresence, PRESENCE_HEARTBEAT_MS);
    });

    onBeforeUnmount(() => {
        stopFallback();

        if (presenceHeartbeat !== null) {
            clearInterval(presenceHeartbeat);
        }

        document.removeEventListener('visibilitychange', onAttentionChange);
        window.removeEventListener('focus', onAttentionChange);
        window.removeEventListener('blur', onAttentionChange);
        window.removeEventListener('pagehide', forgetThread);

        if (viewedHeartbeat !== null) {
            clearInterval(viewedHeartbeat);
        }

        forgetThread();
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
