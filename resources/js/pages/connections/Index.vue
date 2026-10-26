<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleAlert,
    Eye,
    Pencil,
    Plug,
    PlugZap,
    Plus,
    Power,
    PowerOff,
    Search,
    ShieldCheck,
    Unplug,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import ChannelPicker from '@/components/connections/ChannelPicker.vue';
import ConnectionChecklist from '@/components/connections/ConnectionChecklist.vue';
import ConnectionHealthBadge from '@/components/connections/ConnectionHealthBadge.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { useViewMode } from '@/composables/useViewMode';
import { connectionDeletionCopy } from '@/lib/deletionCopy';
import { formatDateTime, formatRelative } from '@/lib/format';
import { recordActions } from '@/lib/recordActions';
import { toastFirstError } from '@/lib/toastErrors';
import {
    destroy as destroyConnection,
    index as connectionsIndex,
    restore as restoreConnection,
    show as showConnection,
    verify,
} from '@/routes/connections';
import { update as updateStatus } from '@/routes/connections/status';
import type {
    Connection,
    ConnectionFilters,
    ConnectionOption,
    ConnectionStats,
    DepartmentOption,
    MetaChannelAvailability,
    RecordAction,
    RecordStateOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones', href: connectionsIndex() }],
    },
});

const props = defineProps<{
    connections: Connection[];
    filters: ConnectionFilters;
    recordStates: RecordStateOption[];
    stats: ConnectionStats;
    channels: ConnectionOption[];
    healthOptions: ConnectionOption[];
    departments: DepartmentOption[];
    can: { create: boolean; manage: boolean };
    canExport?: boolean;
    metaChannels?: Record<'messenger' | 'instagram', MetaChannelAvailability>;
}>();

const ALL = 'all';

const { mode: viewMode } = useViewMode('connections');

