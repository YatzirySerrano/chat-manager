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
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ConnectionChecklist from '@/components/connections/ConnectionChecklist.vue';
import ConnectionHealthBadge from '@/components/connections/ConnectionHealthBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
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
import { useViewMode } from '@/composables/useViewMode';
import { formatDateTime, formatRelative } from '@/lib/format';
import { recordActions } from '@/lib/recordActions';
import {
    create as createConnection,
    index as connectionsIndex,
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
    RecordAction,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones', href: connectionsIndex() }],
    },
});

const props = defineProps<{
    connections: Connection[];
    filters: ConnectionFilters;
    stats: ConnectionStats;
    channels: ConnectionOption[];
    healthOptions: ConnectionOption[];
    departments: DepartmentOption[];
    can: { create: boolean; manage: boolean };
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
});

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.channel !== ALL ||
        filterState.health !== ALL ||
        filterState.department !== ALL ||
        filterState.status !== ALL,
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
    applyFilters();
}

const processingId = ref<number | null>(null);
const pendingDeactivation = ref<Connection | null>(null);
const confirmOpen = ref(false);

function showFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function verifyCredentials(connection: Connection): void {
    router.visit(verify(connection.id), {
        preserveScroll: true,
        onStart: () => (processingId.value = connection.id),
        onFinish: () => (processingId.value = null),
        onError: showFirstError,
    });
}

function activate(connection: Connection): void {
    router.visit(updateStatus(connection.id), {
        data: { is_active: true },
        preserveScroll: true,
        onStart: () => (processingId.value = connection.id),
        onFinish: () => (processingId.value = null),
        onError: showFirstError,
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
            showFirstError(errors);
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
    );
}

function phoneLabel(connection: Connection): string {
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

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Conexiones"
            description="Números de WhatsApp Business conectados mediante la API oficial de Meta y su estado real."
        >
            <template v-if="can.create" #actions>
                <Button as-child>
                    <Link :href="createConnection()">
                        <Plus />
                        Nueva conexión
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="stats.total > 0"
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
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
                hint="Aceptadas por Meta"
            />
            <StatCard
                label="Requieren atención"
                :value="stats.attention"
                :icon="CircleAlert"
                hint="Sin configurar, rechazadas o con error"
            />
        </div>

        <EmptyState
            v-if="stats.total === 0"
            :icon="Unplug"
            title="Aún no hay conexiones"
            description="Registra tu número de WhatsApp Business con los datos de tu app de Meta. Podrás guardar la configuración aunque todavía no tengas todas las credenciales; ningún indicador se marcará como funcionando hasta que exista evidencia real."
        >
            <Button v-if="can.create" as-child>
                <Link :href="createConnection()">
                    <Plus />
                    Nueva conexión
                </Link>
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
                <li v-for="connection in connections" :key="connection.id">
                    <RecordCard :muted="!connection.is_active">
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
                            <Link
                                :href="showConnection(connection.id)"
                                class="block truncate hover:underline"
                            >
                                {{ connection.name }}
                            </Link>
                        </template>
                        <template #subtitle>
                            {{ phoneLabel(connection) }} ·
                            {{ connection.channel.label }}
                        </template>
                        <template #badges>
                            <ConnectionHealthBadge
                                :health="connection.health"
                            />
                            <ConnectionHealthBadge
                                v-if="!connection.is_active"
                                :health="connection.configuration_health"
                            />
                        </template>
                        <ConnectionChecklist
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
                                :busy="processingId === connection.id"
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
                        v-for="connection in connections"
                        :key="connection.id"
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
                            <p class="truncate text-xs text-muted-foreground">
                                {{ phoneLabel(connection) }} ·
                                {{ connection.channel.label }}
                            </p>
                            <ConnectionHealthBadge
                                class="mt-1 sm:hidden"
                                :health="connection.health"
                            />
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <ConnectionHealthBadge
                                :health="connection.health"
                            />
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            <span
                                v-if="
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
                                :busy="processingId === connection.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </SurfaceCard>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="`¿Desactivar ${pendingDeactivation?.name ?? 'la conexión'}?`"
            description="La plataforma dejará de procesar los mensajes de este número cuando la integración esté habilitada. La configuración y el historial se conservan."
            confirm-label="Desactivar"
            destructive
            :processing="processingId !== null"
            @confirm="confirmDeactivation"
        />
    </div>
</template>
