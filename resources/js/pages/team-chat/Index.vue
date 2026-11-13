<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import {
    MessageCircle,
    MessagesSquare,
    Plus,
    Search,
    UserRound,
    UsersRound,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import ChatWindow from '@/components/team-chat/ChatWindow.vue';
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
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
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
    TeamMessagePage,
    TeamSearchResult,
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
    can: { createGroup: boolean };
    limits: TeamChatLimits;
}>();

/* The same canvas as the open chat (ChatWindow). */
const CHAT_CANVAS =
    'bg-surface-sunken bg-[radial-gradient(color-mix(in_oklch,var(--primary)_14%,transparent)_1px,transparent_1.5px)] bg-size-[20px_20px]';

const FILTER_STORAGE_KEY = 'team-chat.filter';
const PAGE_STEP = 50;

const filterOptions: { value: TeamThreadFilter; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'unread', label: 'No leídos' },
    { value: 'direct', label: 'Privados' },
    { value: 'groups', label: 'Grupos' },
    { value: 'archived', label: 'Archivados' },
];

const search = ref(props.filters.search);
const filter = ref<TeamThreadFilter>(props.filters.filter);
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
        only: ['threads', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loadingList.value = true),
        onFinish: () => (loadingList.value = false),
    });
}

function rememberFilter(value: TeamThreadFilter): void {
    try {
        window.localStorage.setItem(FILTER_STORAGE_KEY, value);
    } catch {
        // Storage may be unavailable (private mode): the filter is not remembered.
    }
}

function chooseFilter(value: TeamThreadFilter): void {
    if (filter.value === value) {
        return;
    }

    filter.value = value;
    rememberFilter(value);
    reloadList();
}

try {
    const remembered = window.localStorage.getItem(
        FILTER_STORAGE_KEY,
    ) as TeamThreadFilter | null;
    const isUrlFiltered = new URLSearchParams(window.location.search).has(
        'filter',
    );

    if (
        remembered &&
        remembered !== filter.value &&
        !isUrlFiltered &&
        filterOptions.some((option) => option.value === remembered)
    ) {
        filter.value = remembered;
        reloadList();
    }
} catch {
    // Storage may be unavailable: use the default filter.
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

function clearSearch(): void {
    search.value = '';
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
    only: ['threads', 'unreadTotal', 'teamChatUnread'],
    fallbackIntervalMs: 20_000,
});

