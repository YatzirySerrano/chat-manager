<script setup lang="ts">
import {
    Hourglass,
    Inbox,
    MailWarning,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { useDebounceFn, useMediaQuery, watchDebounced } from '@vueuse/core';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import InboxFilterPanel from '@/components/conversations/InboxFilterPanel.vue';
import { MAX_FILTER_TAGS } from '@/components/conversations/inboxFilters';
import type { InboxFilterState } from '@/components/conversations/inboxFilters';
import InboxListItem from '@/components/conversations/InboxListItem.vue';
import InboxTagsDialog from '@/components/conversations/InboxTagsDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import ModuleHelpButton from '@/components/help/ModuleHelpButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatPhone } from '@/lib/phone';
import type {
    ConnectionChannel,
    ConversationFilters,
    ConversationInbox,
    ConversationList,
    ConversationSearchScope,
    ConversationSort,
    ConversationStatusFilter,
    ConversationSummary,
    InboxCounts,
    InboxView,
    InboxViewer,
    TagOption,
} from '@/types';

/**
 * Left panel of the inbox. The operational indicators stay always visible
 * and recognizable: the general inbox, the pending conversations (with
 * their counter, highlighted while there is any) and the unread ones.
 * Everything else (view, status, department, responsible agent, channel,
 * connection, tags with OR/AND, order and search scope) lives in one
 * compact "Filtros" menu (a bottom sheet on phones) whose applied values
 * show as removable chips under the search box. The URL stays the source of
 * truth (query()), the list is loaded with one keyset cursor, and filters
 * only ever narrow what the server already authorized.
 */
const props = withDefaults(
    defineProps<{
        conversations: ConversationList;
        filters: ConversationFilters;
        counts: InboxCounts;
        inbox: ConversationInbox;
        departments: { id: number; name: string; is_active: boolean }[];
        agents: { id: number; name: string; is_active: boolean }[];
        connections: {
            id: number;
            name: string;
            display_phone_number: string | null;
        }[];
        /** Conversations with unread messages in this view and filters. */
        unreadCount: number;
        /** Channels of the viewer's connections (the filter needs more than one). */
        filterOptions: {
            channels: { value: ConnectionChannel; label: string }[];
        };
        tags: TagOption[];
        canSeeUnrouted: boolean;
        /** The viewer may see closed conversations (history permission). */
        canSeeClosed: boolean;
        canExport: boolean;
        viewer: InboxViewer;
        selectedId: number | null;
        loading: boolean;
        /** Conversation being accepted from the list, if any. */
        acceptingId: number | null;
        /** Rows offer "Asignar alias personal" (PERSONALIZATION). */
        canEditAlias?: boolean;
    }>(),
    { canEditAlias: false },
);

const emit = defineEmits<{
    select: [id: number];
    filter: [query: Record<string, string | string[]>];
    loadMore: [];
    accept: [id: number];
    markRead: [id: number];
    editAlias: [conversation: ConversationSummary];
}>();

const ALL = '';

function fromFilters(filters: ConversationFilters): InboxFilterState {
    return {
        view: filters.view,
        status: filters.status,
        department: filters.department ?? ALL,
        agent: filters.agent ?? ALL,
        connection:
            filters.connection === null ? ALL : String(filters.connection),
        channel: filters.channel ?? ALL,
        tags: [...(filters.tags ?? [])],
        tagMatch: filters.tag_match ?? 'any',
        sort: filters.sort,
        searchIn: filters.search_in,
        unread: filters.unread,
    };
}

const state = reactive<InboxFilterState>(fromFilters(props.filters));
const search = ref(props.filters.search);

/* Follow the server when it normalizes or changes the filters. */
watch(
    () => props.filters,
    (filters) => Object.assign(state, fromFilters(filters)),
);

const defaultView = computed(() => props.inbox.views[0]);
/*
 * The view the indicators narrow by status: the general inbox for whoever
 * attends conversations, every authorized conversation otherwise.
 */