const filterState = reactive({
    search: props.filters.search ?? '',
    channel: props.filters.channel ?? ALL,
    health: props.filters.health ?? ALL,
    department:
        props.filters.department === null
            ? ALL
            : String(props.filters.department),
    status: props.filters.status ?? ALL,
    record: props.filters.record,
});

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.channel !== ALL ||
        filterState.health !== ALL ||
        filterState.department !== ALL ||
        filterState.status !== ALL ||
        filterState.record !== 'active',
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    for (const key of ['channel', 'health', 'department', 'status'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.record !== 'active') {
        query.record = filterState.record;
    }

    router.get(connectionsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['connections', 'filters', 'stats'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    filterState.search = '';
    filterState.channel = ALL;
    filterState.health = ALL;
    filterState.department = ALL;
    filterState.status = ALL;
    filterState.record = 'active';
    applyFilters();
}

const channelPickerOpen = ref(false);

const processingId = ref<number | null>(null);
const pendingDeactivation = ref<Connection | null>(null);
const confirmOpen = ref(false);

const deletion = useRecordDeletion<Connection>({
    destroy: (connection) => destroyConnection(connection.id),
    restore: (connection) => restoreConnection(connection.id),
    copy: connectionDeletionCopy,
});

function verifyCredentials(connection: Connection): void {
    router.visit(verify(connection.id), {
        preserveScroll: true,
        onStart: () => (processingId.value = connection.id),
        onFinish: () => (processingId.value = null),
        onError: toastFirstError,
    });
}

function activate(connection: Connection): void {
    router.visit(updateStatus(connection.id), {
        data: { is_active: true },
        preserveScroll: true,
        onStart: () => (processingId.value = connection.id),
        onFinish: () => (processingId.value = null),
        onError: toastFirstError,
    });
}

function askDeactivation(connection: Connection): void {
    pendingDeactivation.value = connection;
    confirmOpen.value = true;
}

function confirmDeactivation(): void {
    const connection = pendingDeactivation.value;

    if (!connection) {
        return;
    }

    router.visit(updateStatus(connection.id), {
        data: { is_active: false },
        preserveScroll: true,
        onStart: () => (processingId.value = connection.id),
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (processingId.value = null),
        onError: (errors) => {
            confirmOpen.value = false;
            toastFirstError(errors);
        },
    });
}

/**
 * Activation needs the required credentials; the server rejects it
 * otherwise, so the button is only offered when they are saved.
 */
function actionsFor(connection: Connection): RecordAction[] {
    return recordActions(
        connection.can.view &&
            !connection.can.update && {
                key: 'view',
                label: 'Ver',
                icon: Eye,
                href: showConnection(connection.id),
            },
        connection.can.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            href: showConnection(connection.id),
        },
        connection.can.verify &&
            connection.has_required_credentials && {
                key: 'verify',
                label: 'Verificar credenciales',
                icon: ShieldCheck,
                onSelect: () => verifyCredentials(connection),
            },
        connection.can.changeStatus &&
            !connection.is_active &&
            connection.has_required_credentials && {
                key: 'activate',
                label: 'Activar',
                icon: Power,
                onSelect: () => activate(connection),
            },
        connection.can.changeStatus &&
            connection.is_active && {
                key: 'deactivate',
                label: 'Desactivar',
                icon: PowerOff,
                onSelect: () => askDeactivation(connection),
            },
        ...deletion.actionsFor(connection),
    );
}

function phoneLabel(connection: Connection): string {
    if (connection.meta_account) {
        return connection.meta_account.authorized
            ? (connection.meta_account.name ?? 'Cuenta autorizada')
            : 'Desconectada de Meta';
    }

    if (connection.channel.value === 'web_chat') {
        const origins = connection.web_chat_widget?.allowed_origins ?? [];

        if (origins.length === 0) {
            return 'Sin dominios autorizados';
        }

        return origins.length === 1
            ? origins[0]
            : `${origins[0]} y ${origins.length - 1} más`;
    }

    return (
        connection.display_phone_number ??
        (connection.phone_number_id
            ? `ID ${connection.phone_number_id}`
            : 'Sin número configurado')
    );
}
</script>

<template>
    <Head title="Conexiones" />

    <div class="page-shell">
        <PageHeader
            help="conexiones"
            title="Conexiones"
            description="Números de WhatsApp Business conectados mediante la API oficial de Meta, chats para sitios web y su estado real."
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="connections"
                    :filters="filters"
                />
                <Button
                    v-if="can.create"
                    data-tour="connections.new"
                    @click="channelPickerOpen = true"
                >
                    <Plus />
                    Nueva conexión
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="stats.total > 0"
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
            data-tour="connections.stats"
        >
            <StatCard
                label="Conexiones"
                :value="stats.total"
                :icon="Plug"
                hint="Registradas en la plataforma"
            />
            <StatCard
                label="Activas"
                :value="stats.active"
                :icon="Power"
                hint="Encendidas por un administrador"
            />
            <StatCard
                label="Credenciales verificadas"
                :value="stats.verified"
                :icon="ShieldCheck"
                hint="Conexiones de WhatsApp aceptadas por Meta"
            />
            <StatCard
                label="Requieren atención"
                :value="stats.attention"
                :icon="CircleAlert"
                hint="Sin configurar, rechazadas o con error"
            />
        </div>

        <EmptyState
            v-if="stats.total === 0 && recordStates.length === 0"
            :icon="Unplug"
            title="Aún no hay conexiones"
            description="Registra tu número de WhatsApp Business con los datos de tu app de Meta o crea un chat para tu sitio web. Podrás guardar la configuración aunque todavía no esté completa; ningún indicador se marcará como funcionando hasta que exista evidencia real."
        >
            <Button v-if="can.create" @click="channelPickerOpen = true">
                <Plus />
                Nueva conexión
            </Button>
        </EmptyState>

        <SurfaceCard v-else>
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4"
                role="search"
            >
                <div class="relative order-1 min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="filterState.search"
                        type="search"
                        placeholder="Buscar por nombre, número o identificador"
                        class="pl-9"
                        aria-label="Buscar conexiones"
                    />
                </div>
                <ViewModeToggle v-model="viewMode" class="order-2" />
                <div
                    class="order-3 grid w-full grid-cols-2 gap-2 sm:grid-cols-4 lg:flex lg:flex-wrap"
                >
                    <Select
                        v-model="filterState.channel"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-36"
                            aria-label="Filtrar por canal"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL"
                                >Todos los canales</SelectItem
                            >
                            <SelectItem
                                v-for="option in channels"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        v-model="filterState.health"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-48"
                            aria-label="Filtrar por estado de salud"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL"
                                >Cualquier estado de salud</SelectItem
                            >
                            <SelectItem
                                v-for="option in healthOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        v-model="filterState.department"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-44"
                            aria-label="Filtrar por departamento"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL"
                                >Todos los departamentos</SelectItem
                            >
                            <SelectItem
                                v-for="department in departments"
                                :key="department.id"
                                :value="String(department.id)"
                            >
                                {{ department.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        v-model="filterState.status"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-32"
                            aria-label="Filtrar por activación"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Todas</SelectItem>
                            <SelectItem value="active">Activas</SelectItem>
                            <SelectItem value="inactive">Inactivas</SelectItem>
                        </SelectContent>
                    </Select>
                    <RecordStateFilter
                        v-model="filterState.record"
                        :options="recordStates"
                        @change="applyFilters"
                    />
                    <Button
                        v-if="hasFilters"
                        variant="ghost"
                        @click="clearFilters"
                    >
                        <X />
                        Limpiar
                    </Button>
                </div>
            </div>

            <EmptyState
                v-if="connections.length === 0"
                class="m-4 border-none"
                :icon="Search"
                title="Ninguna conexión coincide con los filtros"
                description="Prueba con otros criterios de búsqueda."
            >
                <Button variant="outline" @click="clearFilters"
                    >Limpiar filtros</Button
                >
            </EmptyState>

            <RecordGrid v-else-if="viewMode === 'cards'">
                <li
                    v-for="(connection, position) in connections"
                    :key="connection.id"
                    :data-tour="position === 0 ? 'connections.item' : undefined"
                >
                    <RecordCard
                        :muted="!connection.is_active || connection.is_deleted"
                        :href="showConnection(connection.id)"
                        :label="`Ver conexión ${connection.name}`"
                    >
                        <template #leading>
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                :class="
                                    connection.is_active
                                        ? 'bg-primary-soft text-primary-soft-foreground'
                                        : 'bg-muted text-muted-foreground'
                                "
                            >
                                <PlugZap
                                    v-if="connection.is_active"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <Unplug
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                        </template>
                        <template #title>
                            <span class="block truncate">{{
                                connection.name
                            }}</span>
                        </template>
                        <template #subtitle>
                            <span
                                class="inline-flex min-w-0 items-center gap-1"
                            >
                                <ChannelIcon
                                    :channel="connection.channel.value"
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate"
                                    >{{ connection.channel.label }} ·
                                    {{ phoneLabel(connection) }}</span
                                >
                            </span>
                        </template>
                        <template #badges>
                            <DeletedBadge
                                v-if="connection.is_deleted"
                                feminine
                            />
                            <ConnectionHealthBadge
                                :health="connection.health"
                            />
                            <ConnectionHealthBadge
                                v-if="!connection.is_active"
                                :health="connection.configuration_health"
                            />
                        </template>
                        <ConnectionChecklist
                            v-if="connection.channel.value === 'whatsapp'"
                            :checks="connection.checks"
                            compact
                        />
                        <template #meta>
                            <DepartmentTag
                                v-for="department in connection.departments.slice(
                                    0,
                                    2,
                                )"
                                :key="department.id"
                                :name="department.name"
                                :color="department.color"
                                :muted="!department.is_active"
                            />
                            <span v-if="connection.departments.length > 2">
                                +{{ connection.departments.length - 2 }}
                            </span>
                            <span v-if="connection.departments.length === 0"
                                >Sin departamentos autorizados</span
                            >
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(connection)"
                                :record="connection.name"
                                :busy="
                                    processingId === connection.id ||
                                    deletion.processingId === connection.id
                                "
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>

            <Table v-else>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Conexión</TableHead>
                        <TableHead class="hidden sm:table-cell"
                            >Estado</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Credenciales</TableHead
                        >
                        <TableHead class="hidden xl:table-cell"
                            >Departamentos</TableHead
                        >
                        <TableHead class="hidden 2xl:table-cell"
                            >Última actividad</TableHead
                        >
                        <TableHead class="pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="(connection, position) in connections"
                        :key="connection.id"
                        :data-tour="
                            position === 0 ? 'connections.item' : undefined
                        "
                        :class="
                            !connection.is_active && 'text-muted-foreground'
                        "
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <Link
                                :href="showConnection(connection.id)"
                                class="block truncate font-medium text-foreground hover:underline"
                            >
                                {{ connection.name }}
                            </Link>
                            <p
                                class="flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
                            >
                                <ChannelIcon
                                    :channel="connection.channel.value"
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate"
                                    >{{ connection.channel.label }} ·
                                    {{ phoneLabel(connection) }}</span
                                >
                            </p>
                            <div class="mt-1 flex flex-wrap gap-1 sm:hidden">
                                <DeletedBadge
                                    v-if="connection.is_deleted"
                                    feminine
                                />
                                <ConnectionHealthBadge
                                    :health="connection.health"
                                />
                            </div>
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <div class="flex flex-wrap gap-1">
                                <DeletedBadge
                                    v-if="connection.is_deleted"
                                    feminine
                                />
                                <ConnectionHealthBadge
                                    :health="connection.health"
                                />
                            </div>
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            <span
                                v-if="connection.channel.value === 'web_chat'"
                                class="text-muted-foreground"
                                >No aplica</span
                            >
                            <span
                                v-else-if="
                                    connection.checks.credentials.state ===
                                    'verified'
                                "
                                class="text-success"
                                :title="
                                    formatDateTime(
                                        connection.checks.credentials
                                            .verified_at,
                                    )
                                "
                                >Verificadas
                                {{
                                    formatRelative(
                                        connection.checks.credentials
                                            .verified_at,
                                    )
                                }}</span
                            >
                            <span
                                v-else-if="
                                    connection.checks.credentials.state ===
                                    'invalid'
                                "
                                class="text-destructive"
                                :title="
                                    connection.checks.credentials.error ??
                                    undefined
                                "
                                >Rechazadas por Meta</span
                            >
                            <span
                                v-else-if="
                                    connection.checks.credentials.state ===
                                    'unverified'
                                "
                                class="text-muted-foreground"
                                >Sin verificar</span
                            >
                            <span v-else class="text-muted-foreground"
                                >Incompletas</span
                            >
                        </TableCell>
                        <TableCell class="hidden xl:table-cell">
                            <div
                                v-if="connection.departments.length > 0"
                                class="flex flex-wrap gap-1"
                            >
                                <DepartmentTag
                                    v-for="department in connection.departments.slice(
                                        0,
                                        3,
                                    )"
                                    :key="department.id"
                                    :name="department.name"
                                    :color="department.color"
                                    :muted="!department.is_active"
                                />
                                <span
                                    v-if="connection.departments.length > 3"
                                    class="text-xs text-muted-foreground"
                                >
                                    +{{ connection.departments.length - 3 }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-muted-foreground"
                                >Sin asignar</span
                            >
                        </TableCell>
                        <TableCell class="hidden text-sm 2xl:table-cell">
                            <span
                                v-if="connection.last_activity_at"
                                :title="
                                    formatDateTime(connection.last_activity_at)
                                "
                                >{{
                                    formatRelative(connection.last_activity_at)
                                }}</span
                            >
                            <span v-else class="text-xs text-muted-foreground"
                                >Sin actividad registrada</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(connection)"
                                :record="connection.name"
                                :busy="
                                    processingId === connection.id ||
                                    deletion.processingId === connection.id
                                "
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </SurfaceCard>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`¿Desactivar ${pendingDeactivation?.name ?? 'la conexión'}?`"
            :description="
                pendingDeactivation?.channel.value === 'web_chat'
                    ? 'El widget se mostrará fuera de línea y no aceptará mensajes nuevos. La configuración y el historial se conservan.'
                    : 'La plataforma dejará de procesar los mensajes de este número cuando la integración esté habilitada. La configuración y el historial se conservan.'
            "
            confirm-label="Desactivar"
            destructive
            :processing="processingId !== null"
            @confirm="confirmDeactivation"
        />

        <ChannelPicker
            v-model:open="channelPickerOpen"
            :meta-channels="metaChannels"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