const emptyListCopy = computed(() => {
    if (search.value.trim() !== '') {
        return {
            title: 'Ningún chat coincide',
            description:
                'Prueba con otro nombre o revisa los resultados en mensajes.',
        };
    }

    return {
        unread: {
            title: 'Estás al día',
            description: 'No tienes mensajes internos sin leer.',
        },
        archived: {
            title: 'Sin grupos archivados',
            description:
                'Los grupos que se archiven aparecerán aquí con su historial.',
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
        class="flex h-[calc(100dvh-3.5rem)] min-h-0 sm:h-[calc(100dvh-4rem)] [@media(max-height:32rem)]:h-[calc(100dvh-3rem)]!"
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
            <div class="grid gap-2.5 px-3 pt-3 pb-2.5">
                <div class="flex items-center justify-between gap-2 pl-1">
                    <div class="min-w-0">
                        <h1
                            class="truncate font-display text-xl leading-tight font-semibold"
                        >
                            Chat interno
                        </h1>
                        <p
                            class="truncate text-xs"
                            :class="
                                unreadTotal > 0
                                    ? 'font-medium text-primary'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                unreadTotal > 0
                                    ? `${unreadTotal} sin leer`
                                    : 'Mensajes entre el equipo'
                            }}
                        </p>
                    </div>
                    <DropdownMenu v-if="can.createGroup">
                        <DropdownMenuTrigger as-child>
                            <Button size="sm" data-tour="team-chat.new">
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
                        v-else
                        size="sm"
                        data-tour="team-chat.new"
                        @click="newDirectOpen = true"
                    >
                        <Plus /> Nuevo chat
                    </Button>
                </div>

                <div
                    class="relative"
                    role="search"
                    data-tour="team-chat.search"
                >
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="rounded-full border-transparent bg-muted/70 pr-9 pl-9 shadow-none hover:bg-muted focus-visible:bg-background [&::-webkit-search-cancel-button]:hidden"
                        placeholder="Buscar chats y mensajes"
                        aria-label="Buscar chats y mensajes"
                        maxlength="80"
                        @keydown.esc="clearSearch"
                    />
                    <button
                        v-if="search !== ''"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 rounded-full p-1 text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label="Limpiar búsqueda"
                        @click="clearSearch"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>

                <div
                    class="flex gap-1 overflow-x-auto pb-0.5"
                    role="group"
                    aria-label="Filtrar chats"
                >
                    <button
                        v-for="option in filterOptions"
                        :key="option.value"
                        type="button"
                        :aria-pressed="filter === option.value"
                        :class="
                            cn(
                                'shrink-0 press rounded-full px-3 py-1 text-xs font-medium focus-visible:ring-[3px] focus-visible:ring-primary/25 focus-visible:outline-none pointer-coarse:min-h-9',
                                filter === option.value
                                    ? 'bg-foreground text-background'
                                    : 'bg-muted/70 text-muted-foreground hover:bg-accent hover:text-foreground',
                            )
                        "
                        @click="chooseFilter(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div
                class="min-h-0 flex-1 overflow-y-auto border-t p-2"
                :aria-busy="loadingList"
            >
                <template v-if="loadingList && threads.threads.length === 0">
                    <div
                        v-for="index in 5"
                        :key="index"
                        class="flex items-center gap-3 px-3 py-2.5"
                    >
                        <Skeleton class="size-10 rounded-full" />
                        <div class="grid flex-1 gap-1.5">
                            <Skeleton class="h-3.5 w-2/3" />
                            <Skeleton class="h-3 w-full" />
                        </div>
                    </div>
                </template>
                <EmptyState
                    v-else-if="threads.threads.length === 0"
                    :icon="MessagesSquare"
                    :title="emptyListCopy.title"
                    :description="emptyListCopy.description"
                    class="m-1 py-10"
                />
                <nav
                    v-else
                    aria-label="Lista de chats"
                    data-tour="team-chat.threads"
                    class="grid grid-cols-1 gap-0.5"
                    :class="firstPaint && 'reveal-stagger'"
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
                        @preview="(row) => (previewThread = row)"
                    />
                </nav>

                <p
                    v-if="reachedListLimit"
                    class="px-3 py-2 text-xs text-muted-foreground"
                >
                    Se muestran los {{ limits.maxThreads }} chats más recientes.
                    Usa la búsqueda o los filtros para encontrar otros.
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
                    v-if="search.trim().length >= 2"
                    class="mt-3 border-t pt-3"
                    aria-label="Resultados en mensajes"
                >
                    <h2
                        class="px-3 pb-1 text-xs font-semibold text-muted-foreground"
                    >
                        En mensajes
                    </h2>
                    <div
                        v-if="searchingMessages"
                        class="grid gap-2 px-3 py-2"
                        role="status"
                    >
                        <span class="sr-only">Buscando…</span>
                        <Skeleton class="h-3.5 w-1/2" />
                        <Skeleton class="h-3 w-full" />
                        <Skeleton class="h-3.5 w-2/5" />
                        <Skeleton class="h-3 w-4/5" />
                    </div>
                    <p
                        v-else-if="messageResults.length === 0"
                        class="px-3 py-2 text-xs text-muted-foreground"
                    >
                        Ningún mensaje coincide.
                    </p>
                    <ul v-else class="grid gap-0.5">
                        <li
                            v-for="result in messageResults"
                            :key="result.message_id"
                        >
                            <button
                                type="button"
                                class="grid w-full gap-0.5 rounded-xl px-3 py-2 text-left transition-colors duration-(--duration-fast) hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
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
                                    {{ result.excerpt }}
                                </span>
                            </button>
                        </li>
                    </ul>
                    <p
                        v-if="!searchingMessages && moreMessageResults"
                        class="px-3 py-2 text-xs text-muted-foreground"
                    >
                        Se muestran los {{ messageResults.length }} mensajes más
                        recientes que coinciden. Escribe más palabras para
                        acotar la búsqueda.
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
                    @open-settings="settingsOpen = true"
                    @unread-changed="onUnreadChanged"
                />
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
                <div class="grid reveal justify-items-start gap-2">
                    <span
                        class="mb-2 grid size-12 place-items-center rounded-2xl bg-primary text-primary-foreground"
                        aria-hidden="true"
                    >
                        <MessageCircle class="size-6" />
                    </span>
                    <h2
                        class="font-display text-3xl font-semibold tracking-tight text-balance"
                    >
                        {{
                            unreadTotal > 0
                                ? unreadTotal === 1
                                    ? 'Tienes 1 mensaje sin leer'
                                    : `Tienes ${unreadTotal} mensajes sin leer`
                                : 'Elige un chat'
                        }}
                    </h2>
                    <p
                        class="max-w-prose text-base text-pretty text-muted-foreground"
                    >
                        Los mensajes internos son privados entre sus
                        participantes y nunca se envían a WhatsApp ni se mezclan
                        con las conversaciones de clientes. Puedes pegar
                        capturas de pantalla y arrastrar archivos a cualquier
                        chat.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button @click="newDirectOpen = true">
                            <Plus /> Nuevo chat privado
                        </Button>
                        <Button
                            v-if="can.createGroup"
                            variant="outline"
                            @click="newGroupOpen = true"
                        >
                            <UsersRound /> Nuevo grupo
                        </Button>
                    </div>
                </div>
            </div>
        </main>

        <ThreadPreview
            :thread="previewRow"
            @close="previewThread = null"
            @open="openFromPreview"
        />

        <NewDirectDialog v-model:open="newDirectOpen" />
        <NewGroupDialog
            v-if="can.createGroup"
            v-model:open="newGroupOpen"
            :max-participants="limits.groupMaxParticipants"
        />
    </div>
</template>
