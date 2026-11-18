<script setup lang="ts">
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import {
    AtSign,
    Gauge,
    Hand,
    Headset,
    Inbox,
    ListFilter,
    LockKeyhole,
    MailWarning,
    MessageCircle,
    MessagesSquare,
    Paperclip,
    Plus,
    SearchX,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    onScopeDispose,
    ref,
    watch,
} from 'vue';
import ChatPaneEmpty from '@/components/chat/ChatPaneEmpty.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import FilterToggle from '@/components/filters/FilterToggle.vue';
import HighlightMatch from '@/components/filters/HighlightMatch.vue';
import type { FilterTabOption } from '@/components/filters/types';
import ListSkeleton from '@/components/filters/ListSkeleton.vue';
import { useRowMotion } from '@/components/filters/useRowMotion';
import { useSelectionGlider } from '@/components/filters/useSelectionGlider';
import ChatWindow from '@/components/team-chat/ChatWindow.vue';
import DeskRequestBar from '@/components/team-chat/DeskRequestBar.vue';
import DeskRequestItem from '@/components/team-chat/DeskRequestItem.vue';
import NewDirectDialog from '@/components/team-chat/NewDirectDialog.vue';
import NewGroupDialog from '@/components/team-chat/NewGroupDialog.vue';
import ThreadListItem from '@/components/team-chat/ThreadListItem.vue';
import ThreadPreview from '@/components/team-chat/ThreadPreview.vue';
import ThreadSettingsSheet from '@/components/team-chat/ThreadSettingsSheet.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { useChatViewport } from '@/composables/useChatViewport';
import { useGuidedTour } from '@/composables/useGuidedTour';
import { useLiveReload } from '@/composables/useLiveReload';
import { userChannelName } from '@/composables/useUserChannel';
import { formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    index as teamChatIndex,
    search as searchMessages,
} from '@/routes/team-chat';
import { show as showThread } from '@/routes/team-chat/threads';
import type {
    TeamChatFilters,
    TeamChatLimits,
    TeamDeskInbox,
    TeamDeskRole,
    TeamDeskTab,
    TeamMessagePage,
    TeamSearchResult,
    TeamThreadCounts,
    TeamThreadDetail,
    TeamThreadFilter,
    TeamThreadList,
    TeamThreadSummary,
} from '@/types/team-chat';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Chat interno', href: teamChatIndex() }],
    },
});

const props = defineProps<{
    threads: TeamThreadList;
    filters: TeamChatFilters;
    activeThread: TeamThreadDetail | null;
    messages: TeamMessagePage | null;
    around: number | null;
    unreadTotal: number;
    /** Numbers of «No leídos» and «Menciones» in the scope. */
    threadCounts?: TeamThreadCounts;
    can: { createGroup: boolean; startDirect?: boolean };
    limits: TeamChatLimits;
    /** «Mesa de control»: requester, auditor, or null when it does not apply. */
    desk?: TeamDeskRole;
    /** The auditor's desk section, when it is open (`desk` in the URL). */
    deskInbox?: TeamDeskInbox | null;
    /** Requests waiting, for the auditor's «Mesa de control» badge. */
    deskPending?: number | null;
}>();

/* Phones: the page follows the visible area (on-screen keyboard) and never bounces. */
useChatViewport();

/*
 * Phones: a chat opened from the list sits on top of the list's history
 * entry, so the chat's arrow goes back through history, exactly like the
 * system back gesture (Android, iOS swipe), instead of stacking the list
 * again. A chat reached by history, a link or a reload keeps the link.
 */
const threadEntryOnList = ref(false);
let poppedState = false;

function onPopState(): void {
    poppedState = true;
    threadEntryOnList.value = false;
}

globalThis.addEventListener?.('popstate', onPopState);
onScopeDispose(() => globalThis.removeEventListener?.('popstate', onPopState));

watch(
    () => props.activeThread?.id ?? null,
    (id, previous) => {
        threadEntryOnList.value =
            id !== null && previous === null && !poppedState;
        poppedState = false;
    },
);

/*
 * «Mesa de control». A requester's chat is a single contact, her desk
 * thread: no search, filters or new chats. An auditor switches between her
 * chats and the desk section (Pendientes, Mías, Completadas), which the URL
 * keeps (`desk`), also while a request is open.
 */
const isRequester = computed(() => props.desk?.role === 'requester');
const isAuditor = computed(() => props.desk?.role === 'auditor');
const canStartDirect = computed(() => props.can.startDirect !== false);
const deskTab = ref<TeamDeskTab | null>(props.desk?.tab ?? null);

watch(
    () => props.desk?.tab,
    (tab) => (deskTab.value = tab ?? null),
);

const DESK_PROPS = ['desk', 'deskInbox', 'deskPending'];

function openDeskSection(tab: TeamDeskTab | null): void {
    deskTab.value = tab;
    router.get(currentPath(), tab === null ? listQuery() : { desk: tab }, {
        only: [...DESK_PROPS, 'threads', 'filters', 'threadCounts'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loadingList.value = true),
        onFinish: () => (loadingList.value = false),
    });
}