const baseView = computed<InboxView>(() =>
    props.inbox.views.includes('general') ? 'general' : 'all',
);
const isGeneral = computed(() => baseView.value === 'general');
const channels = computed(() => props.filterOptions.channels);
const showChannels = computed(() => channels.value.length > 1);
const showAgents = computed(
    () => props.viewer.can_supervise && !props.inbox.personal,
);

const viewLabels: Record<InboxView, string> = {
    general: 'Bandeja general',
    mine: 'Mis conversaciones',
    all: 'Todas las visibles',
    queue: 'Bandeja general',
};

const statusLabels: Record<string, string> = {
    active: 'Todas las activas',
    pending: 'Pendientes',
    open: 'En atención',
    closed: 'Cerradas',
    all: 'Todos los estados',
};

/* Estado options; "Mis conversaciones" never holds pending ones. */
const statusOptions = computed<
    { value: ConversationStatusFilter; label: string }[]
>(() => {
    const values: ConversationStatusFilter[] = ['active'];

    if (state.view !== 'mine') {
        values.push('pending');
    }

    values.push('open');

    if (props.canSeeClosed) {
        values.push('closed');
    }

    return values.map((value) => ({ value, label: statusLabels[value] }));
});

const sortOptions: { value: ConversationSort; label: string }[] = [
    { value: 'recent', label: 'Actividad más reciente' },
    { value: 'priority', label: 'Pendientes primero' },
    { value: 'oldest', label: 'Esperando hace más tiempo' },
];

const searchScopes: { value: ConversationSearchScope; label: string }[] = [
    { value: 'contact', label: 'Nombre, teléfono o correo del contacto' },
    { value: 'messages', label: 'Contenido de los mensajes' },
];

/* Operational indicators: always visible, mutually exclusive with each other. */
const generalActive = computed(
    () => state.view === baseView.value && state.status === 'active',
);
const pendingActive = computed(
    () => state.view === baseView.value && state.status === 'pending',
);
const pendingCount = computed(() => props.counts.pending);

const plural = (count: number, one: string, many: string): string =>
    `${count} ${count === 1 ? one : many}`;

/*
 * Pending conversations that arrive while the list is on screen are
 * announced politely (role=status), only when the counter goes up after a
 * live reload: a filter change (which also brings new filters) or a
 * decrease never speaks. Several increases close together become one
 * message. The region is emptied before each message, so a text equal to
 * the previous one is still a change the screen reader announces.
 */
const pendingAnnouncement = ref('');
let pendingIncrease = 0;

const announcePending = useDebounceFn(async () => {
    if (pendingIncrease > 0) {
        const message =
            pendingIncrease === 1
                ? '1 conversación pendiente nueva'
                : `${pendingIncrease} conversaciones pendientes nuevas`;

        pendingIncrease = 0;
        pendingAnnouncement.value = '';
        await nextTick();
        pendingAnnouncement.value = message;
    }
}, 1500);

watch(
    () => [props.counts.pending, props.filters] as const,
    ([pending, filters], [previousPending, previousFilters]) => {
        if (filters === previousFilters && pending > previousPending) {
            pendingIncrease += pending - previousPending;
            void announcePending();
        }
    },
);

/*
 * The server says when the message search has no word it can look for
 * (fewer than 3 letters or digits, or only very common words).
 */
const searchTooShort = computed(
    () =>
        state.searchIn === 'messages' &&
        props.filters.search_ignored &&
        props.filters.search === search.value.trim(),
);

type AppliedChip = { key: string; label: string; clear: () => void };

/*
 * Applied filters as removable chips. The indicators already tell the
 * general inbox, "Pendientes" and "No leídas", so they get no chip.
 */
