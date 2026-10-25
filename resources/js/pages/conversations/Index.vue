<script setup lang="ts">
import { Head, router, useHttp, usePage } from '@inertiajs/vue3';
import { MessagesSquare, SearchX } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, onScopeDispose, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConversationChat from '@/components/conversations/ConversationChat.vue';
import ConversationDetailsPanel from '@/components/conversations/ConversationDetailsPanel.vue';
import InboxList from '@/components/conversations/InboxList.vue';
import { useConversationAccept } from '@/components/conversations/useConversationAccept';
import {
    forgetSettledStatuses,
    reportMessageStatus,
    withReportedStatus,
} from '@/components/conversations/useInboxMessageStatus';
import EmptyState from '@/components/EmptyState.vue';
import AliasDialog from '@/components/personalization/AliasDialog.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLiveReload } from '@/composables/useLiveReload';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';
import {
    inboxChannelNames,
    userChannelName,
} from '@/composables/useUserChannel';
import { subscribe } from '@/lib/realtime';
import type { LiveChannel } from '@/lib/realtime';
import {
    index as conversationsIndex,
    page as conversationsPage,
    read as markConversationRead,
} from '@/routes/conversations';
import type {
    ComposerSettings,
    ConversationDetails,
    ConversationFilters,
    ConnectionChannel,
    ConversationInbox,
    ConversationList,
    ConversationStatusValue,
    ConversationSummary,
    ConversationTimelinePage,
    CustomerWindow,
    InboxCounts,
    InboxViewer,
    TagOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conversaciones', href: conversationsIndex() }],
    },
});

const props = defineProps<{
    conversations: ConversationList;
    filters: ConversationFilters;
    counts: InboxCounts;
    inbox: ConversationInbox;
    statuses: { value: ConversationStatusValue; label: string }[];
    departments: { id: number; name: string; is_active: boolean }[];
    agents: { id: number; name: string; is_active: boolean }[];
    connections: {
        id: number;
        name: string;
        display_phone_number: string | null;
    }[];
    unreadCount: number;
    filterOptions: {
        channels: { value: ConnectionChannel; label: string }[];
    };
    tags: TagOption[];
    canSeeUnrouted: boolean;
    canSeeClosed: boolean;
    canExport: boolean;
    viewer: InboxViewer;
    composer: ComposerSettings;
    selectedId: number | null;
    selectionMissing: boolean;
    conversation: ConversationSummary | null;
    window: CustomerWindow | null;
    timeline: ConversationTimelinePage | null;
    details: ConversationDetails | null;
    contactUrl: string | null;
    transferReasonRequired: boolean;
}>();

/** Props of the list (left panel). */
const LIST_PROPS = [
    'conversations',
    'filters',
    'counts',
    'inbox',
    'unreadCount',
];
/** Props a live change refreshes in the list. */
const LIVE_LIST_PROPS = ['conversations', 'counts', 'unreadCount'];
/** Props of the selected conversation (center and right panels). */
const SELECTION_PROPS = [
    'selectedId',
    'selectionMissing',
    'conversation',
    'window',
    'timeline',
    'details',
    'contactUrl',
];
/** Rows refreshed at most per request (ConversationController::LIST_CHUNK_MAX). */
const REFRESH_MAX = 500;

const page = usePage();
type ListQuery = Record<string, string | string[]>;

const listQuery = ref<ListQuery>({});
const loadingList = ref(false);
const loadingMore = ref(false);
/*
 * Reloads of the rows after the first page still running. "Load more" waits
 * for them, so the next page always starts from the rows just read again.
 */
let refreshingMore = 0;
let loadMoreAfterRefresh = false;
const loadingSelection = ref(false);

/*
 * The first page is a prop (reloaded live); rows loaded with "load more"
 * follow it here, page after page with the server's cursor, so the list
 * can be followed to its end. Every filter change starts over.
 */
const more = ref<ConversationList | null>(null);
let listGeneration = 0;
const moreHttp = useHttp<Record<string, never>, ConversationList>({});
const refreshHttp = useHttp<Record<string, never>, ConversationList>({});

/*
 * Rows as shown: the first page plus "load more", with the delivery state
 * of each row's latest message patched by real-time status updates when
 * they move it forward (useInboxMessageStatus).
 */
const list = computed<ConversationList>(() => {
    const first = new Set(props.conversations.data.map((row) => row.id));
    const rows =
        more.value === null
            ? props.conversations.data
            : [
                  ...props.conversations.data,
                  ...more.value.data.filter((row) => !first.has(row.id)),
              ];

    return {
        data: rows.map(withReportedStatus),
        has_more: (more.value ?? props.conversations).has_more,
        next_cursor: (more.value ?? props.conversations).next_cursor,
    };
});