const sectionTabs = computed<FilterTabOption<'chats' | 'desk'>[]>(() => [
    { value: 'chats', label: 'Chats', title: 'Tus chats privados y grupos' },
    {
        value: 'desk',
        label: 'Mesa de control',
        count: props.deskPending ?? 0,
        attention: (props.deskPending ?? 0) > 0,
        srLabel: `${props.deskPending ?? 0} solicitudes en espera`,
        title: 'Solicitudes de la mesa de control',
        attrs: { 'data-desk-section': '' },
    },
]);

const deskTabs = computed<FilterTabOption<TeamDeskTab>[]>(() => {
    const counts = props.deskInbox?.counts ?? {
        pending: props.deskPending ?? 0,
        mine: 0,
        completed: 0,
    };

    return [
        {
            value: 'pending',
            label: 'Pendientes',
            count: counts.pending,
            attention: counts.pending > 0,
            srLabel: `${counts.pending} en espera`,
            title: 'Solicitudes en espera, la más reciente arriba',
        },
        {
            value: 'mine',
            label: 'Mías',
            count: counts.mine,
            srLabel: `${counts.mine} que atiendes`,
            title: 'Las solicitudes que atiendes, la que más espera arriba',
        },
        {
            value: 'completed',
            label: 'Completadas',
            count: counts.completed,
            title: 'Solicitudes completadas, la más reciente arriba',
        },
    ];
});

const deskCapacity = computed(() => {
    const capacity = props.deskInbox?.capacity;

    if (!capacity) {
        return null;
    }

    return capacity.limit === null
        ? {
              text: `Atiendes ${capacity.load}, sin límite`,
              title: `Atiendes ${capacity.load} ${capacity.load === 1 ? 'solicitud' : 'solicitudes'} (sin límite).`,
          }
        : {
              text: `Atiendes ${capacity.load} de ${capacity.limit}`,
              title: `Atiendes ${capacity.load} de ${capacity.limit} solicitudes simultáneas.`,
          };
});

const deskEmptyCopy = computed(
    () =>
        ({
            pending: {
                title: 'No hay solicitudes en espera',
                description: 'Las nuevas solicitudes aparecerán aquí.',
            },
            mine: {
                title: 'No atiendes ninguna solicitud',
                description:
                    'Acepta una solicitud de «Pendientes» para atenderla.',
            },
            completed: {
                title: 'Sin solicitudes completadas',
                description: 'Aquí verás las que se completen.',
            },
        })[deskTab.value ?? 'pending'],
);

/* Back from an open request returns to the same desk tab. */
const backQuery = computed((): Record<string, string> =>
    deskTab.value === null ? {} : { desk: deskTab.value },
);

/* The same canvas as the open chat (ChatWindow). */
const CHAT_CANVAS =
    'bg-surface-sunken bg-[radial-gradient(color-mix(in_oklch,var(--primary)_14%,transparent)_1px,transparent_1.5px)] bg-size-[20px_20px]';

/*
 * The list's filters follow the inbox pattern (components/filters): the
 * search box («/» or Ctrl+K), the scope as tabs (Todos, Privados, Grupos,
 * Archivados) and the toggles that combine with it («No leídos»,
 * «Menciones») with their counters. The URL holds them (replace visits) and
 * the last choice is remembered on this device, restored only on a clean
 * visit (no filter in the URL).
 */
const FILTER_STORAGE_KEY = 'team-chat.filters';
/** Before the toggles existed only the chosen chip was remembered. */
const LEGACY_FILTER_STORAGE_KEY = 'team-chat.filter';
const PAGE_STEP = 50;

type TeamScope = Exclude<TeamThreadFilter, 'unread' | 'mentions'>;

const SCOPES: TeamScope[] = ['all', 'direct', 'groups', 'archived'];

const scopeOf = (value: TeamThreadFilter): TeamScope =>
    (SCOPES as string[]).includes(value) ? (value as TeamScope) : 'all';

const search = ref(props.filters.search);
const filter = ref<TeamScope>(scopeOf(props.filters.filter));
const unreadOnly = ref(
    props.filters.unread === true || props.filters.filter === 'unread',
);
const mentionsOnly = ref(
    props.filters.mentions === true || props.filters.filter === 'mentions',
);

/* Follow the server when it normalizes the filters. */
watch(
    () => props.filters,
    (filters) => {
        filter.value = scopeOf(filters.filter);
        unreadOnly.value =
            filters.unread === true || filters.filter === 'unread';
        mentionsOnly.value =
            filters.mentions === true || filters.filter === 'mentions';
    },
);

const scopeTabs = computed<FilterTabOption<TeamScope>[]>(() => [
    { value: 'all', label: 'Todos', title: 'Todos tus chats sin archivar' },
    {
        value: 'direct',
        label: 'Privados',
        title: 'Chats uno a uno con alguien del equipo',
    },
    { value: 'groups', label: 'Grupos', title: 'Los grupos en los que estás' },
    {
        value: 'archived',
        label: 'Archivados',
        title: 'Grupos archivados, con su historial',
    },
]);

/* Mentions only exist in groups. */
const showMentions = computed(() => filter.value !== 'direct');
const counts = computed<TeamThreadCounts>(
    () => props.threadCounts ?? { unread: 0, mentions: 0 },
);