const appliedChips = computed<AppliedChip[]>(() => {
    const chips: AppliedChip[] = [];

    if (state.view !== baseView.value) {
        chips.push({
            key: 'view',
            label: `Vista: ${viewLabels[state.view]}`,
            clear: () => (state.view = baseView.value),
        });
    }

    if (state.status !== 'active' && !pendingActive.value) {
        chips.push({
            key: 'status',
            label: `Estado: ${statusLabels[state.status] ?? state.status}`,
            clear: () => (state.status = 'active'),
        });
    }

    if (state.department !== ALL) {
        const department = props.departments.find(
            (option) => String(option.id) === state.department,
        );

        chips.push({
            key: 'department',
            label: `Departamento: ${state.department === 'none' ? 'Sin departamento' : (department?.name ?? 'No disponible')}`,
            clear: () => (state.department = ALL),
        });
    }

    if (state.agent !== ALL && !props.inbox.personal) {
        const agent = props.agents.find(
            (option) => String(option.id) === state.agent,
        );

        chips.push({
            key: 'agent',
            label: `Responsable: ${state.agent === 'none' ? 'Sin agente' : (agent?.name ?? 'No disponible')}`,
            clear: () => (state.agent = ALL),
        });
    }

    if (state.channel !== ALL) {
        chips.push({
            key: 'channel',
            label: `Canal: ${channels.value.find((option) => option.value === state.channel)?.label ?? state.channel}`,
            clear: () => (state.channel = ALL),
        });
    }

    if (state.connection !== ALL) {
        const connection = props.connections.find(
            (option) => String(option.id) === state.connection,
        );

        chips.push({
            key: 'connection',
            label: `Conexión: ${connection ? `${connection.name}${connection.display_phone_number ? ` · ${formatPhone(connection.display_phone_number)}` : ''}` : 'No disponible'}`,
            clear: () => (state.connection = ALL),
        });
    }

    for (const id of state.tags) {
        chips.push({
            key: `tag-${id}`,
            label: `Etiqueta: ${props.tags.find((tag) => tag.id === id)?.name ?? 'No disponible'}`,
            clear: () => (state.tags = state.tags.filter((tag) => tag !== id)),
        });
    }

    // "Cualquiera (O)" is the default; only the AND mode is a chip.
    if (state.tags.length > 1 && state.tagMatch === 'all') {
        chips.push({
            key: 'tag-match',
            label: 'Con todas las etiquetas (Y)',
            clear: () => (state.tagMatch = 'any'),
        });
    }

    if (state.sort !== 'recent') {
        chips.push({
            key: 'sort',
            label: `Orden: ${sortOptions.find((option) => option.value === state.sort)?.label ?? state.sort}`,
            clear: () => (state.sort = 'recent'),
        });
    }

    if (state.searchIn !== 'contact') {
        chips.push({
            key: 'search-in',
            label: 'Buscar en: mensajes',
            clear: () => (state.searchIn = 'contact'),
        });
    }

    return chips;
});

const activeFilterCount = computed(
    () => appliedChips.value.filter((chip) => chip.key !== 'tag-match').length,
);

/**
 * A search, a filter chip or "No leídas" narrows the list: an empty result
 * then means "nothing matches", never "your inbox is up to date".
 */
const isNarrowed = computed(
    () =>
        appliedChips.value.length > 0 ||
        state.unread ||
        search.value.trim() !== '',
);

const hasFilters = computed(
    () =>
        appliedChips.value.length > 0 ||
        state.unread ||
        state.status !== 'active' ||
        search.value.trim() !== '',
);

/*
 * The export follows the screen's filters except the personal "unread" one,
 * which depends on each person's read markers and is not part of the report.
 */
const exportFilters = computed(() => ({ ...props.filters, unread: false }));

function query(): Record<string, string | string[]> {
    const result: Record<string, string | string[]> = {};

    if (state.view !== defaultView.value) {
        result.view = state.view;
    }

    if (state.status !== 'active') {
        result.status = state.status;
    }

    for (const key of [
        'department',
        'agent',
        'connection',
        'channel',
    ] as const) {
        if (state[key] !== ALL && !(key === 'agent' && props.inbox.personal)) {
            result[key] = state[key];
        }
    }

    if (state.tags.length > 0) {
        result.tags = state.tags.map(String);

        if (state.tagMatch === 'all' && state.tags.length > 1) {
            result.tag_match = 'all';
        }
    }

    if (state.sort !== 'recent') {
        result.sort = state.sort;
    }

    if (state.unread) {
        result.unread = '1';
    }

    if (search.value.trim() !== '') {
        result.search = search.value.trim();

        if (state.searchIn !== 'contact') {
            result.search_in = state.searchIn;
        }
    }

    return result;
}

