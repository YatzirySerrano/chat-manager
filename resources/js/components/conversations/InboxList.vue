<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    CircleAlert,
    Hourglass,
    Inbox,
    ListFilter,
    MailWarning,
    Search,
    X,
} from '@lucide/vue';
import { useDebounceFn, watchDebounced } from '@vueuse/core';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InboxListItem from '@/components/conversations/InboxListItem.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatPhone } from '@/lib/phone';
import { update as updateAvailability } from '@/routes/conversations/availability';
import type {
    ConnectionChannel,
    ConversationFilters,
    ConversationInbox,
    ConversationList,
    ConversationSearchScope,
    ConversationSort,
    ConversationStatusFilter,
    ConversationStatusValue,
    InboxCounts,
    InboxView,
    TagOption,
    ViewerAvailability,
} from '@/types';

/**
 * Left panel of the inbox: one row of mutually exclusive chips over the
 * general inbox (everything waiting for the viewer: pending ones they may
 * accept, the ones they attend and the ones on hold within reach), their
 * own conversations and its branches, with counters computed by the server
 * in one query; the "unread" switch, search, filters, sorting and the list
 * itself, loaded incrementally with one cursor. Filters only ever narrow
 * what the server already authorized, and combine with each other.
 */
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
    availability: ViewerAvailability;
    selectedId: number | null;
    loading: boolean;
    /** Conversation being accepted from the list, if any. */
    acceptingId: number | null;
}>();

const emit = defineEmits<{
    select: [id: number];
    filter: [query: Record<string, string>];
    loadMore: [];
    accept: [id: number];
}>();

const ALL = '';

const state = reactive({
    view: props.filters.view,
    status: props.filters.status,
    department: props.filters.department ?? ALL,
    agent: props.filters.agent ?? ALL,
    connection:
        props.filters.connection === null
            ? ALL
            : String(props.filters.connection),
    channel: props.filters.channel ?? ALL,
    tag: props.filters.tag === null ? ALL : String(props.filters.tag),
    sort: props.filters.sort,
    search: props.filters.search,
    searchIn: props.filters.search_in,
    unread: props.filters.unread,
});

/* Follow the server when it normalizes or changes the filters. */
watch(
    () => props.filters,
    (filters) => {
        state.view = filters.view;
        state.status = filters.status;
        state.department = filters.department ?? ALL;
        state.agent = filters.agent ?? ALL;
        state.connection =
            filters.connection === null ? ALL : String(filters.connection);
        state.channel = filters.channel ?? ALL;
        state.tag = filters.tag === null ? ALL : String(filters.tag);
        state.sort = filters.sort;
        state.searchIn = filters.search_in;
        state.unread = filters.unread;
    },
);

const defaultView = computed(() => props.inbox.views[0]);
/*
 * The view the chips narrow by status: the general inbox for whoever
 * attends conversations, every authorized conversation otherwise.
 */
const baseView = computed<InboxView>(() =>
    props.inbox.views.includes('general') ? 'general' : 'all',
);
const isUnavailable = computed(() => props.availability.value !== 'available');
const showChannels = computed(() => props.filterOptions.channels.length > 1);

type ChipKey =
    | 'base'
    | 'mine'
    | 'pending'
    | 'attending'
    | 'on_hold'
    | 'closed'
    | 'all';

type Chip = {
    key: ChipKey;
    label: string;
    view: InboxView;
    status: ConversationStatusFilter;
    count: number | null;
    /** Meaning of the number for screen readers. */
    countLabel: (count: number) => string;
    help: string;
    warn?: boolean;
};

const plural = (count: number, one: string, many: string): string =>
    `${count} ${count === 1 ? one : many}`;

/*
 * The chips (texts kept for the end-to-end helpers): each number is what
 * the chip lists with the current filters (InboxCounts).
 */