/** A search or a toggle narrows the list: an empty result means "nothing matches". */
const narrowed = computed(
    () =>
        search.value.trim() !== '' ||
        unreadOnly.value ||
        (mentionsOnly.value && showMentions.value),
);
const hasFilters = computed(() => narrowed.value || filter.value !== 'all');
const loadingList = ref(false);
const newDirectOpen = ref(false);
const newGroupOpen = ref(false);
const settingsOpen = ref(false);
const messageResults = ref<TeamSearchResult[]>([]);
const moreMessageResults = ref(false);
const searchingMessages = ref(false);
const searchHttp = useHttp<
    Record<string, never>,
    { data: TeamSearchResult[]; has_more: boolean }
>({});
const reachedListLimit = computed(
    () =>
        props.threads.has_more &&
        props.filters.limit >= props.limits.maxThreads,
);

function loadMoreThreads(): void {
    reloadList(
        Math.min(props.limits.maxThreads, props.filters.limit + PAGE_STEP),
    );
}

const currentPath = (): string => window.location.pathname;

function listQuery(
    extra: Record<string, string | number> = {},
): Record<string, string | number> {
    const query: Record<string, string | number> = { ...extra };

    if (search.value.trim() !== '') {
        query.search = search.value.trim();
    }

    if (filter.value !== 'all') {
        query.filter = filter.value;
    }

    if (unreadOnly.value) {
        query.unread = 1;
    }

    if (mentionsOnly.value && showMentions.value) {
        query.mentions = 1;
    }

    return query;
}

const linkQuery = computed(() => listQuery());

/*
 * Preview from the list (long press, right click, «Vista previa»): it only
 * reads; «Abrir chat» opens it like a click on the row.
 */
const previewThread = ref<TeamThreadSummary | null>(null);
/* The row as the list has it now (its counter follows live changes). */
const previewRow = computed(
    () =>
        props.threads.threads.find(
            (row) => row.id === previewThread.value?.id,
        ) ?? previewThread.value,
);

function openFromPreview(id: number): void {
    previewThread.value = null;
    router.visit(showThread(id, { query: linkQuery.value }), {
        preserveState: true,
        preserveScroll: true,
    });
}

function reloadList(limit?: number): void {
    router.get(currentPath(), listQuery(limit ? { limit } : {}), {
        only: ['threads', 'filters', 'threadCounts'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loadingList.value = true),
        onFinish: () => (loadingList.value = false),
    });
}

type RememberedFilters = {
    filter: TeamScope;
    unread: boolean;
    mentions: boolean;
};

function rememberFilters(): void {
    try {
        window.localStorage.setItem(
            FILTER_STORAGE_KEY,
            JSON.stringify({
                filter: filter.value,
                unread: unreadOnly.value,
                mentions: mentionsOnly.value,
            } satisfies RememberedFilters),
        );
    } catch {
        // Storage may be unavailable (private mode): the filters are not remembered.
    }
}

function chooseScope(value: TeamScope): void {
    // A second click on the chosen tab goes back to «Todos».
    filter.value = filter.value === value ? 'all' : value;
    rememberFilters();
    reloadList();
}

function toggleUnread(): void {
    unreadOnly.value = !unreadOnly.value;
    rememberFilters();
    reloadList();
}

function toggleMentions(): void {
    mentionsOnly.value = !mentionsOnly.value;
    rememberFilters();
    reloadList();
}

function clearFilters(): void {
    filter.value = 'all';
    unreadOnly.value = false;
    mentionsOnly.value = false;
    rememberFilters();

    if (search.value !== '') {
        // The debounced search watcher reloads the list.
        search.value = '';
    } else {
        reloadList();
    }
}

/* A clean visit restores the filters this device used last. */
function rememberedFilters(): RememberedFilters | null {
    const saved = window.localStorage.getItem(FILTER_STORAGE_KEY);

    if (saved !== null) {
        const value = JSON.parse(saved) as Partial<RememberedFilters>;

        return {
            filter: scopeOf((value.filter ?? 'all') as TeamThreadFilter),
            unread: value.unread === true,
            mentions: value.mentions === true,
        };
    }

    const legacy = window.localStorage.getItem(LEGACY_FILTER_STORAGE_KEY);

    return legacy === null
        ? null
        : {
              filter: scopeOf(legacy as TeamThreadFilter),
              unread: legacy === 'unread',
              mentions: false,
          };
}

try {
    const parameters = new URLSearchParams(window.location.search);
    // The desk section (or a requester's single contact) has no chat filters.
    const isUrlFiltered = ['filter', 'unread', 'mentions', 'desk'].some((key) =>
        parameters.has(key),
    );
    const remembered =
        isUrlFiltered || isRequester.value ? null : rememberedFilters();

    if (
        remembered &&
        (remembered.filter !== filter.value ||
            remembered.unread !== unreadOnly.value ||
            remembered.mentions !== mentionsOnly.value)
    ) {
        filter.value = remembered.filter;
        unreadOnly.value = remembered.unread;
        mentionsOnly.value = remembered.mentions;
        reloadList();
    }
} catch {
    // Storage may be unavailable or hold something else: use the defaults.
}