/* Rows read again from the server settle the states they already show. */
watch(
    () => [props.conversations, more.value] as const,
    ([first, loaded]) =>
        forgetSettledStatuses([...first.data, ...(loaded?.data ?? [])]),
);

/** Query string of the current list, as the list component builds it. */
const inboxList = ref<InstanceType<typeof InboxList> | null>(null);

function url(selected: number | null, query: ListQuery): string {
    return conversationsIndex.url({
        query: {
            ...query,
            ...(selected !== null ? { c: String(selected) } : {}),
        },
    });
}

function currentQuery(): ListQuery {
    return inboxList.value?.query() ?? listQuery.value;
}

function select(id: number | null): void {
    if (id === props.selectedId && id !== null) {
        mobilePane.value = 'chat';

        return;
    }

    router.get(url(id, currentQuery()), undefined, {
        preserveState: true,
        preserveScroll: true,
        only: SELECTION_PROPS,
        onStart: () => (loadingSelection.value = true),
        onFinish: () => (loadingSelection.value = false),
    });
    mobilePane.value = id === null ? 'list' : 'chat';
}

function filter(query: ListQuery): void {
    listQuery.value = query;
    listGeneration++;
    loadMoreAfterRefresh = false;
    more.value = null;
    router.get(url(props.selectedId, query), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: LIST_PROPS,
        onStart: () => (loadingList.value = true),
        onFinish: () => {
            loadingList.value = false;
            reloadIfSignaledDuringFilter();
        },
    });
}

async function loadMore(): Promise<void> {
    const current = list.value;

    if (refreshingMore > 0) {
        loadMoreAfterRefresh = true;

        return;
    }

    if (
        loadingList.value ||
        loadingMore.value ||
        !current.has_more ||
        current.next_cursor === null
    ) {
        return;
    }

    const generation = listGeneration;
    loadingMore.value = true;

    try {
        const next = await moreHttp.get(
            conversationsPage.url({
                query: { ...currentQuery(), after: current.next_cursor },
            }),
        );

        if (generation === listGeneration) {
            const loaded = more.value?.data ?? [];
            const known = new Set(loaded.map((row) => row.id));

            more.value = {
                data: [
                    ...loaded,
                    ...next.data.filter((row) => !known.has(row.id)),
                ],
                has_more: next.has_more,
                next_cursor: next.next_cursor ?? current.next_cursor,
            };
        }
    } catch {
        if (generation === listGeneration) {
            toast.error(
                'No se pudieron cargar más conversaciones. Inténtalo de nuevo.',
            );
        }
    } finally {
        loadingMore.value = false;
    }
}

/*
 * The first page was reloaded (live change, read marker): the rows loaded
 * after it are read again from its new end, so they never stay stale,
 * repeated or out of their filter.
 */
async function refreshMore(first: ConversationList): Promise<void> {
    const loaded = more.value?.data.length ?? 0;
    const generation = ++listGeneration;

    if (loaded === 0 || first.next_cursor === null || !first.has_more) {
        more.value = null;

        return;
    }

    refreshingMore++;

    try {
        const rows = await refreshHttp.get(
            conversationsPage.url({
                query: {
                    ...currentQuery(),
                    after: first.next_cursor,
                    limit: String(Math.min(REFRESH_MAX, loaded)),
                },
            }),
        );

        if (generation === listGeneration) {
            more.value = rows.data.length === 0 ? null : rows;
        }
    } catch {
        // The next reload or "load more" brings the current rows.
    } finally {
        refreshingMore--;

        if (refreshingMore === 0 && loadMoreAfterRefresh) {
            loadMoreAfterRefresh = false;
            void loadMore();
        }
    }
}

watch(
    () => props.conversations,
    (first) => {
        if (more.value !== null) {
            void refreshMore(first);
        }
    },
);

/* Real-time: the list follows the viewer's inbox channels. */
const liveChannels = computed<LiveChannel[]>(() => {
    const channels: LiveChannel[] = [];
    const personal = userChannelName();

    if (personal) {
        channels.push({ name: personal, events: ['.inbox.changed'] });
    }

    for (const name of inboxChannelNames()) {
        channels.push({ name, events: ['.inbox.changed'] });
    }

    return channels;
});

/*
 * Delivery updates of the rows' latest messages (inbox.message_status, same
 * channels): they only patch the status icon, never reload the list.
 */
let stopStatusSignals: (() => void)[] = [];

watch(
    liveChannels,
    (channels) => {
        stopStatusSignals.forEach((stop) => stop());
        stopStatusSignals = channels.map((channel) =>
            subscribe(
                channel.name,
                '.inbox.message_status',
                reportMessageStatus,
            ),
        );
    },
    { immediate: true },
);