const chips = computed<Chip[]>(() => {
    const base = baseView.value;
    const general = base === 'general';
    const result: Chip[] = [
        {
            key: 'base',
            label: general ? 'Bandeja general' : 'Todas',
            view: base,
            status: 'active',
            count: props.counts.general,
            countLabel: (count) =>
                plural(count, 'conversación activa', 'conversaciones activas'),
            help: general
                ? 'Todo lo que espera por ti: las pendientes que puedes aceptar, las que atiendes y las que están en espera.'
                : 'Las conversaciones activas de tu alcance.',
        },
    ];

    if (props.inbox.views.includes('mine')) {
        result.push({
            key: 'mine',
            label: 'Mis conversaciones',
            view: 'mine',
            status: 'active',
            count: props.counts.mine,
            countLabel: (count) =>
                `${plural(count, 'asignada', 'asignadas')} a ti`,
            help: 'Las conversaciones de las que eres responsable.',
        });
    }

    result.push(
        {
            key: 'pending',
            label: 'Pendientes',
            view: base,
            status: 'pending',
            count: props.counts.pending,
            countLabel: (count) => `${count} por aceptar`,
            help: general
                ? 'Conversaciones que esperan a que alguien de tu departamento las acepte. No incluye las que atienden tus compañeros.'
                : 'Conversaciones que esperan a que alguien las acepte.',
            warn: true,
        },
        {
            key: 'attending',
            label: 'En atención',
            view: base,
            status: 'open',
            count: props.counts.attending,
            countLabel: (count) =>
                general ? `${count} que atiendes` : `${count} en atención`,
            help: general
                ? 'Las conversaciones abiertas que atiendes tú.'
                : 'Las conversaciones abiertas de tu alcance.',
        },
        {
            key: 'on_hold',
            label: 'En espera',
            view: base,
            status: 'on_hold',
            count: props.counts.on_hold,
            countLabel: (count) => `${count} en espera`,
            help: 'Las conversaciones en espera de tu alcance.',
        },
    );

    if (props.canSeeClosed) {
        result.push({
            key: 'closed',
            label: 'Cerradas',
            view: base,
            status: 'closed',
            count: null,
            countLabel: () => '',
            help: 'El historial de conversaciones cerradas de tu alcance.',
        });
    }

    if (general && props.inbox.views.includes('all')) {
        result.push({
            key: 'all',
            label: 'Todas',
            view: 'all',
            status: 'active',
            count: null,
            countLabel: () => '',
            help: 'Las conversaciones de tu alcance, con filtros por departamento, agente, estado y conexión.',
        });
    }

    return result;
});

/** The chip matching the current view and status. */
const activeChip = computed<ChipKey>(() => {
    if (state.view === 'mine') {
        return 'mine';
    }

    if (state.view !== baseView.value) {
        return 'all';
    }

    switch (state.status) {
        case 'pending':
            return 'pending';
        case 'open':
            return 'attending';
        case 'on_hold':
            return 'on_hold';
        case 'closed':
            return 'closed';
        default:
            return 'base';
    }
});

/*
 * Views whose status is not set by a chip ("Mis conversaciones" and
 * "Todas" next to the general inbox) offer it in the filters.
 */
const offersStatusFilter = computed(
    () =>
        state.view === 'mine' ||
        (state.view === 'all' && baseView.value === 'general'),
);

const statusOptions = computed<
    { value: ConversationStatusFilter; label: string }[]
>(() => [
    { value: 'active', label: 'Activas' },
    ...props.statuses
        .filter(
            (status) =>
                (state.view !== 'mine' || status.value !== 'pending') &&
                (props.canSeeClosed || status.value !== 'closed'),
        )
        .map((status) => ({
            value: status.value as ConversationStatusFilter,
            label: status.label,
        })),
    { value: 'all', label: 'Todos los estados' },
]);

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

const sortOptions: { value: ConversationSort; label: string }[] = [
    { value: 'recent', label: 'Actividad más reciente' },
    { value: 'priority', label: 'Pendientes primero' },
    { value: 'oldest', label: 'Esperando hace más tiempo' },
];

const searchScopes: { value: ConversationSearchScope; label: string }[] = [
    { value: 'contact', label: 'Nombre, teléfono o correo del contacto' },
    { value: 'messages', label: 'Contenido de los mensajes' },
];

/*
 * The server says when the message search has no word it can look for
 * (fewer than 3 letters or digits, or only very common words).
 */
const searchTooShort = computed(
    () =>
        state.searchIn === 'messages' &&
        props.filters.search_ignored &&
        props.filters.search === state.search.trim(),
);