watchDebounced(
    search,
    () => {
        reloadList();
        void runMessageSearch();
    },
    { debounce: 300 },
);

async function runMessageSearch(): Promise<void> {
    const term = search.value.trim();

    if (term.length < 2) {
        messageResults.value = [];
        moreMessageResults.value = false;

        return;
    }

    searchingMessages.value = true;

    try {
        const response = await searchHttp.get(
            searchMessages.url({ query: { q: term } }),
            { onHttpException: () => false },
        );
        messageResults.value = response.data;
        moreMessageResults.value = response.has_more;
    } catch {
        messageResults.value = [];
        moreMessageResults.value = false;
    } finally {
        searchingMessages.value = false;
    }
}

/* Arrow down from the search box: the first chat of the list. */
const threadList = ref<{ $el: HTMLElement } | null>(null);

function focusFirstThread(): void {
    threadList.value?.$el.querySelector<HTMLElement>('a[href]')?.focus();
}

/* Arrow keys move between the chats; up from the first one returns to the search. */
const searchBox = ref<InstanceType<typeof FilterSearch> | null>(null);

function onThreadsKeydown(event: KeyboardEvent): void {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const links = [
        ...(threadList.value?.$el.querySelectorAll<HTMLElement>('a[href]') ??
            []),
    ];
    const index = links.indexOf(document.activeElement as HTMLElement);

    if (index === -1) {
        return;
    }

    event.preventDefault();

    if (event.key === 'ArrowUp' && index === 0) {
        searchBox.value?.focus();

        return;
    }

    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? links.length - 1
              : event.key === 'ArrowDown'
                ? Math.min(links.length - 1, index + 1)
                : Math.max(0, index - 1);

    links[next]?.focus();
}

function openResult(result: TeamSearchResult): void {
    router.visit(
        showThread.url(result.thread_id, {
            query: { ...linkQuery.value, around: result.message_id },
        }),
    );
}

/** Props that carry the unread totals (also brought by full visits). */
const UNREAD_PROPS = ['unreadTotal', 'teamChatUnread'];
/** performance.now() when the latest visit bringing the totals started. */
let unreadVisitStartedAt = Number.NEGATIVE_INFINITY;
/** Visits bringing the totals still running, with when each started. */
const unreadVisitsInFlight = new Map<object, number>();
/** requestedAt of the latest read whose totals were shown. */
let readAppliedAt = Number.NEGATIVE_INFINITY;

const stopUnreadVisitWatch = router.on('start', (event) => {
    const visit = event.detail.visit;
    const only = visit.only;

    if (only.length === 0 || only.some((prop) => UNREAD_PROPS.includes(prop))) {
        unreadVisitStartedAt = performance.now();
        unreadVisitsInFlight.set(visit, unreadVisitStartedAt);
    }
});

/*
 * A visit that started before a read was sent carries totals computed
 * before it: when it lands after the read was shown it puts the old (higher)
 * count back, so a live reload brings the current one.
 */
const stopUnreadVisitFinish = router.on('finish', (event) => {
    const startedAt = unreadVisitsInFlight.get(event.detail.visit);

    if (startedAt === undefined) {
        return;
    }

    unreadVisitsInFlight.delete(event.detail.visit);

    if (startedAt < readAppliedAt) {
        liveReload.schedule();
    }
});

/*
 * The chat being opened: its row lights up and the pane shows the shape of
 * a chat at once, instead of the previous chat staying on screen until the
 * new one arrives.
 */
const openingThreadId = ref<number | null>(null);
const openingVisits = new Set<object>();

const stopOpeningStart = router.on('start', (event) => {
    const visit = event.detail.visit;
    const match = /\/team-chat\/threads\/(\d+)$/.exec(visit.url.pathname);

    if (
        !match ||
        visit.method !== 'get' ||
        visit.only.length > 0 ||
        visit.prefetch
    ) {
        return;
    }

    const id = Number(match[1]);

    // Jumping to a message of the open chat keeps it on screen.
    if (id !== props.activeThread?.id) {
        openingVisits.add(visit);
        openingThreadId.value = id;
    }
});

const stopOpeningFinish = router.on('finish', (event) => {
    if (openingVisits.delete(event.detail.visit) && openingVisits.size === 0) {
        openingThreadId.value = null;
    }
});

onBeforeUnmount(() => {
    stopUnreadVisitWatch();
    stopUnreadVisitFinish();
    stopOpeningStart();
    stopOpeningFinish();
});

/**
 * The open chat was marked as read: show the totals of that answer at once,
 * unless a visit bringing the totals started after the read was sent. Its
 * answer can be newer (another chat got a message meanwhile) and an older
 * read answer must never overwrite it, so a live reload settles the values.
 * Visits that started before the read and are still running are settled
 * when they finish (see above).
 */
function onUnreadChanged(total: number, requestedAt: number): void {
    if (unreadVisitStartedAt > requestedAt) {
        liveReload.schedule();

        return;
    }

    readAppliedAt = Math.max(readAppliedAt, requestedAt);

    router.replaceProp('teamChatUnread', total);
    router.replaceProp('unreadTotal', total);

    const activeId = props.activeThread?.id;

    if (activeId !== undefined) {
        router.replaceProp('threads', (current: unknown) => {
            const list = current as TeamThreadList;

            return {
                ...list,
                threads: list.threads.map((thread) =>
                    thread.id === activeId ? { ...thread, unread: 0 } : thread,
                ),
            };
        });
    }
}

