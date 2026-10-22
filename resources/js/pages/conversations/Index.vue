<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { MessagesSquare, SearchX } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import ConversationChat from '@/components/conversations/ConversationChat.vue';
import ConversationDetailsPanel from '@/components/conversations/ConversationDetailsPanel.vue';
import InboxList from '@/components/conversations/InboxList.vue';
import EmptyState from '@/components/EmptyState.vue';
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
    useUserChannel,
    userChannelName,
} from '@/composables/useUserChannel';
import { afterUserVisits } from '@/lib/realtime';
import type { LiveChannel } from '@/lib/realtime';
import { index as conversationsIndex } from '@/routes/conversations';
import type {
    ComposerSettings,
    ConversationDetails,
    ConversationFilters,
    ConversationInbox,
    ConversationInboxView,
    ConversationList,
    ConversationStatusValue,
    ConversationSummary,
    ConversationTimelinePage,
    CustomerWindow,
    TagOption,
    TransferOptions,
    ViewerAvailability,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conversaciones', href: conversationsIndex() }],
    },
});

const props = defineProps<{
    conversations: ConversationList;
    filters: ConversationFilters;
    counts: Record<ConversationStatusValue, number>;
    inbox: ConversationInbox;
    viewCounts: Partial<Record<ConversationInboxView, number>>;
    statuses: { value: ConversationStatusValue; label: string }[];
    departments: { id: number; name: string; is_active: boolean }[];
    agents: { id: number; name: string; is_active: boolean }[];
    connections: { id: number; name: string }[];
    tags: TagOption[];
    canSeeUnrouted: boolean;
    canExport: boolean;
    availability: ViewerAvailability;
    composer: ComposerSettings;
    selectedId: number | null;
    selectionMissing: boolean;
    conversation: ConversationSummary | null;
    window: CustomerWindow | null;
    timeline: ConversationTimelinePage | null;
    details: ConversationDetails | null;
    contactUrl: string | null;
    transferOptions?: TransferOptions;
    transferReasonRequired: boolean;
}>();

/** Props of the list (left panel). */
const LIST_PROPS = [
    'conversations',
    'filters',
    'counts',
    'inbox',
    'viewCounts',
];
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
const PAGE_SIZE = 25;

const page = usePage();
const listQuery = ref<Record<string, string>>({});
const limit = ref(Math.max(PAGE_SIZE, props.conversations.limit));
const loadingList = ref(false);
const loadingSelection = ref(false);

/** Query string of the current list, as the list component builds it. */
const inboxList = ref<InstanceType<typeof InboxList> | null>(null);

function url(selected: number | null, query: Record<string, string>): string {
    return conversationsIndex.url({
        query: {
            ...query,
            ...(limit.value > PAGE_SIZE ? { limit: String(limit.value) } : {}),
            ...(selected !== null ? { c: String(selected) } : {}),
        },
    });
}

function currentQuery(): Record<string, string> {
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

function filter(query: Record<string, string>): void {
    listQuery.value = query;
    limit.value = PAGE_SIZE;
    router.get(url(props.selectedId, query), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: LIST_PROPS,
        onStart: () => (loadingList.value = true),
        onFinish: () => (loadingList.value = false),
    });
}

function loadMore(): void {
    if (loadingList.value || !props.conversations.has_more) {
        return;
    }

    limit.value = Math.min(250, props.conversations.limit + PAGE_SIZE);
    router.get(url(props.selectedId, currentQuery()), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['conversations'],
        onStart: () => (loadingList.value = true),
        onFinish: () => (loadingList.value = false),
    });
}

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

useLiveReload({
    channels: liveChannels,
    only: ['conversations', 'counts', 'viewCounts'],
    fallbackIntervalMs: 20_000,
    enabled: () => !loadingList.value,
});

/* The viewer's availability changed (another tab or device): follow it. */
useUserChannel({
    '.availability.changed': () =>
        afterUserVisits(() => router.reload({ only: ['availability'] })),
});

/*
 * The read marker moved: refresh the unread counters of the list. With
 * real-time the personal channel already signals it to every tab.
 */
const { state: realtimeState } = useRealtimeStatus();

function onRead(): void {
    if (realtimeState.value !== 'connected' && !loadingList.value) {
        router.reload({ only: ['conversations'] });
    }
}

/* The server decides how many rows it returned (it may cap the limit). */
watch(
    () => props.conversations.limit,
    (value) => (limit.value = Math.max(PAGE_SIZE, value)),
);

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

const title = computed(() =>
    props.conversation
        ? `Conversación con ${props.conversation.contact.name}`
        : 'Conversaciones',
);
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
                :conversations="conversations"
                :filters="filters"
                :counts="counts"
                :inbox="inbox"
                :view-counts="viewCounts"
                :statuses="statuses"
                :departments="departments"
                :agents="agents"
                :connections="connections"
                :tags="tags"
                :can-see-unrouted="canSeeUnrouted"
                :can-export="canExport"
                :availability="availability"
                :selected-id="selectedId"
                :loading="loadingList"
                @select="select"
                @filter="filter"
                @load-more="loadMore"
            />
        </div>

        <main
            v-show="showChat"
            class="relative min-h-0 min-w-0"
            :aria-busy="loadingSelection"
        >
            <ConversationChat
                v-if="conversation && window"
                :key="conversation.id"
                :conversation="conversation"
                :window="window"
                :timeline="timeline"
                :availability="availability"
                :transfer-options="transferOptions"
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
    </div>
</template>