const activeFilterCount = computed(
    () =>
        [
            state.department,
            state.agent,
            state.connection,
            state.channel,
            state.tag,
        ].filter((value) => value !== ALL).length +
        (offersStatusFilter.value && state.status !== 'active' ? 1 : 0) +
        (state.sort !== 'recent' ? 1 : 0) +
        (state.searchIn !== 'contact' ? 1 : 0),
);

const hasFilters = computed(
    () =>
        activeFilterCount.value > 0 ||
        state.unread ||
        state.search.trim() !== '',
);

/*
 * The export follows the screen's filters except the personal "unread" one,
 * which depends on each person's read markers and is not part of the report.
 */
const exportFilters = computed(() => ({ ...props.filters, unread: false }));

function query(): Record<string, string> {
    const result: Record<string, string> = {};

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
        'tag',
    ] as const) {
        if (state[key] !== ALL && !(key === 'agent' && props.inbox.personal)) {
            result[key] = state[key];
        }
    }

    if (state.sort !== 'recent') {
        result.sort = state.sort;
    }

    if (state.unread) {
        result.unread = '1';
    }

    if (state.search.trim() !== '') {
        result.search = state.search.trim();

        if (state.searchIn !== 'contact') {
            result.search_in = state.searchIn;
        }
    }

    return result;
}

function apply(): void {
    emit('filter', query());
}

watchDebounced(() => state.search, apply, { debounce: 300 });

function selectChip(chip: Chip): void {
    state.view = chip.view;
    state.status = chip.status;
    apply();
}

function toggleUnread(): void {
    state.unread = !state.unread;
    apply();
}

function clearFilters(): void {
    state.department = ALL;
    state.agent = ALL;
    state.connection = ALL;
    state.channel = ALL;
    state.tag = ALL;
    state.sort = 'recent';

    if (offersStatusFilter.value) {
        state.status = 'active';
    }

    state.search = '';
    state.searchIn = 'contact';
    state.unread = false;
    apply();
}