watch(
    () => props.activeThread?.id,
    () => {
        settingsOpen.value = false;
    },
);

// No PageHeader here: the global help button offers this module's tour
// and guide.
onBeforeUnmount(useGuidedTour().registerPageModule('chat-interno'));

const liveReload = useLiveReload({
    channels: () => {
        const user = userChannelName();

        return user ? [{ name: user, events: ['team-chat.changed'] }] : [];
    },
    only: [
        'threads',
        'unreadTotal',
        'teamChatUnread',
        'threadCounts',
        'deskInbox',
        'deskPending',
    ],
    fallbackIntervalMs: 20_000,
});

/* Empty because of the filters: say which ones, offer to clear them. */
const narrowedCopy = computed(() => {
    if (search.value.trim() !== '') {
        return {
            title: 'No hay chats con estos filtros',
            description: 'Prueba otro nombre o limpia los filtros.',
        };
    }

    if (mentionsOnly.value && showMentions.value) {
        return {
            title: 'Nadie te ha mencionado',
            description: 'Aquí verás tus menciones (@) sin leer.',
        };
    }

    return {
        title: 'Estás al día',
        description: 'Nada sin leer con estos filtros.',
    };
});

/* Rows animate only on small live changes (a chat moves up, a new one). */
const threadMotion = useRowMotion(
    () => props.threads.threads,
    () => props.filters,
);

/* The desk rows animate like the chats (a request arrives, one is accepted). */
const deskMotion = useRowMotion(
    () => props.deskInbox?.threads ?? [],
    () => props.deskInbox?.tab,
);

const emptyListCopy = computed(() => {
    return {
        archived: {
            title: 'Sin grupos archivados',
            description: 'Aquí verás los grupos archivados.',
        },
        direct: {
            title: 'Sin chats privados',
            description: 'Inicia un chat privado con alguien de tu equipo.',
        },
        groups: {
            title: 'Sin grupos',
            description: 'Aún no participas en ningún grupo.',
        },
        all: {
            title: 'Aún no tienes chats',
            description: 'Escribe a alguien de tu equipo para empezar.',
        },
    }[filter.value];
});

/* The chat list slides in once, when the screen opens. */
const firstPaint = ref(true);

/* The header casts a shadow while chats pass under it. */
const listScrolled = ref(false);

function onListScroll(event: Event): void {
    listScrolled.value = (event.target as HTMLElement).scrollTop > 2;
}

/* The open chat sits on one glider that springs between rows. */
const threadRail = ref<HTMLElement | null>(null);
const glider = useSelectionGlider(threadRail, () => [
    props.activeThread?.id,
    openingThreadId.value,
    props.threads.threads.map((thread) => thread.id),
]);

onMounted(() => globalThis.setTimeout(() => (firstPaint.value = false), 900));
</script>

