<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    CircleAlert,
    Eye,
    Hand,
    Inbox,
    MessagesSquare,
    Search,
    UserRound,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import DataPagination from '@/components/DataPagination.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { useViewMode } from '@/composables/useViewMode';
import { formatDateTime, formatRelative } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import {
    accept,
    index as conversationsIndex,
    show,
} from '@/routes/conversations';
import { update as updateAvailability } from '@/routes/conversations/availability';
import type {
    ConversationFilters,
    ConversationInbox,
    ConversationInboxView,
    ConversationStatusFilter,
    ConversationStatusValue,
    ConversationSummary,
    Paginated,
    RecordAction,
    ViewerAvailability,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conversaciones', href: conversationsIndex() }],
    },
});

const props = defineProps<{
    conversations: Paginated<ConversationSummary>;
    filters: ConversationFilters;
    canExport?: boolean;
    counts: Record<ConversationStatusValue, number>;
    statuses: { value: ConversationStatusValue; label: string }[];
    departments: { id: number; name: string; is_active: boolean }[];
    agents: { id: number; name: string; is_active: boolean }[];
    connections: { id: number; name: string }[];
    availability: ViewerAvailability;
    inbox: ConversationInbox;
    viewCounts: Partial<Record<ConversationInboxView, number>>;
    canSeeUnrouted: boolean;
}>();

/**
 * Without WebSockets, the queue refreshes itself in the background; own
 * operations refresh immediately through the redirect that follows them.
 */
usePoll(15_000, { only: ['conversations', 'counts', 'viewCounts'] });

const ALL = '';

const filterState = reactive({
    view: props.filters.view,
    status: props.filters.status,
    department: props.filters.department ?? ALL,
    agent: props.filters.agent ?? ALL,
    connection:
        props.filters.connection === null
            ? ALL
            : String(props.filters.connection),
    search: props.filters.search,
});

const defaultView = computed(() => props.inbox.views[0]);

const viewLabels: Record<
    ConversationInboxView,
    { label: string; description: string }
> = {
    all: {
        label: 'Todas las conversaciones autorizadas',
        description:
            'Las conversaciones de tu alcance, con filtros por departamento, agente, estado y conexión.',
    },
    mine: {
        label: 'Mis conversaciones',
        description: 'Las conversaciones de las que eres responsable.',
    },
    queue: {
        label: 'Pendientes de mi departamento',
        description:
            'Conversaciones que esperan a que alguien de tu departamento las acepte. No incluye las que atienden tus compañeros.',
    },
};

/** Personal inboxes switch between their own work and the queue. */
const viewTabs = computed(() =>
    props.inbox.personal
        ? props.inbox.views.map((view) => ({
              value: view,
              label: viewLabels[view].label,
              count: props.viewCounts[view] ?? 0,
          }))
        : [],
);

/** Team inboxes narrow the authorized list to their own conversations. */
const canShowOnlyMine = computed(
    () => !props.inbox.personal && props.inbox.views.includes('mine'),
);

const onlyMine = computed({
    get: () => filterState.view === 'mine',
    set: (value: boolean) => (filterState.view = value ? 'mine' : 'all'),
});

const isQueue = computed(() => filterState.view === 'queue');
const isUnavailable = computed(() => props.availability.value !== 'available');

const tabs = computed<
    { value: ConversationStatusFilter; label: string; count: number | null }[]
>(() => [
    {
        value: 'active',
        label: 'Activas',
        count: props.counts.pending + props.counts.open + props.counts.on_hold,
    },
    ...props.statuses.map((status) => ({
        value: status.value,
        label: status.label,
        count: props.counts[status.value],
    })),
    { value: 'all', label: 'Todas', count: null },
]);

const hasFilters = computed(
    () =>
        filterState.department !== ALL ||
        filterState.agent !== ALL ||
        filterState.connection !== ALL ||
        filterState.search.trim() !== '' ||
        (canShowOnlyMine.value && onlyMine.value),
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.view !== defaultView.value) {
        query.view = filterState.view;
    }

    if (filterState.status !== 'active' && !isQueue.value) {
        query.status = filterState.status;
    }

    for (const key of ['department', 'agent', 'connection'] as const) {
        if (
            filterState[key] !== ALL &&
            !(key === 'agent' && props.inbox.personal)
        ) {
            query[key] = filterState[key];
        }
    }

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    router.get(conversationsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['conversations', 'filters', 'counts', 'viewCounts'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function selectView(view: ConversationInboxView): void {
    filterState.view = view;
    filterState.status = 'active';
    applyFilters();
}

function selectStatus(status: ConversationStatusFilter): void {
    filterState.status = status;
    applyFilters();
}

function clearFilters(): void {
    filterState.department = ALL;
    filterState.agent = ALL;
    filterState.connection = ALL;
    filterState.search = '';

    if (canShowOnlyMine.value) {
        filterState.view = defaultView.value;
    }

    applyFilters();
}

const loading = ref(false);
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.method === 'get' && !event.detail.visit.async) {
        loading.value = true;
    }
});
const stopFinish = router.on('finish', () => (loading.value = false));