function apply(): void {
    emit('filter', query());
}

watchDebounced(search, apply, { debounce: 300 });

function change(patch: Partial<InboxFilterState>): void {
    Object.assign(state, patch);

    // "Mis conversaciones" never holds pending ones.
    if (state.view === 'mine' && state.status === 'pending') {
        state.status = 'active';
    }

    if (
        'searchIn' in patch &&
        Object.keys(patch).length === 1 &&
        search.value.trim() === ''
    ) {
        return;
    }

    apply();
}

function removeChip(chip: AppliedChip): void {
    chip.clear();
    apply();
}

function showGeneral(): void {
    change({ view: baseView.value, status: 'active' });
}

function togglePending(): void {
    change(
        pendingActive.value
            ? { status: 'active' }
            : { view: baseView.value, status: 'pending' },
    );
}

function toggleUnread(): void {
    change({ unread: !state.unread });
}

function clearFilters(): void {
    Object.assign(state, {
        view: defaultView.value,
        status: 'active',
        department: ALL,
        agent: ALL,
        connection: ALL,
        channel: ALL,
        tags: [],
        tagMatch: 'any',
        sort: 'recent',
        searchIn: 'contact',
        unread: false,
    } satisfies InboxFilterState);
    search.value = '';
    filtersOpen.value = false;
    apply();
}

defineExpose({ query });

/* "Filtros": a popover on wide screens, a bottom sheet on phones. */
const compact = useMediaQuery('(max-width: 639px)');
const filtersOpen = ref(false);

/* Row "Administrar etiquetas": one dialog for the whole list. */
const taggedConversation = ref<ConversationSummary | null>(null);
const tagsDialogOpen = ref(false);

function manageTags(conversation: ConversationSummary): void {
    taggedConversation.value = conversation;
    tagsDialogOpen.value = true;
}

/* Keyboard: arrows move between rows, Home/End jump to the ends. */
const listElement = ref<HTMLElement | null>(null);

function onListKeydown(event: KeyboardEvent): void {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const items = [
        ...(listElement.value?.querySelectorAll<HTMLElement>(
            '[data-inbox-item]',
        ) ?? []),
    ];
    const index = items.indexOf(document.activeElement as HTMLElement);

    if (items.length === 0 || index === -1) {
        return;
    }

    event.preventDefault();

    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? items.length - 1
              : event.key === 'ArrowDown'
                ? Math.min(items.length - 1, index + 1)
                : Math.max(0, index - 1);

    items[next]?.focus();
}

/* Load more when the end of the list comes into view. */
function onScroll(event: Event): void {
    const element = event.target as HTMLElement;

    if (
        props.conversations.has_more &&
        !props.loading &&
        element.scrollHeight - element.scrollTop - element.clientHeight < 160
    ) {
        emit('loadMore');
    }
}

