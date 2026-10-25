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
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import ModuleHelpButton from '@/components/help/ModuleHelpButton.vue';
import ChatWindow from '@/components/team-chat/ChatWindow.vue';
import NewDirectDialog from '@/components/team-chat/NewDirectDialog.vue';
import NewGroupDialog from '@/components/team-chat/NewGroupDialog.vue';
import ThreadListItem from '@/components/team-chat/ThreadListItem.vue';
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

function onUnreadChanged(total: number): void {
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

useLiveReload({
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
</script>

<template>
    <Head
        :title="
            activeThread
                ? `${activeThread.title} · Chat interno`
                : 'Chat interno'
        "
    />

    <div class="flex h-[calc(100dvh-3.5rem)] min-h-0 sm:h-[calc(100dvh-4rem)]">
        <aside
            :class="
                cn(
                    'min-h-0 w-full shrink-0 flex-col border-r bg-background lg:flex lg:w-80 xl:w-96',
                    activeThread ? 'hidden' : 'flex',
                )
            "
            aria-label="Chats internos"
        >
            <div class="grid gap-3 border-b p-3">
                <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0">
                        <h1 class="sr-only">Chat interno</h1>
                        <p class="text-sm text-muted-foreground">
                            {{
                                unreadTotal > 0
                                    ? `${unreadTotal} sin leer`
                                    : 'Mensajes entre el equipo'
                            }}
                        </p>
                    </div>
                    <ModuleHelpButton
                        module="chat-interno"
                        icon-only
                        class="ml-auto"
                    />
                    <DropdownMenu v-if="can.createGroup">
                        <DropdownMenuTrigger as-child>
                            <Button size="sm"> <Plus /> Nuevo </Button>
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
                    <Button v-else size="sm" @click="newDirectOpen = true">
                        <Plus /> Nuevo chat
                    </Button>
                </div>

                <div class="relative" role="search">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        class="pr-9 pl-9"
                        placeholder="Buscar chats y mensajes"
                        aria-label="Buscar chats y mensajes"
                        maxlength="80"
                        @keydown.esc="clearSearch"
                    />
                    <button
                        v-if="search !== ''"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
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
                                'shrink-0 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                filter === option.value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
                            )
                        "
                        @click="chooseFilter(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>

            <div
                class="min-h-0 flex-1 overflow-y-auto p-2"
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
                    class="grid grid-cols-1 gap-0.5"
                >
                    <ThreadListItem
                        v-for="thread in threads.threads"
                        :key="thread.id"
                        :thread="thread"
                        :active="activeThread?.id === thread.id"
                        :query="linkQuery"
                    />
                </nav>

                <p
                    v-if="reachedListLimit"
                    class="px-3 py-2 text-center text-xs text-muted-foreground"
                >
                    Se muestran los {{ limits.maxThreads }} chats más recientes.
                    Usa la búsqueda o los filtros para encontrar otros.
                </p>
                <div
                    v-else-if="threads.has_more"
                    class="flex justify-center py-2"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="loadingList"
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
                        class="px-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        En mensajes
                    </h2>
                    <p
                        v-if="searchingMessages"
                        class="px-3 py-2 text-xs text-muted-foreground"
                    >
                        Buscando…
                    </p>
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
                                class="grid w-full gap-0.5 rounded-lg px-3 py-2 text-left hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
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
                    'min-h-0 min-w-0 flex-1 flex-col bg-muted/20',
                    activeThread ? 'flex' : 'hidden lg:flex',
                )
            "
        >
            <template v-if="activeThread && messages">
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
            <div v-else class="flex flex-1 items-center justify-center p-6">
                <EmptyState
                    :icon="MessageCircle"
                    title="Elige un chat"
                    description="Los mensajes internos son privados entre sus participantes y nunca se envían a WhatsApp ni se mezclan con las conversaciones de clientes."
                    class="max-w-md"
                >
                    <Button variant="outline" @click="newDirectOpen = true">
                        <Plus /> Nuevo chat privado
                    </Button>
                </EmptyState>
            </div>
        </main>

        <NewDirectDialog v-model:open="newDirectOpen" />
        <NewGroupDialog
            v-if="can.createGroup"
            v-model:open="newGroupOpen"
            :max-participants="limits.groupMaxParticipants"
        />
    </div>
</template>