onUnmounted(() => {
    stopStart();
    stopFinish();
});

const { mode: viewMode } = useViewMode('conversations');

const processingId = ref<number | null>(null);

function acceptConversation(conversation: ConversationSummary): void {
    router.post(accept.url(conversation.id), undefined, {
        preserveScroll: true,
        onStart: () => (processingId.value = conversation.id),
        onFinish: () => (processingId.value = null),
        onError: (errors) => {
            const message = Object.values(errors)[0];

            if (message) {
                toast.error(message);
            }
        },
    });
}

function actionsFor(conversation: ConversationSummary): RecordAction[] {
    const actions: RecordAction[] = [];

    if (conversation.can.view) {
        actions.push({
            key: 'view',
            label: 'Ver conversación',
            icon: Eye,
            href: show(conversation.id),
        });
    }

    if (conversation.can.accept) {
        // Unavailable agents keep seeing the queue but cannot take from it;
        // the label (tooltip and accessible name) explains why.
        actions.push({
            key: 'accept',
            label: isUnavailable.value
                ? 'Aceptar (no disponible: cambia tu disponibilidad para aceptar)'
                : 'Aceptar',
            icon: Hand,
            disabled: isUnavailable.value,
            onSelect: () => acceptConversation(conversation),
        });
    }

    return actions;
}

const availabilityProcessing = ref(false);