const indicatorClass =
    'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 motion-safe:transition-colors pointer-coarse:h-10';
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col bg-background"
        aria-label="Lista de conversaciones"
    >
        <header
            class="grid min-w-0 grid-cols-1 gap-2.5 border-b px-3 pt-3 pb-2.5"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="flex min-w-0 items-center gap-1">
                    <h1 class="truncate text-base font-semibold tracking-tight">
                        Conversaciones
                    </h1>
                    <ModuleHelpButton module="conversaciones" icon-only />
                </div>
                <ExportMenu
                    v-if="canExport"
                    report="conversations"
                    :filters="exportFilters"
                    label="Exportar"
                />
            </div>

            <nav aria-label="Bandejas">
                <div
                    class="flex flex-wrap gap-1.5"
                    role="group"
                    aria-label="Filtrar la bandeja"
                >
                    <button
                        type="button"
                        :class="[
                            indicatorClass,
                            generalActive
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        ]"
                        :aria-pressed="generalActive"
                        :title="
                            isGeneral
                                ? 'Todo lo que espera por ti: las pendientes que puedes aceptar y las que atiendes.'
                                : 'Las conversaciones activas de tu alcance.'
                        "
                        data-inbox-chip="base"
                        @click="showGeneral"
                    >
                        {{ isGeneral ? 'Bandeja general' : 'Todas' }}
                        <span
                            class="rounded-full px-1.5 text-xs tabular-nums"
                            :class="
                                generalActive
                                    ? 'bg-primary-foreground/20'
                                    : 'bg-muted'
                            "
                            aria-hidden="true"
                            >{{ counts.general }}</span
                        >
                        <span class="sr-only"
                            >:
                            {{
                                plural(
                                    counts.general,
                                    'conversación activa',
                                    'conversaciones activas',
                                )
                            }}</span
                        >
                    </button>

                    <button
                        type="button"
                        :class="[
                            indicatorClass,
                            pendingActive
                                ? 'border-primary bg-primary text-primary-foreground'
                                : pendingCount > 0
                                  ? 'border-warning/60 bg-warning/15 font-semibold text-foreground hover:bg-warning/25'
                                  : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        ]"
                        :aria-pressed="pendingActive"
                        :title="
                            isGeneral
                                ? 'Conversaciones que esperan a que alguien de tu departamento las acepte. No incluye las que atienden tus compañeros.'
                                : 'Conversaciones que esperan a que alguien las acepte.'
                        "
                        data-inbox-chip="pending"
                        @click="togglePending"
                    >
                        <Hourglass
                            v-if="pendingCount > 0"
                            class="size-4 shrink-0"
                            :class="pendingActive ? undefined : 'text-warning'"
                            aria-hidden="true"
                        />
                        Pendientes
                        <span
                            class="rounded-full px-1.5 text-xs tabular-nums"
                            :class="
                                pendingActive
                                    ? 'bg-primary-foreground/20'
                                    : pendingCount > 0
                                      ? 'bg-warning/25'
                                      : 'bg-muted'
                            "
                            aria-hidden="true"
                            >{{ pendingCount }}</span
                        >
                        <span class="sr-only"
                            >: {{ pendingCount }} por aceptar</span
                        >
                    </button>

                    <button
                        type="button"
                        :class="[
                            indicatorClass,
                            state.unread
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        ]"
                        :aria-pressed="state.unread"
                        title="Solo las conversaciones con mensajes del cliente que aún no has leído"
                        data-inbox-unread
                        @click="toggleUnread"
                    >
                        <MailWarning
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        No leídas
                        <span
                            class="rounded-full px-1.5 text-xs tabular-nums"
                            :class="
                                state.unread
                                    ? 'bg-primary-foreground/20'
                                    : 'bg-muted'
                            "
                            aria-hidden="true"
                            >{{ unreadCount }}</span
                        >
                        <span class="sr-only"
                            >:
                            {{
                                plural(
                                    unreadCount,
                                    'conversación sin leer',
                                    'conversaciones sin leer',
                                )
                            }}</span
                        >
                    </button>
                </div>
            </nav>

            <p class="sr-only" role="status" aria-live="polite">
                {{ pendingAnnouncement }}
            </p>

            <div class="flex items-center gap-2" role="search">
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        :placeholder="
                            state.searchIn === 'messages'
                                ? 'Buscar en los mensajes'
                                : 'Buscar por nombre, teléfono o correo'
                        "
                        class="h-9 pl-8 pointer-coarse:h-10"
                        aria-label="Buscar conversaciones"
                    />
                </div>

                <Sheet v-if="compact" v-model:open="filtersOpen">
                    <Button
                        variant="outline"
                        size="sm"
                        class="h-9 shrink-0 pointer-coarse:h-10"
                        :aria-label="
                            activeFilterCount > 0
                                ? `Filtros (${activeFilterCount} aplicados)`
                                : 'Filtros'
                        "
                        aria-haspopup="dialog"
                        :aria-expanded="filtersOpen"
                        data-inbox-filters
                        @click="filtersOpen = true"
                    >
                        <SlidersHorizontal aria-hidden="true" />
                        Filtros
                        <span
                            v-if="activeFilterCount > 0"
                            class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums"
                            aria-hidden="true"
                            >{{ activeFilterCount }}</span
                        >
                    </Button>
                    <SheetContent
                        side="bottom"
                        class="max-h-[85dvh] gap-0 overflow-y-auto rounded-t-xl p-0"
                    >
                        <SheetHeader class="border-b px-4 py-3 text-left">
                            <SheetTitle class="text-base">Filtros</SheetTitle>
                            <SheetDescription class="text-sm">
                                Se aplican al momento y se combinan entre sí.
                            </SheetDescription>
                        </SheetHeader>
                        <div class="p-4">
                            <InboxFilterPanel
                                :state="state"
                                :views="
                                    inbox.views.filter(
                                        (view) => view !== 'queue',
                                    )
                                "
                                :view-labels="viewLabels"
                                :status-options="statusOptions"
                                :unread-count="unreadCount"
                                :departments="departments"
                                :can-see-unrouted="canSeeUnrouted"
                                :show-agents="showAgents"
                                :agents="agents"
                                :channels="channels"
                                :connections="connections"
                                :tags="tags"
                                :max-tags="MAX_FILTER_TAGS"
                                :sort-options="sortOptions"
                                :search-scopes="searchScopes"
                                :has-filters="hasFilters"
                                @change="change"
                                @clear="clearFilters"
                            />
                        </div>
                    </SheetContent>
                </Sheet>

                <Popover v-else v-model:open="filtersOpen">
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 shrink-0 pointer-coarse:h-10"
                            :aria-label="
                                activeFilterCount > 0
                                    ? `Filtros (${activeFilterCount} aplicados)`
                                    : 'Filtros'
                            "
                            data-inbox-filters
                        >
                            <SlidersHorizontal aria-hidden="true" />
                            Filtros
                            <span
                                v-if="activeFilterCount > 0"
                                class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums"
                                aria-hidden="true"
                                >{{ activeFilterCount }}</span
                            >
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        align="end"
                        class="max-h-[min(75dvh,40rem)] w-80 overflow-y-auto"
                        aria-label="Filtros de la bandeja"
                    >
                        <InboxFilterPanel
                            :state="state"
                            :views="
                                inbox.views.filter((view) => view !== 'queue')
                            "
                            :view-labels="viewLabels"
                            :status-options="statusOptions"
                            :unread-count="unreadCount"
                            :departments="departments"
                            :can-see-unrouted="canSeeUnrouted"
                            :show-agents="showAgents"
                            :agents="agents"
                            :channels="channels"
                            :connections="connections"
                            :tags="tags"
                            :max-tags="MAX_FILTER_TAGS"
                            :sort-options="sortOptions"
                            :search-scopes="searchScopes"
                            :has-filters="hasFilters"
                            @change="change"
                            @clear="clearFilters"
                        />
                    </PopoverContent>
                </Popover>
            </div>

            <p
                v-if="searchTooShort"
                class="text-xs text-muted-foreground"
                role="status"
            >
                Escribe al menos una palabra de 3 letras o números para buscar
                en los mensajes. Las palabras muy comunes (como «the» o «for»)
                no se buscan.
            </p>

            <div
                v-if="appliedChips.length > 0 || hasFilters"
                class="flex flex-wrap items-center gap-1.5"
                aria-label="Filtros aplicados"
                role="group"
            >
                <span
                    v-for="chip in appliedChips"
                    :key="chip.key"
                    class="inline-flex max-w-full min-w-0 items-center gap-0.5 rounded-full border bg-muted/50 py-0.5 pr-0.5 pl-2.5 text-xs"
                    data-inbox-applied
                >
                    <span class="truncate">{{ chip.label }}</span>
                    <button
                        type="button"
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-8"
                        :aria-label="`Quitar el filtro ${chip.label}`"
                        @click="removeChip(chip)"
                    >
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </span>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="rounded px-1.5 py-0.5 text-xs font-medium text-primary underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-8"
                    data-inbox-clear
                    @click="clearFilters"
                >
                    Limpiar filtros
                </button>
            </div>
        </header>

        <div
            class="min-h-0 flex-1 overflow-y-auto"
            :aria-busy="loading"
            @scroll.passive="onScroll"
        >
            <ul
                v-if="conversations.data.length > 0"
                ref="listElement"
                class="divide-y motion-safe:transition-opacity"
                :class="loading && 'opacity-60'"
                @keydown="onListKeydown"
            >
                <li
                    v-for="conversation in conversations.data"
                    :key="conversation.id"
                >
                    <InboxListItem
                        :conversation="conversation"
                        :selected="conversation.id === selectedId"
                        :unread="
                            conversation.id === selectedId
                                ? 0
                                : (conversation.unread_count ?? 0)
                        "
                        :show-operational="viewer.can_supervise"
                        :show-assignee="showAgents"
                        :show-channel="showChannels"
                        :accepting-id="acceptingId"
                        :can-edit-alias="canEditAlias"
                        @select="(id) => emit('select', id)"
                        @accept="(id) => emit('accept', id)"
                        @mark-read="(id) => emit('markRead', id)"
                        @manage-tags="manageTags"
                        @edit-alias="(row) => emit('editAlias', row)"
                    />
                </li>
            </ul>

            <div
                v-else-if="loading"
                class="grid gap-0 divide-y"
                aria-hidden="true"
            >
                <div
                    v-for="index in 7"
                    :key="index"
                    class="flex items-start gap-3 px-3 py-3"
                >
                    <Skeleton class="size-10 shrink-0 rounded-full" />
                    <div class="grid flex-1 gap-2 pt-0.5">
                        <div class="flex justify-between gap-3">
                            <Skeleton class="h-4 w-2/5" />
                            <Skeleton class="h-3 w-10" />
                        </div>
                        <Skeleton class="h-3.5 w-4/5" />
                    </div>
                </div>
            </div>

            <EmptyState
                v-else
                class="m-3 border-none"
                :icon="Inbox"
                :title="
                    isNarrowed
                        ? 'Ninguna conversación coincide'
                        : pendingActive
                          ? 'No hay pendientes por aceptar'
                          : generalActive && isGeneral
                            ? 'Tu bandeja está al día'
                            : 'No hay conversaciones aquí'
                "
                :description="
                    isNarrowed
                        ? 'Prueba con otra búsqueda, cambia los filtros o límpialos para ver toda tu bandeja.'
                        : isGeneral && (generalActive || pendingActive)
                          ? 'Aquí aparecen las pendientes que puedes aceptar y las que atiendes. Si tus departamentos distribuyen las conversaciones de forma manual, no tienes pendientes por aceptar: un supervisor te las asigna y aparecerán aquí al recibirlas.'
                          : 'Las conversaciones aparecen cuando un cliente escribe a una de tus conexiones. Solo ves las que corresponden a tu alcance.'
                "
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    @click="clearFilters"
                    >Limpiar filtros</Button
                >
            </EmptyState>

            <div
                v-if="conversations.has_more"
                class="flex justify-center border-t p-3"
            >
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="loading"
                    @click="emit('loadMore')"
                >
                    <Spinner v-if="loading" />
                    Cargar más conversaciones
                </Button>
            </div>
        </div>

        <InboxTagsDialog
            v-model:open="tagsDialogOpen"
            :conversation="taggedConversation"
            :available="tags"
        />
    </section>
</template>