onScopeDispose(() => stopStatusSignals.forEach((stop) => stop()));

/*
 * A signal that arrives while a filter change is loading is not lost: the
 * filter's response may have been read before that change was committed,
 * so the list reloads once more when the filter finishes.
 */
let signalDuringFilter = false;

const liveList = useLiveReload({
    channels: liveChannels,
    only: LIVE_LIST_PROPS,
    fallbackIntervalMs: 20_000,
    enabled: () => !loadingList.value,
    filter: () => {
        if (loadingList.value) {
            signalDuringFilter = true;
        }

        return true;
    },
});

function reloadIfSignaledDuringFilter(): void {
    if (!signalDuringFilter) {
        return;
    }

    signalDuringFilter = false;
    // Through the live reload, so a debounced reload already scheduled by the
    // same signal and this one become a single request.
    liveList.schedule();
}

/*
 * Accepting a pending row: the same route and action as in the chat. In the
 * general inbox the row stays where it was (its order is the latest
 * activity, which accepting does not change) and only its indicator turns
 * into "En atención"; nothing is merged on the client.
 */
const { acceptingId, accept: acceptConversation } = useConversationAccept();

function acceptFromList(id: number): void {
    acceptConversation(id, {
        onSuccess: () => {
            // The server opens the accepted conversation (?c=id).
            if (props.selectedId === id) {
                mobilePane.value = 'chat';
            }
        },
    });
}

/*
 * The read marker moved: refresh the unread counters of the list. With
 * real-time the personal channel already signals it to every tab.
 */
const { state: realtimeState } = useRealtimeStatus();

function onRead(): void {
    if (realtimeState.value !== 'connected' && !loadingList.value) {
        router.reload({ only: ['conversations', 'unreadCount'] });
    }
}

/* "Marcar como leída" from a row: the same personal read marker as the chat. */
const readHttp = useHttp<{ message_id: number | null }, { moved: boolean }>({
    message_id: null,
});

/*
 * One request instance serves every row: quick clicks on several rows are
 * queued and sent one after another, so none cancels the previous one.
 */
const readQueue: number[] = [];
let drainingReadQueue = false;

async function markReadFromList(id: number): Promise<void> {
    if (!readQueue.includes(id)) {
        readQueue.push(id);
    }

    if (drainingReadQueue) {
        return;
    }

    drainingReadQueue = true;

    try {
        while (readQueue.length > 0) {
            const next = readQueue.shift() as number;

            try {
                await readHttp.post(markConversationRead.url(next));
            } catch {
                toast.error('No se pudo marcar la conversación como leída.');
            }
        }
    } finally {
        drainingReadQueue = false;
    }

    onRead();
}

/* Layout: three panels on wide screens, list ↔ chat on small ones. */
const wide = useMediaQuery('(min-width: 1024px)');
const extraWide = useMediaQuery('(min-width: 1280px)');
const mobilePane = ref<'list' | 'chat'>(props.selectedId ? 'chat' : 'list');

watch(
    () => props.selectedId,
    (id) => {
        if (id === null) {
            mobilePane.value = 'list';
        }
    },
);

const detailsKey = computed(
    () => `chat-manager:inbox-details:${page.props.auth?.user?.id ?? 0}`,
);
const detailsOpen = ref(true);

watch(
    detailsKey,
    (key) => {
        try {
            detailsOpen.value = globalThis.localStorage.getItem(key) !== '0';
        } catch {
            detailsOpen.value = true;
        }
    },
    { immediate: true },
);

const sheetDetailsOpen = ref(false);

function toggleDetails(): void {
    if (!extraWide.value) {
        sheetDetailsOpen.value = !sheetDetailsOpen.value;

        return;
    }

    detailsOpen.value = !detailsOpen.value;

    try {
        globalThis.localStorage.setItem(
            detailsKey.value,
            detailsOpen.value ? '1' : '0',
        );
    } catch {
        // Storage may be unavailable; the panel still toggles.
    }
}

const showList = computed(() => wide.value || mobilePane.value === 'list');
const showChat = computed(() => wide.value || mobilePane.value === 'chat');
const showDetailsColumn = computed(
    () =>
        extraWide.value &&
        detailsOpen.value &&
        props.conversation !== null &&
        props.details !== null,
);

const title = computed(() => {
    const contact = props.conversation?.contact as
        | (ConversationSummary['contact'] & { display_name?: string | null })
        | undefined;

    return contact
        ? `Conversación con ${contact.display_name || contact.name}`
        : 'Conversaciones';
});

/*
 * Private alias from the row menu (PERSONALIZATION): only the viewer's own
 * view changes, so the list rows and the open conversation reload.
 */
