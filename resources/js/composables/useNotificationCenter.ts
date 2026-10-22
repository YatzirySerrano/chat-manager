import { router, useHttp, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';
import { useUserChannel } from '@/composables/useUserChannel';
import { afterUserVisits } from '@/lib/realtime';
import {
    playNotificationSound,
    showBrowserNotification,
} from '@/lib/notifications/alerts';
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

/**
 * State and actions of the notification bell. The list is always fetched
 * from the server (texts are rendered there after checking access); a
 * `.notification` signal on the personal channel, or the periodic refresh
 * when real-time is unavailable, triggers a new fetch. Notifications that
 * appear between two fetches may play a sound, show a system notification
 * or a toast, according to the user's preferences, except those about the
 * conversation already on screen, which are marked read instead.
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

    /** updated_at of every notification seen, to detect fresh ones. */
    const seen = new Map<string, string | null>();
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

    function remember(list: AppNotification[]): AppNotification[] {
        const fresh: AppNotification[] = [];

        for (const item of list) {
            const previous = seen.get(item.id);

            if (
                loaded.value &&
                item.read_at === null &&
                (previous === undefined || previous !== item.updated_at)
            ) {
                fresh.push(item);
            }

            seen.set(item.id, item.updated_at);
        }

        return fresh;
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
                    seen.set(item.id, item.updated_at),
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

    function alert(fresh: AppNotification[]): void {
        let played = false;

        for (const item of fresh) {
            if (
                item.conversation_id !== null &&
                item.conversation_id === viewedConversationId.value
            ) {
                void markConversationRead(item.conversation_id);
                continue;
            }

            const preference = preferences.value[item.kind];

            if (!preference) {
                continue;
            }

            if (preference.sound && !played) {
                playNotificationSound(item.level === 'urgent');
                played = true;
            }

            if (preference.browser && document.visibilityState === 'hidden') {
                showBrowserNotification(item.id, item.title, item.body, () =>
                    open(item),
                );
            } else if (item.level === 'urgent') {
                toast.info(item.title, {
                    description: item.body ?? undefined,
                    action: item.url
                        ? { label: 'Abrir', onClick: () => open(item) }
                        : undefined,
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

    watch(viewedConversationId, (conversationId) => {
        if (
            conversationId !== null &&
            items.value.some(
                (item) =>
                    item.conversation_id === conversationId &&
                    item.read_at === null,
            )
        ) {
            void markConversationRead(conversationId);
        }
    });

    watch(onlyUnread, () => void load({ silent: true }));

    onMounted(() => void load());
    onBeforeUnmount(stopFallback);

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