<template>
    <Head
        :title="
            activeThread
                ? `${activeThread.title} · Chat interno`
                : 'Chat interno'
        "
    />

    <div
        class="flex h-[calc(var(--app-height,100dvh)-3.5rem)] min-h-0 sm:h-[calc(var(--app-height,100dvh)-4rem)] [@media(max-height:32rem)]:h-[calc(var(--app-height,100dvh)-3rem)]!"
    >
        <aside
            :class="
                cn(
                    'min-h-0 w-full shrink-0 flex-col border-r bg-background lg:flex lg:w-80 xl:w-96 2xl:w-[26rem]',
                    activeThread || openingThreadId !== null
                        ? 'hidden'
                        : 'flex',
                )
            "
            aria-label="Chats internos"
        >
            <div
                class="list-header grid gap-2 border-b border-border/60 bg-background px-3 pt-2.5 pb-2"
                :data-scrolled="listScrolled ? '' : undefined"
            >
                <!-- The page header already names the module: this title is for screen readers. -->
                <h1 class="sr-only">
                    Chat interno<template v-if="unreadTotal > 0"
                        >, {{ unreadTotal }} sin leer</template
                    >
                </h1>

                <!-- An auditor: her chats or the «Mesa de control» section. -->
                <div v-if="isAuditor" class="-mx-1 min-w-0">
                    <FilterTabs
                        :options="sectionTabs"
                        :model-value="deskTab === null ? 'chats' : 'desk'"
                        label="Sección del chat interno"
                        class="px-1"
                        @update:model-value="
                            (section) =>
                                openDeskSection(
                                    section === 'desk' ? 'pending' : null,
                                )
                        "
                    />
                </div>

                <!-- A requester: one contact, the desk. -->
                <p
                    v-if="isRequester"
                    class="flex items-center gap-2 px-1 py-1 text-sm text-muted-foreground"
                >
                    <Headset
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    Tus solicitudes van a la mesa de control
                </p>

                <template v-else-if="deskTab !== null">
                    <div class="-mx-1 min-w-0">
                        <FilterTabs
                            :options="deskTabs"
                            :model-value="deskTab"
                            label="Solicitudes de la mesa de control"
                            class="px-1"
                            @update:model-value="openDeskSection"
                        />
                    </div>
                    <p
                        v-if="deskCapacity"
                        class="ml-1 inline-flex w-fit items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                        :title="deskCapacity.title"
                        data-desk-capacity
                    >
                        <Gauge class="size-3.5 shrink-0" aria-hidden="true" />
                        {{ deskCapacity.text }}
                    </p>
                </template>

                <div v-else class="flex items-center gap-1.5">
                    <FilterSearch
                        ref="searchBox"
                        v-model="search"
                        placeholder="Buscar chats y mensajes"
                        label="Buscar chats y mensajes"
                        title="Busca por nombre de la persona o del grupo, y en los mensajes. Atajo: / o Ctrl+K"
                        :maxlength="80"
                        :loading="loadingList || searchingMessages"
                        data-tour="team-chat.search"
                        @next="focusFirstThread"
                    />

                    <DropdownMenu v-if="can.createGroup">
                        <DropdownMenuTrigger as-child>
                            <Button
                                class="h-9 shrink-0 gap-1.5 px-2.5"
                                data-tour="team-chat.new"
                            >
                                <Plus /> Nuevo
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="newDirectOpen = true">
                                <UserRound /> Chat privado
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="newGroupOpen = true">
                                <UsersRound /> Grupo
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button
                        v-else-if="canStartDirect"
                        class="h-9 shrink-0 gap-1.5 px-2.5"
                        data-tour="team-chat.new"
                        @click="newDirectOpen = true"
                    >
                        <Plus /> Nuevo chat
                    </Button>
                </div>

                <!-- One strip: the scopes as tabs, then the quick toggles. -->
                <div
                    v-if="!isRequester && deskTab === null"
                    class="-mx-1 min-w-0"
                >
                    <FilterTabs
                        :options="scopeTabs"
                        :model-value="filter"
                        label="Filtrar chats"
                        class="px-1"
                        @update:model-value="chooseScope"
                    >
                        <template #after>
                            <span
                                class="mx-0.5 h-4 w-px shrink-0 bg-border"
                                aria-hidden="true"
                            />
                            <div
                                class="flex shrink-0 items-center gap-1"
                                role="group"
                                aria-label="Filtros rápidos"
                            >
                                <FilterToggle
                                    :pressed="unreadOnly"
                                    label="No leídos"
                                    :icon="MailWarning"
                                    :count="counts.unread"
                                    tone="primary"
                                    :sr-label="
                                        counts.unread === 1
                                            ? '1 chat con mensajes sin leer'
                                            : `${counts.unread} chats con mensajes sin leer`
                                    "
                                    title="Solo los chats con mensajes que aún no has leído"
                                    data-team-filter="unread"
                                    @toggle="toggleUnread"
                                />
                                <FilterToggle
                                    v-if="showMentions"
                                    :pressed="mentionsOnly"
                                    label="Menciones"
                                    :icon="AtSign"
                                    :count="counts.mentions"
                                    tone="warning"
                                    :sr-label="
                                        counts.mentions === 1
                                            ? '1 grupo te menciona'
                                            : `${counts.mentions} grupos te mencionan`
                                    "
                                    title="Solo los grupos con mensajes sin leer que te mencionan (@)"
                                    data-team-filter="mentions"
                                    @toggle="toggleMentions"
                                />
                                <button
                                    v-if="hasFilters"
                                    type="button"
                                    class="inline-flex h-7 shrink-0 items-center rounded-md px-1.5 text-xs font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-9"
                                    data-inbox-clear
                                    data-filter-clear
                                    @click="clearFilters"
                                >
                                    Limpiar
                                </button>
                            </div>
                        </template>
                    </FilterTabs>
                </div>
            </div>

            <div
                class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain p-1.5"
                :aria-busy="loadingList"
                @scroll.passive="onListScroll"
            >
                <div
                    v-if="loadingList && threads.threads.length > 0"
                    class="sticky top-0 z-10 -mx-1.5 -mt-1.5 mb-1 pane-progress"
                    aria-hidden="true"
                />
                <!-- «Mesa de control» section of an auditor. -->
                <template v-if="deskTab !== null">
                    <ListSkeleton
                        v-if="!deskInbox || deskInbox.tab !== deskTab"
                        :rows="5"
                        class="-m-1.5"
                    />
                    <FilteredEmpty
                        v-else-if="deskInbox.threads.length === 0"
                        :narrowed="false"
                        narrowed-title=""
                        :icon="Inbox"
                        :title="deskEmptyCopy.title"
                        :description="deskEmptyCopy.description"
                    />
                    <nav
                        v-else
                        aria-label="Solicitudes de la mesa de control"
                        data-desk-list
                    >
                        <TransitionGroup
                            tag="div"
                            :name="deskMotion"
                            class="relative grid grid-cols-1 gap-px"
                            :class="loadingList && 'opacity-60'"
                        >
                            <DeskRequestItem
                                v-for="row in deskInbox.threads"
                                :key="row.id"
                                :row="row"
                                :tab="deskInbox.tab"
                                :active="
                                    openingThreadId === null &&
                                    activeThread?.id === row.id
                                "
                                :opening="openingThreadId === row.id"
                            />
                        </TransitionGroup>
                        <p
                            v-if="deskInbox.has_more"
                            class="px-3 py-2 text-xs text-muted-foreground"
                        >
                            Se muestran las {{ deskInbox.threads.length }}
                            primeras solicitudes.
                        </p>
                    </nav>
                </template>
                <ListSkeleton
                    v-else-if="loadingList && threads.threads.length === 0"
                    :rows="6"
                    class="-m-1.5"
                />
                <FilteredEmpty
                    v-else-if="threads.threads.length === 0"
                    :narrowed="narrowed"
                    :narrowed-title="narrowedCopy.title"
                    :narrowed-description="narrowedCopy.description"
                    :icon="MessagesSquare"
                    :title="emptyListCopy.title"
                    :description="emptyListCopy.description"
                    @clear="clearFilters"
                >
                    <Button
                        v-if="filter !== 'all'"
                        variant="outline"
                        size="sm"
                        @click="clearFilters"
                        >Ver todos los chats</Button
                    >
                </FilteredEmpty>
                <nav
                    v-else
                    ref="threadRail"
                    class="list-rail"
                    aria-label="Lista de chats"
                    data-tour="team-chat.threads"
                    @keydown="onThreadsKeydown"
                >
                    <span
                        class="list-glider"
                        :data-placed="glider.placed.value ? '' : undefined"
                        :style="glider.style.value"
                        aria-hidden="true"
                        data-list-glider
                    />
                    <TransitionGroup
                        ref="threadList"
                        tag="div"
                        :name="threadMotion"
                        class="relative z-[1] grid grid-cols-1 gap-px motion-safe:transition-opacity motion-safe:duration-(--duration-fast)"
                        :class="[
                            firstPaint && 'reveal-stagger',
                            loadingList && 'opacity-60',
                        ]"
                    >
                        <ThreadListItem
                            v-for="thread in threads.threads"
                            :key="thread.id"
                            :thread="thread"
                            :active="
                                openingThreadId === null &&
                                activeThread?.id === thread.id
                            "
                            :opening="openingThreadId === thread.id"
                            :query="linkQuery"
                            :highlight="filters.search"
                            @preview="(row) => (previewThread = row)"
                        />
                    </TransitionGroup>
                </nav>

                <p v-if="deskTab !== null" class="sr-only" />
                <p
                    v-else-if="reachedListLimit"
                    class="flex items-center gap-1.5 px-3 py-2 text-xs text-muted-foreground"
                    title="Usa la búsqueda o los filtros para encontrar otros."
                >
                    <ListFilter class="size-3.5 shrink-0" aria-hidden="true" />
                    Solo los {{ limits.maxThreads }} chats más recientes
                </p>
                <div
                    v-else-if="threads.has_more"
                    class="flex justify-center py-2"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        :loading="loadingList"
                        @click="loadMoreThreads"
                    >
                        Ver más chats
                    </Button>
                </div>

                <section
                    v-if="deskTab === null && search.trim().length >= 2"
                    class="mt-3 border-t border-border/70 pt-3"
                    aria-label="Resultados en mensajes"
                >
                    <h2
                        class="px-3.5 pb-1.5 text-xs font-semibold text-muted-foreground"
                    >
                        En mensajes
                    </h2>
                    <div
                        v-if="searchingMessages"
                        class="grid gap-2 px-3.5 py-2"
                        role="status"
                    >
                        <span class="sr-only">Buscando…</span>
                        <span class="list-shimmer h-3.5 w-1/2 rounded-full" />
                        <span
                            class="list-shimmer h-3 w-full rounded-full"
                            style="--i: 1"
                        />
                        <span
                            class="list-shimmer h-3.5 w-2/5 rounded-full"
                            style="--i: 2"
                        />
                        <span
                            class="list-shimmer h-3 w-4/5 rounded-full"
                            style="--i: 3"
                        />
                    </div>
                    <p
                        v-else-if="messageResults.length === 0"
                        class="flex items-center gap-1.5 px-3 py-2 text-xs text-muted-foreground"
                    >
                        <SearchX class="size-3.5 shrink-0" aria-hidden="true" />
                        Ningún mensaje coincide
                    </p>
                    <ul v-else class="grid gap-0.5">
                        <li
                            v-for="result in messageResults"
                            :key="result.message_id"
                        >
                            <button
                                type="button"
                                class="list-row grid w-full gap-0.5 px-3.5 py-2 text-left outline-none"
                                @click="openResult(result)"
                            >
                                <span class="flex items-center gap-2 text-xs">
                                    <span
                                        class="min-w-0 flex-1 truncate font-semibold"
                                        >{{ result.thread_title }}</span
                                    >
                                    <span
                                        class="shrink-0 text-muted-foreground"
                                        >{{
                                            formatRelative(result.created_at)
                                        }}</span
                                    >
                                </span>
                                <span
                                    class="line-clamp-2 text-xs text-muted-foreground"
                                >
                                    <span class="font-medium text-foreground"
                                        >{{ result.author }}:</span
                                    >
                                    <HighlightMatch
                                        :text="result.excerpt"
                                        :query="search"
                                    />
                                </span>
                            </button>
                        </li>
                    </ul>
                    <p
                        v-if="!searchingMessages && moreMessageResults"
                        class="flex items-center gap-1.5 px-3 py-2 text-xs text-muted-foreground"
                        title="Escribe más palabras para acotar la búsqueda."
                    >
                        <ListFilter
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Solo los {{ messageResults.length }} más recientes
                    </p>
                </section>
            </div>
        </aside>

        <main
            :class="
                cn(
                    'min-h-0 min-w-0 flex-1 flex-col',
                    activeThread || openingThreadId !== null
                        ? 'flex'
                        : 'hidden lg:flex',
                )
            "
        >
            <!-- A chat is on its way: its shape, at once. -->
            <div
                v-if="openingThreadId !== null"
                class="flex h-full min-h-0 flex-col"
                role="status"
                aria-label="Abriendo el chat…"
            >
                <div
                    class="flex h-14 shrink-0 items-center gap-3 border-b px-4 sm:h-16 sm:px-5"
                >
                    <Skeleton class="size-10 rounded-full" />
                    <div class="grid gap-1.5">
                        <Skeleton class="h-4 w-40" />
                        <Skeleton class="h-3 w-24" />
                    </div>
                </div>
                <div
                    class="flex min-h-0 flex-1 flex-col justify-end gap-2 overflow-hidden bg-surface-sunken px-3 pb-4 sm:px-5"
                >
                    <Skeleton class="h-10 w-56 rounded-2xl" />
                    <Skeleton class="h-10 w-80 max-w-[75%] rounded-2xl" />
                    <Skeleton
                        class="h-10 w-64 max-w-[70%] self-end rounded-2xl bg-primary/20"
                    />
                    <Skeleton class="h-16 w-72 max-w-[75%] rounded-2xl" />
                    <Skeleton
                        class="h-10 w-40 self-end rounded-2xl bg-primary/20"
                    />
                </div>
                <div class="shrink-0 border-t px-2 py-2.5 sm:px-5 sm:py-3">
                    <Skeleton class="h-12 w-full rounded-2xl" />
                </div>
            </div>
            <template v-else-if="activeThread && messages">
                <ChatWindow
                    :key="`${activeThread.id}-${around ?? 'latest'}`"
                    :thread="activeThread"
                    :page="messages"
                    :around="around"
                    :limits="limits"
                    :back-query="backQuery"
                    :back-by-history="threadEntryOnList"
                    @open-settings="settingsOpen = true"
                    @unread-changed="onUnreadChanged"
                >
                    <template v-if="activeThread.type === 'desk'" #banner>
                        <DeskRequestBar :thread="activeThread" />
                    </template>
                </ChatWindow>
                <ThreadSettingsSheet
                    v-model:open="settingsOpen"
                    :thread="activeThread"
                />
            </template>
            <!-- Nothing open: stated at the top-left corner of the pane. -->
            <div
                v-else
                class="flex-1 overflow-y-auto p-6 sm:p-10"
                :class="CHAT_CANVAS"
            >
                <!-- A requester: her only contact is the desk. -->
                <ChatPaneEmpty
                    v-if="isRequester"
                    :icon="Headset"
                    title="Mesa de control"
                    hint="Envía tus solicitudes con fotos, videos o documentos"
                    :hint-icon="Paperclip"
                >
                    <Button v-if="desk?.thread_id" as-child>
                        <Link :href="showThread(desk.thread_id)">
                            <MessageCircle /> Escribir a la mesa de control
                        </Link>
                    </Button>
                </ChatPaneEmpty>
                <ChatPaneEmpty
                    v-else-if="deskTab !== null"
                    :icon="Headset"
                    :title="
                        (deskPending ?? 0) > 0
                            ? deskPending === 1
                                ? '1 solicitud en espera'
                                : `${deskPending} solicitudes en espera`
                            : 'Elige una solicitud'
                    "
                    hint="Acepta una de «Pendientes» para atenderla"
                    :hint-icon="Hand"
                />
                <ChatPaneEmpty
                    v-else
                    :icon="MessageCircle"
                    :title="
                        unreadTotal > 0
                            ? unreadTotal === 1
                                ? 'Tienes 1 mensaje sin leer'
                                : `Tienes ${unreadTotal} mensajes sin leer`
                            : 'Elige un chat'
                    "
                    hint="Privado para tu equipo, nunca llega a clientes"
                    :hint-icon="LockKeyhole"
                >
                    <Button v-if="canStartDirect" @click="newDirectOpen = true">
                        <Plus /> Nuevo chat privado
                    </Button>
                    <Button
                        v-if="can.createGroup"
                        variant="outline"
                        @click="newGroupOpen = true"
                    >
                        <UsersRound /> Nuevo grupo
                    </Button>
                </ChatPaneEmpty>
            </div>
        </main>

        <ThreadPreview
            :thread="previewRow"
            @close="previewThread = null"
            @open="openFromPreview"
        />

        <NewDirectDialog v-if="canStartDirect" v-model:open="newDirectOpen" />
        <NewGroupDialog
            v-if="can.createGroup"
            v-model:open="newGroupOpen"
            :max-participants="limits.groupMaxParticipants"
        />
    </div>
</template>