const aliasTarget = ref<ConversationSummary | null>(null);

function onAliasSaved(): void {
    router.reload({
        only:
            props.selectedId === aliasTarget.value?.id
                ? ['conversations', 'conversation', 'details']
                : ['conversations'],
    });
}
</script>

<template>
    <Head :title="title" />

    <div
        class="grid h-[calc(100dvh-3.5rem)] min-h-0 grid-cols-1 overflow-hidden sm:h-[calc(100dvh-4rem)]"
        :class="
            showDetailsColumn
                ? 'lg:grid-cols-[22rem_minmax(0,1fr)] xl:grid-cols-[22rem_minmax(0,1fr)_20rem]'
                : 'lg:grid-cols-[22rem_minmax(0,1fr)]'
        "
    >
        <div v-show="showList" class="min-h-0 border-r">
            <InboxList
                ref="inboxList"
                :conversations="list"
                :filters="filters"
                :counts="counts"
                :inbox="inbox"
                :departments="departments"
                :agents="agents"
                :connections="connections"
                :unread-count="unreadCount"
                :filter-options="filterOptions"
                :tags="tags"
                :can-see-unrouted="canSeeUnrouted"
                :can-see-closed="canSeeClosed"
                :can-export="canExport"
                :viewer="viewer"
                :selected-id="selectedId"
                :loading="loadingList || loadingMore"
                :accepting-id="acceptingId"
                @select="select"
                @accept="acceptFromList"
                @mark-read="markReadFromList"
                @filter="filter"
                @load-more="loadMore"
                :can-edit-alias="true"
                @edit-alias="(row) => (aliasTarget = row)"
            />
        </div>

        <main
            v-show="showChat"
            class="relative min-h-0 min-w-0"
            :aria-busy="loadingSelection"
        >
            <h1 v-if="!showList" class="sr-only">
                {{
                    conversation
                        ? `Conversación con ${conversation.contact.display_name}`
                        : 'Conversaciones'
                }}
            </h1>
            <ConversationChat
                v-if="conversation && window"
                :key="conversation.id"
                :conversation="conversation"
                :window="window"
                :timeline="timeline"
                :transfer-reason-required="transferReasonRequired"
                :composer="composer"
                :details-open="extraWide ? detailsOpen : sheetDetailsOpen"
                :class="loadingSelection && 'opacity-70 transition-opacity'"
                @back="select(null)"
                @read="onRead"
                @toggle-details="toggleDetails"
            />
            <div
                v-else
                class="flex h-full items-center justify-center bg-muted/30 p-6"
            >
                <EmptyState
                    class="max-w-md border-none bg-transparent"
                    :icon="selectionMissing ? SearchX : MessagesSquare"
                    :title="
                        selectionMissing
                            ? 'La conversación no está disponible'
                            : 'Elige una conversación'
                    "
                    :description="
                        selectionMissing
                            ? 'No existe o ya no está a tu alcance (por ejemplo, otra persona la aceptó o fue transferida).'
                            : 'Selecciona una conversación de la lista para ver sus mensajes y atender al cliente.'
                    "
                />
            </div>
        </main>

        <div
            v-if="showDetailsColumn && conversation && window && details"
            class="min-h-0"
        >
            <ConversationDetailsPanel
                :conversation="conversation"
                :window="window"
                :details="details"
                :contact-url="contactUrl"
                @close="toggleDetails"
                @select="select"
            />
        </div>

        <Sheet
            v-if="!extraWide && conversation && window && details"
            v-model:open="sheetDetailsOpen"
        >
            <SheetContent
                side="right"
                class="w-full max-w-sm p-0 sm:max-w-sm [&>button]:hidden"
            >
                <SheetTitle class="sr-only"
                    >Detalles de la conversación</SheetTitle
                >
                <SheetDescription class="sr-only">
                    Cliente, procedencia, etiquetas e historial.
                </SheetDescription>
                <ConversationDetailsPanel
                    :conversation="conversation"
                    :window="window"
                    :details="details"
                    :contact-url="contactUrl"
                    @close="sheetDetailsOpen = false"
                    @select="
                        (id) => {
                            sheetDetailsOpen = false;
                            select(id);
                        }
                    "
                />
            </SheetContent>
        </Sheet>

        <AliasDialog
            v-if="aliasTarget"
            :open="aliasTarget !== null"
            :conversation-id="aliasTarget.id"
            :contact-name="aliasTarget.contact.name"
            :alias="aliasTarget.contact.alias ?? null"
            @update:open="(value) => !value && (aliasTarget = null)"
            @saved="onAliasSaved"
        />
    </div>
</template>
