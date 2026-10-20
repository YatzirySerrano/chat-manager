<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleAlert,
    LayoutGrid,
    List,
    MoreHorizontal,
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
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { formatDateTime, formatRelative } from '@/lib/format';
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
const VIEW_STORAGE_KEY = 'connections.view';

type ViewMode = 'list' | 'cards';

function storedView(): ViewMode {
    try {
        return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'list'
            ? 'list'
            : 'cards';
    } catch {
        return 'cards';
    }
}

const view = ref<ViewMode>(storedView());

function setView(mode: ViewMode): void {
    view.value = mode;

    try {
        window.localStorage.setItem(VIEW_STORAGE_KEY, mode);
    } catch {
        // Storage can be unavailable (private mode); the choice just isn't remembered.
    }
}

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
                class="flex flex-col gap-2 border-b p-4 lg:flex-row lg:items-center"
                role="search"
            >
                <div class="relative flex-1">
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
                <div class="grid grid-cols-2 gap-2 lg:flex">
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
                    <div
                        class="flex rounded-md border p-0.5"
                        role="group"
                        aria-label="Tipo de vista"
                    >
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :class="view === 'cards' && 'bg-muted'"
                            :aria-pressed="view === 'cards'"
                            aria-label="Vista de tarjetas"
                            @click="setView('cards')"
                        >
                            <LayoutGrid />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            :class="view === 'list' && 'bg-muted'"
                            :aria-pressed="view === 'list'"
                            aria-label="Vista de lista"
                            @click="setView('list')"
                        >
                            <List />
                        </Button>
                    </div>
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

            <div
                v-else-if="view === 'cards'"
                class="grid grid-cols-1 gap-4 p-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <article
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex flex-col gap-4 rounded-xl border bg-card p-4 transition-shadow hover:shadow-card"
                    :class="!connection.is_active && 'bg-muted/30'"
                >
                    <header class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                                :class="
                                    connection.is_active
                                        ? 'bg-primary-soft text-primary-soft-foreground'
                                        : 'bg-muted text-muted-foreground'
                                "
                            >
                                <PlugZap
                                    v-if="connection.is_active"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                                <Unplug
                                    v-else
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <div class="min-w-0">
                                <Link
                                    :href="showConnection(connection.id)"
                                    class="block truncate font-medium hover:underline"
                                >
                                    {{ connection.name }}
                                </Link>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ phoneLabel(connection) }} ·
                                    {{ connection.channel.label }}
                                </p>
                            </div>
                        </div>
                        <DropdownMenu v-if="can.manage">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="processingId === connection.id"
                                    :aria-label="`Acciones para ${connection.name}`"
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-52">
                                <DropdownMenuItem as-child>
                                    <Link :href="showConnection(connection.id)">
                                        <Pencil />
                                        Editar
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :disabled="
                                        !connection.has_required_credentials
                                    "
                                    @select="verifyCredentials(connection)"
                                >
                                    <ShieldCheck />
                                    Verificar credenciales
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    v-if="!connection.is_active"
                                    :disabled="
                                        !connection.has_required_credentials
                                    "
                                    @select="activate(connection)"
                                >
                                    <Power />
                                    Activar
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-else
                                    @select="askDeactivation(connection)"
                                >
                                    <PowerOff />
                                    Desactivar
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </header>

                    <div class="flex flex-wrap items-center gap-2">
                        <ConnectionHealthBadge :health="connection.health" />
                        <ConnectionHealthBadge
                            v-if="!connection.is_active"
                            :health="connection.configuration_health"
                        />
                    </div>

                    <ConnectionChecklist :checks="connection.checks" compact />

                    <footer class="mt-auto flex flex-wrap gap-1 border-t pt-3">
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
                        <span
                            v-if="connection.departments.length === 0"
                            class="text-xs text-muted-foreground"
                            >Sin departamentos autorizados</span
                        >
                    </footer>
                </article>
            </div>

            <Table v-else>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Conexión</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Credenciales</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Departamentos</TableHead
                        >
                        <TableHead class="hidden xl:table-cell"
                            >Última actividad</TableHead
                        >
                        <TableHead class="w-14 pr-5">
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
                        <TableCell class="py-3 pl-5">
                            <Link
                                :href="showConnection(connection.id)"
                                class="font-medium text-foreground hover:underline"
                            >
                                {{ connection.name }}
                            </Link>
                            <p class="text-xs text-muted-foreground">
                                {{ phoneLabel(connection) }} ·
                                {{ connection.channel.label }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <ConnectionHealthBadge
                                :health="connection.health"
                            />
                        </TableCell>
                        <TableCell class="hidden text-sm md:table-cell">
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
                        <TableCell class="hidden lg:table-cell">
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
                        <TableCell class="hidden text-sm xl:table-cell">
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
                        <TableCell class="pr-5 text-right">
                            <DropdownMenu v-if="can.manage">
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        :disabled="
                                            processingId === connection.id
                                        "
                                        :aria-label="`Acciones para ${connection.name}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-52">
                                    <DropdownMenuItem as-child>
                                        <Link
                                            :href="
                                                showConnection(connection.id)
                                            "
                                        >
                                            <Pencil />
                                            Editar
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        :disabled="
                                            !connection.has_required_credentials
                                        "
                                        @select="verifyCredentials(connection)"
                                    >
                                        <ShieldCheck />
                                        Verificar credenciales
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        v-if="!connection.is_active"
                                        :disabled="
                                            !connection.has_required_credentials
                                        "
                                        @select="activate(connection)"
                                    >
                                        <Power />
                                        Activar
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-else
                                        @select="askDeactivation(connection)"
                                    >
                                        <PowerOff />
                                        Desactivar
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
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
