<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, Inbox, ListFilter, Search, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
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
import { update as updateAvailability } from '@/routes/conversations/availability';
import type {
    ConversationFilters,
    ConversationInbox,
    ConversationInboxView,
    ConversationList,
    ConversationSort,
    ConversationStatusFilter,
    ConversationStatusValue,
    TagOption,
    ViewerAvailability,
} from '@/types';

/**
 * Left panel of the inbox: quick access to the views the viewer has (their
 * conversations, the pending queue they may accept from or every authorized
 * one), status tabs with counters, search, filters, sorting and the list
 * itself, loaded incrementally. Filters only ever narrow what the server
 * already authorized.
 */
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
    selectedId: number | null;
    loading: boolean;
}>();

const emit = defineEmits<{
    select: [id: number];
    filter: [query: Record<string, string>];
    loadMore: [];
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
    tag: props.filters.tag === null ? ALL : String(props.filters.tag),
    sort: props.filters.sort,
    search: props.filters.search,
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
        state.tag = filters.tag === null ? ALL : String(filters.tag);
        state.sort = filters.sort;
    },
);

const defaultView = computed(() => props.inbox.views[0]);
const isQueue = computed(() => state.view === 'queue');
const isUnavailable = computed(() => props.availability.value !== 'available');

const viewLabels: Record<ConversationInboxView, string> = {
    all: 'Todas',
    mine: 'Mis conversaciones',
    queue: 'Pendientes',
};

const viewHelp: Record<ConversationInboxView, string> = {
    all: 'Las conversaciones de tu alcance, con filtros por departamento, agente, estado y conexión.',
    mine: 'Las conversaciones de las que eres responsable.',
    queue: 'Conversaciones que esperan a que alguien de tu departamento las acepte. No incluye las que atienden tus compañeros.',
};

const views = computed(() =>
    props.inbox.views.map((view) => ({
        value: view,
        label: viewLabels[view],
        count: props.viewCounts[view] ?? 0,
    })),
);

const statusTabs = computed<
    { value: ConversationStatusFilter; label: string; count: number | null }[]
>(() => [
    {
        value: 'active',
        label: 'Activas',
        count: props.counts.pending + props.counts.open + props.counts.on_hold,
    },
    ...props.statuses.map((status) => ({
        value: status.value as ConversationStatusFilter,
        label: status.label,
        count: props.counts[status.value],
    })),
    { value: 'all', label: 'Todas', count: null },
]);

const sortOptions: { value: ConversationSort; label: string }[] = [
    { value: 'priority', label: 'Pendientes primero' },
    { value: 'recent', label: 'Actividad más reciente' },
    { value: 'oldest', label: 'Esperando hace más tiempo' },
];

const activeFilterCount = computed(
    () =>
        [state.department, state.agent, state.connection, state.tag].filter(
            (value) => value !== ALL,
        ).length + (state.sort !== 'priority' ? 1 : 0),
);

const hasFilters = computed(
    () => activeFilterCount.value > 0 || state.search.trim() !== '',
);

function query(): Record<string, string> {
    const result: Record<string, string> = {};

    if (state.view !== defaultView.value) {
        result.view = state.view;
    }

    if (state.status !== 'active' && !isQueue.value) {
        result.status = state.status;
    }

    for (const key of ['department', 'agent', 'connection', 'tag'] as const) {
        if (state[key] !== ALL && !(key === 'agent' && props.inbox.personal)) {
            result[key] = state[key];
        }
    }

    if (state.sort !== 'priority') {
        result.sort = state.sort;
    }

    if (state.search.trim() !== '') {
        result.search = state.search.trim();
    }

    return result;
}

function apply(): void {
    emit('filter', query());
}

watchDebounced(() => state.search, apply, { debounce: 300 });

function selectView(view: ConversationInboxView): void {
    state.view = view;
    state.status = 'active';
    apply();
}

function selectStatus(status: ConversationStatusFilter): void {
    state.status = status;
    apply();
}

function clearFilters(): void {
    state.department = ALL;
    state.agent = ALL;
    state.connection = ALL;
    state.tag = ALL;
    state.sort = 'priority';
    state.search = '';
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
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col bg-background"
        aria-label="Lista de conversaciones"
    >
        <header class="grid min-w-0 grid-cols-1 gap-3 border-b px-3 pt-3 pb-2">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="text-base font-semibold">Conversaciones</h1>
                <div class="flex flex-wrap items-center gap-1">
                    <ExportMenu
                        v-if="canExport"
                        report="conversations"
                        :filters="filters"
                        label="Exportar"
                    />
                    <label
                        class="flex cursor-pointer items-center gap-2 rounded-md border px-2 py-1 text-xs"
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

            <nav
                v-if="views.length > 1"
                class="flex flex-wrap gap-1 rounded-lg bg-muted/60 p-1"
                aria-label="Bandejas"
            >
                <button
                    v-for="view in views"
                    :key="view.value"
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium whitespace-nowrap transition-colors"
                    :class="
                        state.view === view.value
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="state.view === view.value"
                    :title="viewHelp[view.value]"
                    @click="selectView(view.value)"
                >
                    {{ view.label }}
                    <span
                        class="rounded-full px-1.5 tabular-nums"
                        :class="
                            view.value === 'queue' && view.count > 0
                                ? 'bg-warning/20 text-warning'
                                : 'bg-muted'
                        "
                        >{{ view.count }}</span
                    >
                </button>
            </nav>

            <div class="flex items-center gap-2" role="search">
                <div class="relative min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="state.search"
                        type="search"
                        placeholder="Buscar por nombre o teléfono"
                        class="h-9 pl-8"
                        aria-label="Buscar conversaciones"
                    />
                </div>
                <Popover>
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-9 shrink-0"
                            :aria-label="
                                activeFilterCount > 0
                                    ? `Filtros (${activeFilterCount} activos)`
                                    : 'Filtros y orden'
                            "
                        >
                            <ListFilter aria-hidden="true" />
                            <span
                                v-if="activeFilterCount > 0"
                                class="rounded-full bg-primary px-1.5 text-[11px] text-primary-foreground tabular-nums"
                                >{{ activeFilterCount }}</span
                            >
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" class="grid w-72 gap-3">
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
                                    {{ connection.name }}
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

            <nav
                v-if="!isQueue"
                class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-0.5"
                aria-label="Estado de las conversaciones"
            >
                <button
                    v-for="tab in statusTabs"
                    :key="tab.value"
                    type="button"
                    class="shrink-0 rounded-full border px-2.5 py-0.5 text-xs transition-colors"
                    :class="
                        state.status === tab.value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    :aria-pressed="state.status === tab.value"
                    @click="selectStatus(tab.value)"
                >
                    {{ tab.label }}
                    <span v-if="tab.count !== null" class="tabular-nums">
                        · {{ tab.count }}</span
                    >
                </button>
            </nav>
        </header>

        <div
            v-if="isQueue && isUnavailable"
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
                class="h-7"
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
                        :show-assignee="
                            !inbox.personal || state.view === 'queue'
                        "
                        @select="(id) => emit('select', id)"
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
                        : isQueue
                          ? 'No hay pendientes en tu departamento'
                          : 'No hay conversaciones aquí'
                "
                :description="
                    hasFilters
                        ? 'Prueba con otra búsqueda o limpia los filtros.'
                        : 'Las conversaciones aparecen cuando un cliente escribe a una conexión de WhatsApp. Solo ves las que corresponden a tu alcance.'
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