defineExpose({ query });

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

    if (items.length === 0) {
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

const availabilityProcessing = ref(false);

function toggleAvailability(available: boolean): void {
    router.put(
        updateAvailability.url(),
        { availability: available ? 'available' : 'unavailable' },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (availabilityProcessing.value = true),
            onFinish: () => (availabilityProcessing.value = false),
            onError: (errors) => {
                toast.error(
                    Object.values(errors)[0] ??
                        'No se pudo cambiar tu disponibilidad.',
                );
            },
        },
    );
}

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs md:text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col bg-background"
        aria-label="Lista de conversaciones"
    >
        <header class="grid min-w-0 grid-cols-1 gap-3 border-b px-3 pt-3 pb-2">
            <div
                class="flex flex-wrap items-center justify-between gap-x-2 gap-y-2"
            >
                <h1 class="text-base font-semibold">Conversaciones</h1>
                <div
                    class="ml-auto flex flex-wrap items-center justify-end gap-1"
                >
                    <ExportMenu
                        v-if="canExport"
                        report="conversations"
                        :filters="exportFilters"
                        label="Exportar"
                    />
                    <label
                        class="flex h-9 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm pointer-coarse:h-10"
                        :title="
                            availability.value === 'available'
                                ? 'Recibes y aceptas conversaciones'
                                : 'No recibes asignaciones automáticas ni aceptas nuevas'
                        "
                    >
                        <Switch
                            :model-value="availability.value === 'available'"
                            :disabled="availabilityProcessing"
                            aria-label="Disponible para aceptar y recibir conversaciones"
                            @update:model-value="toggleAvailability"
                        />
                        <span class="font-medium">{{
                            availability.label
                        }}</span>
                    </label>
                    <FieldHelp
                        title="Disponibilidad"
                        :help="{
                            description:
                                'Disponible: puedes aceptar conversaciones pendientes y participas en la asignación automática. No disponible: no aceptas nuevas ni recibes asignaciones automáticas. Es independiente de tener la sesión abierta.',
                            warning:
                                'Ponerte como no disponible no te quita las conversaciones que ya atiendes: las sigues gestionando.',
                        }"
                    />
                </div>
            </div>

            <nav aria-label="Bandejas">
                <div
                    class="flex flex-wrap gap-1"
                    role="group"
                    aria-label="Filtrar la bandeja"
                >
                    <button
                        v-for="chip in chips"
                        :key="chip.key"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium whitespace-nowrap transition-colors pointer-coarse:min-h-10 pointer-coarse:px-3"
                        :class="
                            activeChip === chip.key
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                        "
                        :aria-pressed="activeChip === chip.key"
                        :title="chip.help"
                        :data-inbox-chip="chip.key"
                        @click="selectChip(chip)"
                    >
                        <Hourglass
                            v-if="chip.warn && (chip.count ?? 0) > 0"
                            class="size-3.5 shrink-0"
                            :class="
                                activeChip === chip.key
                                    ? undefined
                                    : 'text-warning'
                            "
                            aria-hidden="true"
                        />
                        {{ chip.label }}
                        <template v-if="chip.count !== null">
                            <span
                                class="rounded-full px-1.5 tabular-nums"
                                :class="
                                    activeChip === chip.key
                                        ? 'bg-primary-foreground/20'
                                        : chip.warn && chip.count > 0
                                          ? 'bg-warning/15 font-semibold text-foreground'
                                          : 'bg-muted'
                                "
                                aria-hidden="true"
                                >{{ chip.count }}</span
                            >
                            <span class="sr-only"
                                >: {{ chip.countLabel(chip.count) }}</span
                            >
                        </template>
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
                        v-model="state.search"
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
                <Popover>
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 shrink-0 pointer-coarse:h-10 pointer-coarse:min-w-10"
                            :aria-label="
                                activeFilterCount > 0
                                    ? `Filtros (${activeFilterCount} activos)`
                                    : 'Filtros y orden'
                            "
                        >
                            <ListFilter aria-hidden="true" />
                            <span
                                v-if="activeFilterCount > 0"
                                class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums"
                                >{{ activeFilterCount }}</span
                            >
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" class="grid w-72 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="inbox-search-in">Buscar en</Label>
                            <select
                                id="inbox-search-in"
                                v-model="state.searchIn"
                                :class="selectClass"
                                @change="state.search.trim() !== '' && apply()"
                            >
                                <option
                                    v-for="option in searchScopes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="inbox-sort">Ordenar por</Label>
                            <select
                                id="inbox-sort"
                                v-model="state.sort"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option
                                    v-for="option in sortOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div v-if="offersStatusFilter" class="grid gap-1.5">
                            <Label for="inbox-status">Estado</Label>
                            <select
                                id="inbox-status"
                                v-model="state.status"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option
                                    v-for="option in statusOptions"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="inbox-department">Departamento</Label>
                            <select
                                id="inbox-department"
                                v-model="state.department"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option :value="ALL">
                                    Todos los departamentos
                                </option>
                                <option v-if="canSeeUnrouted" value="none">
                                    Sin departamento
                                </option>
                                <option
                                    v-for="department in departments"
                                    :key="department.id"
                                    :value="String(department.id)"
                                >
                                    {{ department.name
                                    }}{{
                                        department.is_active
                                            ? ''
                                            : ' (inactivo)'
                                    }}
                                </option>
                            </select>
                        </div>
                        <div v-if="!inbox.personal" class="grid gap-1.5">
                            <Label for="inbox-agent">Responsable</Label>
                            <select
                                id="inbox-agent"
                                v-model="state.agent"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option :value="ALL">Todos los agentes</option>
                                <option value="none">Sin agente</option>
                                <option
                                    v-for="agent in agents"
                                    :key="agent.id"
                                    :value="String(agent.id)"
                                >
                                    {{ agent.name }}
                                </option>
                            </select>
                        </div>
                        <div v-if="connections.length > 1" class="grid gap-1.5">
                            <Label for="inbox-connection">Conexión</Label>
                            <select
                                id="inbox-connection"
                                v-model="state.connection"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option :value="ALL">
                                    Todas las conexiones
                                </option>
                                <option
                                    v-for="connection in connections"
                                    :key="connection.id"
                                    :value="String(connection.id)"
                                >
                                    {{ connection.name
                                    }}{{
                                        connection.display_phone_number
                                            ? ` · ${formatPhone(connection.display_phone_number)}`
                                            : ''
                                    }}
                                </option>
                            </select>
                        </div>
                        <div v-if="showChannels" class="grid gap-1.5">
                            <Label for="inbox-channel">Canal</Label>
                            <select
                                id="inbox-channel"
                                v-model="state.channel"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option :value="ALL">Todos los canales</option>
                                <option
                                    v-for="channel in filterOptions.channels"
                                    :key="channel.value"
                                    :value="channel.value"
                                >
                                    {{ channel.label }}
                                </option>
                            </select>
                        </div>
                        <div v-if="tags.length > 0" class="grid gap-1.5">
                            <Label for="inbox-tag">Etiqueta</Label>
                            <select
                                id="inbox-tag"
                                v-model="state.tag"
                                :class="selectClass"
                                @change="apply"
                            >
                                <option :value="ALL">
                                    Todas las etiquetas
                                </option>
                                <option
                                    v-for="tag in tags"
                                    :key="tag.id"
                                    :value="String(tag.id)"
                                >
                                    {{ tag.name }}
                                </option>
                            </select>
                        </div>
                        <Button
                            v-if="hasFilters"
                            variant="ghost"
                            size="sm"
                            @click="clearFilters"
                        >
                            <X aria-hidden="true" />
                            Limpiar filtros
                        </Button>
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

            <div class="flex flex-wrap items-center gap-1 pb-0.5">
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs transition-colors pointer-coarse:min-h-10 pointer-coarse:px-3"
                    :class="
                        state.unread
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    :aria-pressed="state.unread"
                    title="Solo las conversaciones con mensajes del cliente que aún no has leído"
                    data-inbox-unread
                    @click="toggleUnread"
                >
                    <MailWarning class="size-3.5" aria-hidden="true" />
                    No leídas
                    <span class="tabular-nums"> · {{ unreadCount }}</span>
                </button>
            </div>
        </header>

        <div
            v-if="
                isUnavailable &&
                state.view === 'general' &&
                (state.status === 'pending' || counts.pending > 0)
            "
            class="flex items-start gap-2 border-b bg-warning/10 px-3 py-2 text-xs"
            role="status"
        >
            <CircleAlert
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <p class="flex-1">
                Estás como No disponible: no puedes aceptar pendientes ni
                recibir asignaciones automáticas.
            </p>
            <Button
                size="sm"
                variant="outline"
                class="pointer-coarse:h-10"
                :disabled="availabilityProcessing"
                @click="toggleAvailability(true)"
            >
                Disponible
            </Button>
        </div>

        <div
            class="min-h-0 flex-1 overflow-y-auto"
            :aria-busy="loading"
            @scroll.passive="onScroll"
        >
            <ul
                v-if="conversations.data.length > 0"
                ref="listElement"
                class="divide-y transition-opacity"
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
                        :show-assignee="!inbox.personal"
                        :show-channel="showChannels"
                        :unavailable="isUnavailable"
                        :accepting-id="acceptingId"
                        @select="(id) => emit('select', id)"
                        @accept="(id) => emit('accept', id)"
                    />
                </li>
            </ul>

            <div v-else-if="loading" class="grid gap-3 p-3" aria-hidden="true">
                <Skeleton v-for="index in 6" :key="index" class="h-16 w-full" />
            </div>

            <EmptyState
                v-else
                class="m-3 border-none"
                :icon="Inbox"
                :title="
                    hasFilters
                        ? 'Ninguna conversación coincide'
                        : activeChip === 'pending'
                          ? 'No hay pendientes por aceptar'
                          : activeChip === 'base' && baseView === 'general'
                            ? 'Tu bandeja está al día'
                            : 'No hay conversaciones aquí'
                "
                :description="
                    hasFilters
                        ? 'Prueba con otra búsqueda, elige otra bandeja o limpia los filtros.'
                        : baseView === 'general' &&
                            ['base', 'pending'].includes(activeChip)
                          ? 'Aquí aparecen las pendientes que puedes aceptar, las que atiendes y las que están en espera. Si tus departamentos distribuyen las conversaciones de forma manual, no tienes pendientes por aceptar: un supervisor te las asigna y aparecerán aquí al recibirlas.'
                          : 'Las conversaciones aparecen cuando un cliente escribe a una de tus conexiones (WhatsApp o chat web). Solo ves las que corresponden a tu alcance.'
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
    </section>
</template>
