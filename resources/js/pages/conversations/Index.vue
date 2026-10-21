<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
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
import FieldHelp from '@/components/FieldHelp.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
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
    ConversationStatusFilter,
    ConversationStatusValue,
    ConversationSummary,
    Paginated,
    RecordAction,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conversaciones', href: conversationsIndex() }],
    },
});

const props = defineProps<{
    conversations: Paginated<ConversationSummary>;
    filters: ConversationFilters;
    counts: Record<ConversationStatusValue, number>;
    statuses: { value: ConversationStatusValue; label: string }[];
    departments: { id: number; name: string; is_active: boolean }[];
    agents: { id: number; name: string; is_active: boolean }[];
    connections: { id: number; name: string }[];
    availability: {
        value: 'available' | 'unavailable';
        label: string;
        options: { value: string; label: string }[];
    };
    canSeeUnrouted: boolean;
}>();

/**
 * Without WebSockets, the queue refreshes itself in the background; own
 * operations refresh immediately through the redirect that follows them.
 */
usePoll(15_000, { only: ['conversations', 'counts'] });

const ALL = '';

const filterState = reactive({
    status: props.filters.status,
    department: props.filters.department ?? ALL,
    agent: props.filters.agent ?? ALL,
    connection:
        props.filters.connection === null
            ? ALL
            : String(props.filters.connection),
    search: props.filters.search,
    mine: props.filters.mine,
});

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
        filterState.mine,
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.status !== 'active') {
        query.status = filterState.status;
    }

    for (const key of ['department', 'agent', 'connection'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    if (filterState.mine) {
        query.mine = '1';
    }

    router.get(conversationsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['conversations', 'filters', 'counts'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function selectStatus(status: ConversationStatusFilter): void {
    filterState.status = status;
    applyFilters();
}

function clearFilters(): void {
    filterState.department = ALL;
    filterState.agent = ALL;
    filterState.connection = ALL;
    filterState.search = '';
    filterState.mine = false;
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
        actions.push({
            key: 'accept',
            label: 'Aceptar',
            icon: Hand,
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
                <label
                    class="flex items-center gap-3 rounded-lg border bg-card px-3 py-2 text-sm shadow-card"
                >
                    <span class="grid">
                        <span class="flex items-center gap-1.5 font-medium">
                            {{ availability.label }}
                            <FieldHelp
                                title="Disponibilidad"
                                :help="{
                                    description:
                                        'Indica si puedes recibir conversaciones por asignación automática. Es independiente de tener la sesión abierta.',
                                    warning:
                                        'Ponerte como no disponible no te quita las conversaciones que ya atiendes.',
                                }"
                            />
                        </span>
                        <span class="text-xs text-muted-foreground"
                            >Asignación automática</span
                        >
                    </span>
                    <Switch
                        :model-value="availability.value === 'available'"
                        :disabled="availabilityProcessing"
                        aria-label="Disponible para asignación automática"
                        @update:model-value="toggleAvailability"
                    />
                </label>
            </template>
        </PageHeader>

        <nav
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
                    class="grid w-full grid-cols-1 gap-2 sm:grid-cols-3 lg:flex lg:w-auto lg:flex-wrap"
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
                    class="flex h-9 items-center gap-2 rounded-md border px-3 text-sm"
                >
                    <Switch
                        v-model="filterState.mine"
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
                    <RecordCard :muted="conversation.status === 'closed'">
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
                            <Link
                                v-if="conversation.can.view"
                                :href="show(conversation.id)"
                                class="block truncate hover:underline"
                                >{{ conversation.contact.name }}</Link
                            >
                            <span v-else class="block truncate">{{
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