function toggleAvailability(available: boolean): void {
    router.put(
        updateAvailability.url(),
        { availability: available ? 'available' : 'unavailable' },
        {
            preserveScroll: true,
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

function contactPhone(conversation: ConversationSummary): string {
    return conversation.contact.phone_e164
        ? formatPhone(conversation.contact.phone_e164)
        : 'Sin teléfono';
}
</script>

<template>
    <Head title="Conversaciones" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Conversaciones"
            description="Cola de atención: acepta pendientes, sigue tus conversaciones y consulta las de tu alcance."
        >
            <template #actions>
                <ExportMenu
                    v-if="canExport"
                    report="conversations"
                    :filters="filters"
                />
                <div
                    class="flex items-center gap-2 rounded-lg border bg-card py-2 pr-2 pl-3 text-sm shadow-card"
                >
                    <label class="flex cursor-pointer items-center gap-3">
                        <span class="grid">
                            <span class="font-medium">{{
                                availability.label
                            }}</span>
                            <span class="text-xs text-muted-foreground"
                                >Aceptar y recibir conversaciones</span
                            >
                        </span>
                        <Switch
                            :model-value="availability.value === 'available'"
                            :disabled="availabilityProcessing"
                            aria-label="Disponible para aceptar y recibir conversaciones"
                            @update:model-value="toggleAvailability"
                        />
                    </label>
                    <span
                        class="ml-1 h-8 w-px self-center bg-border"
                        aria-hidden="true"
                    />
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
            </template>
        </PageHeader>

        <div v-if="inbox.personal" class="grid gap-2">
            <nav
                class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1"
                aria-label="Bandeja de conversaciones"
            >
                <Button
                    v-for="tab in viewTabs"
                    :key="tab.value"
                    :variant="
                        filterState.view === tab.value ? 'default' : 'outline'
                    "
                    class="shrink-0"
                    :aria-pressed="filterState.view === tab.value"
                    @click="selectView(tab.value)"
                >
                    {{ tab.label }}
                    <span
                        class="rounded-full px-1.5 text-xs tabular-nums"
                        :class="
                            filterState.view === tab.value
                                ? 'bg-primary-foreground/20'
                                : 'bg-muted'
                        "
                        >{{ tab.count }}</span
                    >
                </Button>
            </nav>
            <p class="text-sm text-muted-foreground">
                {{ viewLabels[filterState.view].description }}
            </p>
        </div>
        <div v-else class="grid gap-1">
            <h2 class="text-base font-semibold">
                {{ onlyMine ? viewLabels.mine.label : viewLabels.all.label }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{
                    onlyMine
                        ? viewLabels.mine.description
                        : viewLabels.all.description
                }}
            </p>
        </div>

        <Alert v-if="isQueue && isUnavailable" role="status">
            <CircleAlert aria-hidden="true" />
            <AlertTitle>Estás como No disponible</AlertTitle>
            <AlertDescription class="grid gap-3">
                <p>
                    Mientras no estés disponible no puedes aceptar
                    conversaciones pendientes ni recibir asignaciones
                    automáticas. Sigues atendiendo las que ya tienes.
                </p>
                <Button
                    class="w-fit"
                    :disabled="availabilityProcessing"
                    @click="toggleAvailability(true)"
                >
                    Cambiar a Disponible
                </Button>
            </AlertDescription>
        </Alert>

        <nav
            v-if="!isQueue"
            class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1"
            aria-label="Estado de las conversaciones"
        >
            <Button
                v-for="tab in tabs"
                :key="tab.value"
                size="sm"
                :variant="
                    filterState.status === tab.value ? 'default' : 'ghost'
                "
                class="shrink-0"
                :aria-pressed="filterState.status === tab.value"
                @click="selectStatus(tab.value)"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== null"
                    class="rounded-full px-1.5 text-xs tabular-nums"
                    :class="
                        filterState.status === tab.value
                            ? 'bg-primary-foreground/20'
                            : 'bg-muted'
                    "
                    >{{ tab.count }}</span
                >
            </Button>
        </nav>

        <SurfaceCard>
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4"
                role="search"
            >
                <div class="relative min-w-0 flex-1 basis-60">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="filterState.search"
                        type="search"
                        placeholder="Buscar por contacto o teléfono"
                        class="pl-9"
                        aria-label="Buscar conversaciones"
                    />
                </div>
                <ViewModeToggle v-model="viewMode" />
                <div
                    class="grid w-full grid-cols-1 gap-2 lg:flex lg:w-auto lg:flex-wrap"
                    :class="
                        inbox.personal ? 'sm:grid-cols-2' : 'sm:grid-cols-3'
                    "
                >
                    <select
                        v-model="filterState.department"
                        :class="selectClass"
                        class="lg:w-44"
                        aria-label="Filtrar por departamento"
                        @change="applyFilters"
                    >
                        <option :value="ALL">Todos los departamentos</option>
                        <option v-if="canSeeUnrouted" value="none">
                            Sin departamento
                        </option>
                        <option
                            v-for="department in departments"
                            :key="department.id"
                            :value="String(department.id)"
                        >
                            {{ department.name
                            }}{{ department.is_active ? '' : ' (inactivo)' }}
                        </option>
                    </select>
                    <select
                        v-if="!inbox.personal"
                        v-model="filterState.agent"
                        :class="selectClass"
                        class="lg:w-44"
                        aria-label="Filtrar por agente"
                        @change="applyFilters"
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
                    <select
                        v-model="filterState.connection"
                        :class="selectClass"
                        class="lg:w-44"
                        aria-label="Filtrar por conexión"
                        @change="applyFilters"
                    >
                        <option :value="ALL">Todas las conexiones</option>
                        <option
                            v-for="connection in connections"
                            :key="connection.id"
                            :value="String(connection.id)"
                        >
                            {{ connection.name }}
                        </option>
                    </select>
                </div>
                <label
                    v-if="canShowOnlyMine"
                    class="flex h-9 items-center gap-2 rounded-md border px-3 text-sm"
                >
                    <Switch
                        v-model="onlyMine"
                        aria-label="Solo mis conversaciones"
                        @update:model-value="applyFilters"
                    />
                    Solo mías
                </label>
                <Button v-if="hasFilters" variant="ghost" @click="clearFilters">
                    <X />
                    Limpiar
                </Button>
            </div>

            <Table
                v-if="viewMode === 'table' && conversations.data.length > 0"
                class="transition-opacity"
                :class="loading && 'opacity-60'"
                :aria-busy="loading"
            >
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Contacto</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Departamento</TableHead
                        >
                        <TableHead class="hidden md:table-cell"
                            >Responsable</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Conexión</TableHead
                        >
                        <TableHead class="hidden sm:table-cell"
                            >Último mensaje</TableHead
                        >
                        <TableHead class="w-px pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="conversation in conversations.data"
                        :key="conversation.id"
                        :class="
                            conversation.status === 'closed' &&
                            'text-muted-foreground'
                        "
                    >
                        <TableCell class="py-3 pl-5">
                            <Link
                                v-if="conversation.can.view"
                                :href="show(conversation.id)"
                                class="block max-w-56 truncate font-medium text-foreground hover:underline"
                                >{{ conversation.contact.name }}</Link
                            >
                            <span
                                v-else
                                class="block max-w-56 truncate font-medium"
                                >{{ conversation.contact.name }}</span
                            >
                            <p class="truncate text-xs text-muted-foreground">
                                {{ contactPhone(conversation) }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <ConversationStatusBadge
                                :status="conversation.status"
                                :label="conversation.status_label"
                            />
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            <DepartmentTag
                                v-if="conversation.department"
                                :name="conversation.department.name"
                                :color="conversation.department.color"
                                :muted="!conversation.department.is_active"
                            />
                            <span v-else class="text-xs text-muted-foreground"
                                >Sin departamento</span
                            >
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            <span
                                v-if="conversation.assignee"
                                class="text-sm"
                                >{{ conversation.assignee.name }}</span
                            >
                            <span v-else class="text-xs text-muted-foreground"
                                >Sin agente</span
                            >
                        </TableCell>
                        <TableCell
                            class="hidden max-w-40 truncate text-sm lg:table-cell"
                        >
                            {{ conversation.connection.name }}
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <span
                                v-if="conversation.last_message_at"
                                class="text-sm"
                                :title="
                                    formatDateTime(conversation.last_message_at)
                                "
                                >{{
                                    formatRelative(conversation.last_message_at)
                                }}</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(conversation)"
                                :record="conversation.contact.name"
                                :busy="processingId === conversation.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid
                v-else-if="conversations.data.length > 0"
                class="transition-opacity"
                :class="loading && 'opacity-60'"
                :aria-busy="loading"
            >
                <li
                    v-for="conversation in conversations.data"
                    :key="conversation.id"
                >
                    <RecordCard
                        :muted="conversation.status === 'closed'"
                        :href="
                            conversation.can.view
                                ? show(conversation.id)
                                : undefined
                        "
                        :label="`Ver conversación con ${conversation.contact.name}`"
                    >
                        <template #leading>
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                            >
                                <MessagesSquare
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                        </template>
                        <template #title>
                            <span class="block truncate">{{
                                conversation.contact.name
                            }}</span>
                        </template>
                        <template #subtitle>{{
                            contactPhone(conversation)
                        }}</template>
                        <template #badges>
                            <ConversationStatusBadge
                                :status="conversation.status"
                                :label="conversation.status_label"
                            />
                            <DepartmentTag
                                v-if="conversation.department"
                                :name="conversation.department.name"
                                :color="conversation.department.color"
                                :muted="!conversation.department.is_active"
                            />
                        </template>
                        <p
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <UserRound class="size-3.5" aria-hidden="true" />
                            {{ conversation.assignee?.name ?? 'Sin agente' }}
                        </p>
                        <p
                            v-if="conversation.on_hold_reason"
                            class="line-clamp-2 text-xs text-muted-foreground"
                        >
                            En espera: {{ conversation.on_hold_reason }}
                        </p>
                        <template #meta>
                            <span class="truncate">{{
                                conversation.connection.name
                            }}</span>
                            <span
                                v-if="conversation.last_message_at"
                                :title="
                                    formatDateTime(conversation.last_message_at)
                                "
                                >{{
                                    formatRelative(conversation.last_message_at)
                                }}</span
                            >
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(conversation)"
                                :record="conversation.contact.name"
                                :busy="processingId === conversation.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>

            <EmptyState
                v-else
                class="m-4 border-none"
                :icon="Inbox"
                :title="
                    hasFilters
                        ? 'Ninguna conversación coincide con los filtros'
                        : isQueue
                          ? 'No hay conversaciones pendientes en tu departamento'
                          : 'No hay conversaciones aquí'
                "
                description="Las conversaciones aparecen cuando un cliente escribe a una conexión de WhatsApp. Solo ves las que corresponden a tu alcance."
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    @click="clearFilters"
                    >Limpiar filtros</Button
                >
            </EmptyState>

            <div
                v-if="conversations.data.length > 0"
                class="border-t px-4 py-3"
            >
                <DataPagination
                    :meta="conversations.meta"
                    noun="conversaciones"
                />
            </div>
        </SurfaceCard>
    </div>
</template>
