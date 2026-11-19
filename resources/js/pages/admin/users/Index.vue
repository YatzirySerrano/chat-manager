<script setup lang="ts">
import { vAutoAnimate } from '@formkit/auto-animate/vue';
import { Head, router } from '@inertiajs/vue3';
import {
    Pencil,
    Plus,
    SearchCheck,
    ShieldCheck,
    Trash2,
    UserCheck,
    UserX,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import StatusPill from '@/components/admin/StatusPill.vue';
import UserFormDialog from '@/components/admin/UserFormDialog.vue';
import '@/components/admin/admin.css';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import type {
    ActiveFilterPill,
    FilterTabOption,
} from '@/components/filters/types';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RoleBadge from '@/components/RoleBadge.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { useRecordHighlight } from '@/composables/useRecordHighlight';
import { useViewMode } from '@/composables/useViewMode';
import { formatDateTime, formatRelative } from '@/lib/format';
import { recordActions } from '@/lib/recordActions';
import { scopeIcon } from '@/lib/roles';
import {
    destroy as destroyUser,
    index as usersIndex,
    restore as restoreUser,
} from '@/routes/admin/users';
import type {
    AdminUser,
    DepartmentOption,
    Paginated,
    RecordAction,
    RecordStateOption,
    RecordStateValue,
    RoleOption,
    UserFilters,
    UserSort,
    UserStats,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usuarios', href: usersIndex() }],
    },
});

const props = defineProps<{
    users: Paginated<AdminUser>;
    filters: UserFilters;
    recordStates: RecordStateOption[];
    stats: UserStats;
    roles: RoleOption[];
    departments: DepartmentOption[];
    can: { create: boolean };
    canExport?: boolean;
}>();

const ALL = 'all';

const SORT_OPTIONS: { value: UserSort; label: string }[] = [
    { value: 'name', label: 'Nombre (A-Z)' },
    { value: 'newest', label: 'Más recientes' },
    { value: 'last_login', label: 'Último acceso' },
];

const filterState = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role === null ? ALL : String(props.filters.role),
    department:
        props.filters.department === null
            ? ALL
            : String(props.filters.department),
    record: props.filters.record,
    sort: props.filters.sort ?? 'name',
});

/* A filter request is on its way: the rows dim and a bar runs on top. */
const loading = ref(false);
let visitId = 0;

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.role !== ALL ||
        filterState.department !== ALL ||
        filterState.record !== 'active',
);

const { highlightedId, missing, dismissMissing } = useRecordHighlight(() =>
    props.users.data.map((user) => user.id),
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    for (const key of ['role', 'department'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.record !== 'active') {
        query.record = filterState.record;
    }

    if (filterState.sort !== 'name') {
        query.sort = filterState.sort;
    }

    dismissMissing();

    const visit = ++visitId;

    // Inertia cancels the previous pending visit, so typing fast never lets an
    // older response overwrite the latest results.
    router.get(usersIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['users', 'filters', 'stats'],
        onStart: () => (loading.value = true),
        // A cancelled older visit finishes after the new one started.
        onFinish: () => {
            if (visit === visitId) {
                loading.value = false;
            }
        },
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

/* Activos / Eliminados / Todos as tabs (only when deleted ones can be seen). */
const recordTabs = computed<FilterTabOption<RecordStateValue>[]>(() =>
    props.recordStates.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);

function chooseRecord(value: RecordStateValue): void {
    filterState.record = value;
    applyFilters();
}

/* Role and department as removable pills under the toolbar. */
const pills = computed<ActiveFilterPill[]>(() => {
    const result: ActiveFilterPill[] = [];

    if (filterState.role !== ALL) {
        result.push({
            key: 'role',
            label: `Rol: ${props.roles.find((role) => String(role.id) === filterState.role)?.label ?? 'No disponible'}`,
            remove: () => {
                filterState.role = ALL;
                applyFilters();
            },
        });
    }

    if (filterState.department !== ALL) {
        result.push({
            key: 'department',
            label: `Departamento: ${props.departments.find((department) => String(department.id) === filterState.department)?.name ?? 'No disponible'}`,
            remove: () => {
                filterState.department = ALL;
                applyFilters();
            },
        });
    }

    return result;
});

function clearFilters(): void {
    filterState.search = '';
    filterState.role = ALL;
    filterState.department = ALL;
    filterState.record = 'active';
    applyFilters();
}

function locateMissing(): void {
    if (!missing.value?.email) {
        return;
    }

    filterState.role = ALL;
    filterState.department = ALL;
    filterState.record = props.recordStates.length > 0 ? 'all' : 'active';

    // The search watcher applies the rest; when the box already holds that
    // email nothing changes, so the filters are applied here.
    if (filterState.search === missing.value.email) {
        applyFilters();
    } else {
        filterState.search = missing.value.email;
    }
}

const formOpen = ref(false);
const editingUser = ref<AdminUser | null>(null);

function openCreate(): void {
    editingUser.value = null;
    formOpen.value = true;
}

function openEdit(user: AdminUser): void {
    editingUser.value = user;
    formOpen.value = true;
}

const deletion = useRecordDeletion<AdminUser>({
    destroy: (user) => destroyUser(user.id),
    restore: (user) => restoreUser(user.id),
    copy: (user, step) =>
        step === 'delete'
            ? {
                  title: `¿Eliminar a ${user.name}?`,
                  description:
                      'El usuario perderá el acceso de inmediato y sus conversaciones abiertas volverán a «Pendientes» de su departamento para que otro agente las tome. Su historial se conserva y podrás restaurarlo.',
              }
            : {
                  title: `¿Restaurar a ${user.name}?`,
                  description:
                      'Podrá iniciar sesión de nuevo con su rol y sus departamentos. Las conversaciones que se liberaron al eliminarlo no se le reasignan automáticamente.',
              },
});

const { mode: viewMode } = useViewMode('admin.users');

function actionsFor(user: AdminUser): RecordAction[] {
    return recordActions(
        user.can.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openEdit(user),
        },
        ...deletion.actionsFor(user),
    );
}
</script>

<template>
    <Head title="Usuarios" />

    <div class="page-shell">
        <PageHeader
            help="usuarios"
            title="Usuarios"
            description="Administra las cuentas de los empleados, sus roles y departamentos."
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="users"
                    :filters="filters"
                />
                <Button
                    v-if="can.create"
                    data-tour="users.new"
                    @click="openCreate"
                >
                    <Plus />
                    Nuevo usuario
                </Button>
            </template>
        </PageHeader>

        <div
            class="grid reveal-stagger grid-cols-1 gap-3 sm:grid-cols-3"
            data-tour="users.stats"
        >
            <StatCard
                label="Activos"
                :value="stats.active"
                :icon="UserCheck"
                hint="Usuarios que puedes administrar y que pueden entrar a la plataforma"
            />
            <StatCard
                label="Con verificación en dos pasos"
                :value="stats.two_factor"
                :icon="ShieldCheck"
                hint="Usuarios activos que la tienen configurada"
            />
            <StatCard
                v-if="stats.deleted !== null"
                label="Eliminados"
                :value="stats.deleted"
                :icon="Trash2"
                hint="Conservan su historial y pueden restaurarse"
            />
        </div>

        <div
            v-if="missing"
            class="flex enter-spring flex-col gap-3 rounded-xl border border-primary/25 accent-gradient-soft px-4 py-3 text-sm text-foreground shadow-float sm:flex-row sm:items-center"
            role="status"
        >
            <span class="admin-icon-tile size-8">
                <SearchCheck class="size-4" />
            </span>
            <p class="flex-1">
                <strong>{{ missing.name }}</strong> se guardó correctamente,
                pero no aparece con la página o los filtros actuales.
            </p>
            <div class="flex gap-2">
                <Button size="sm" @click="locateMissing">Ver usuario</Button>
                <Button
                    size="sm"
                    variant="ghost"
                    aria-label="Cerrar aviso"
                    @click="dismissMissing"
                >
                    <X />
                </Button>
            </div>
        </div>

        <SurfaceCard class="admin-list overflow-clip">
            <div
                class="admin-toolbar grid gap-2 px-3 py-2.5"
                role="search"
                data-tour="users.search"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <FilterSearch
                        v-model="filterState.search"
                        placeholder="Buscar por nombre o correo"
                        label="Buscar usuarios"
                        :loading="loading"
                        class="max-sm:basis-full sm:min-w-60"
                    />
                    <FilterTabs
                        v-if="recordTabs.length > 0"
                        class="max-sm:w-full"
                        :options="recordTabs"
                        :model-value="filterState.record"
                        label="Mostrar activos o eliminados"
                        @update:model-value="chooseRecord"
                    />
                    <div class="grid w-full grid-cols-2 gap-2 sm:contents">
                        <Select
                            v-model="filterState.role"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full bg-card sm:w-36"
                                aria-label="Filtrar por rol"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Todos los roles</SelectItem
                                >
                                <SelectItem
                                    v-for="role in roles"
                                    :key="role.id"
                                    :value="String(role.id)"
                                >
                                    {{ role.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            v-model="filterState.department"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full bg-card sm:w-40"
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
                            v-model="filterState.sort"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="col-span-2 w-full bg-card sm:w-40"
                                aria-label="Ordenar por"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in SORT_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <ViewModeToggle v-model="viewMode" class="max-sm:ml-auto" />
                </div>
                <ActiveFilters
                    v-if="pills.length > 0 || hasFilters"
                    :pills="pills"
                    :clearable="hasFilters"
                    @clear="clearFilters"
                />
            </div>

            <div class="relative" :aria-busy="loading">
                <div
                    v-if="loading"
                    class="absolute inset-x-0 top-0 z-10 pane-progress h-0.5"
                    aria-hidden="true"
                />

                <Table
                    v-if="users.data.length > 0 && viewMode === 'table'"
                    :class="loading && 'admin-loading'"
                >
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="pl-5">Usuario</TableHead>
                            <TableHead class="hidden sm:table-cell"
                                >Rol</TableHead
                            >
                            <TableHead class="hidden xl:table-cell"
                                >Departamentos</TableHead
                            >
                            <TableHead class="hidden sm:table-cell"
                                >Estado</TableHead
                            >
                            <TableHead class="hidden xl:table-cell"
                                >Último acceso</TableHead
                            >
                            <TableHead class="pr-5 text-right">
                                <span class="sr-only">Acciones</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="(user, position) in users.data"
                            :key="user.id"
                            class="transition-colors duration-700"
                            :class="[
                                user.is_deleted && 'text-muted-foreground',
                                highlightedId === user.id &&
                                    'bg-primary-soft/70',
                            ]"
                        >
                            <TableCell class="w-full max-w-0 py-2 pl-5">
                                <div class="flex min-w-0 items-center gap-3">
                                    <ListAvatar
                                        :name="user.name"
                                        :user-id="user.id"
                                        :dimmed="user.is_deleted"
                                    />
                                    <div class="min-w-0">
                                        <p
                                            class="flex items-center gap-1.5 font-medium text-foreground"
                                        >
                                            <span class="truncate">{{
                                                user.name
                                            }}</span>
                                            <span
                                                v-if="user.is_current_user"
                                                class="shrink-0 rounded-full bg-primary-soft px-1.5 text-[0.6875rem] font-medium text-primary-soft-foreground"
                                                >tú</span
                                            >
                                            <Tooltip
                                                v-if="user.two_factor_enabled"
                                            >
                                                <TooltipTrigger as-child>
                                                    <ShieldCheck
                                                        class="size-3.5 shrink-0 text-success"
                                                        aria-label="Autenticación en dos pasos activa"
                                                    />
                                                </TooltipTrigger>
                                                <TooltipContent
                                                    >Autenticación en dos pasos
                                                    activa</TooltipContent
                                                >
                                            </Tooltip>
                                        </p>
                                        <p
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ user.email }}
                                        </p>
                                        <div
                                            class="mt-1.5 flex flex-wrap gap-1 sm:hidden"
                                        >
                                            <RoleBadge :role="user.role" />
                                            <DeletedBadge
                                                v-if="user.is_deleted"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell class="hidden sm:table-cell">
                                <RoleBadge :role="user.role" />
                            </TableCell>
                            <TableCell class="hidden xl:table-cell">
                                <div
                                    v-if="user.departments.length > 0"
                                    class="flex flex-wrap gap-1"
                                >
                                    <DepartmentTag
                                        v-for="department in user.departments.slice(
                                            0,
                                            3,
                                        )"
                                        :key="department.id"
                                        :name="department.name"
                                        :color="department.color"
                                        :muted="!department.is_active"
                                    />
                                    <span
                                        v-if="user.departments.length > 3"
                                        class="text-xs text-muted-foreground"
                                        :title="
                                            user.departments
                                                .slice(3)
                                                .map((item) => item.name)
                                                .join(', ')
                                        "
                                    >
                                        +{{ user.departments.length - 3 }}
                                    </span>
                                </div>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <component
                                        :is="scopeIcon('global')"
                                        v-if="user.role?.is_root"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        user.role?.is_root
                                            ? 'Toda la organización'
                                            : 'Sin asignar'
                                    }}
                                </span>
                            </TableCell>
                            <TableCell class="hidden sm:table-cell">
                                <DeletedBadge v-if="user.is_deleted" />
                                <StatusPill
                                    v-else
                                    label="Activo"
                                    tone="success"
                                />
                            </TableCell>
                            <TableCell class="hidden xl:table-cell">
                                <span
                                    v-if="user.last_login_at"
                                    class="text-sm"
                                    :title="formatDateTime(user.last_login_at)"
                                >
                                    {{ formatRelative(user.last_login_at) }}
                                </span>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >Nunca</span
                                >
                            </TableCell>
                            <TableCell class="w-px pr-5 text-right">
                                <RecordActions
                                    class="admin-row-actions"
                                    :data-tour="
                                        position === 0
                                            ? 'users.row-actions'
                                            : undefined
                                    "
                                    :actions="actionsFor(user)"
                                    :record="user.name"
                                    :busy="deletion.processingId === user.id"
                                />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <RecordGrid
                    v-else-if="users.data.length > 0"
                    v-auto-animate="{ duration: 220 }"
                    class="reveal-stagger"
                    :class="loading && 'admin-loading'"
                >
                    <li v-for="(user, position) in users.data" :key="user.id">
                        <RecordCard
                            class="admin-card shadow-float"
                            :muted="user.is_deleted"
                            :highlighted="highlightedId === user.id"
                        >
                            <template #leading>
                                <ListAvatar
                                    :name="user.name"
                                    :user-id="user.id"
                                    :dimmed="user.is_deleted"
                                />
                            </template>
                            <template #title>
                                <span class="flex min-w-0 items-center gap-1.5">
                                    <span class="truncate">{{
                                        user.name
                                    }}</span>
                                    <span
                                        v-if="user.is_current_user"
                                        class="shrink-0 rounded-full bg-primary-soft px-1.5 text-[0.6875rem] font-medium text-primary-soft-foreground"
                                        >tú</span
                                    >
                                    <ShieldCheck
                                        v-if="user.two_factor_enabled"
                                        class="size-3.5 shrink-0 text-success"
                                        aria-label="Autenticación en dos pasos activa"
                                    />
                                </span>
                            </template>
                            <template #subtitle>{{ user.email }}</template>
                            <template #badges>
                                <RoleBadge :role="user.role" />
                                <DeletedBadge v-if="user.is_deleted" />
                                <StatusPill
                                    v-else
                                    label="Activo"
                                    tone="success"
                                />
                            </template>
                            <div
                                v-if="user.departments.length > 0"
                                class="flex flex-wrap gap-1"
                            >
                                <DepartmentTag
                                    v-for="department in user.departments.slice(
                                        0,
                                        3,
                                    )"
                                    :key="department.id"
                                    :name="department.name"
                                    :color="department.color"
                                    :muted="!department.is_active"
                                />
                                <span
                                    v-if="user.departments.length > 3"
                                    class="text-xs text-muted-foreground"
                                >
                                    +{{ user.departments.length - 3 }}
                                </span>
                            </div>
                            <p
                                v-else
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <component
                                    :is="scopeIcon('global')"
                                    v-if="user.role?.is_root"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    user.role?.is_root
                                        ? 'Toda la organización'
                                        : 'Sin departamentos asignados'
                                }}
                            </p>
                            <template #meta>
                                <span
                                    v-if="user.last_login_at"
                                    :title="formatDateTime(user.last_login_at)"
                                    >Último acceso
                                    {{
                                        formatRelative(user.last_login_at)
                                    }}</span
                                >
                                <span v-else>Nunca ha iniciado sesión</span>
                            </template>
                            <template #actions>
                                <RecordActions
                                    class="admin-row-actions"
                                    :data-tour="
                                        position === 0
                                            ? 'users.row-actions'
                                            : undefined
                                    "
                                    :actions="actionsFor(user)"
                                    :record="user.name"
                                    :busy="deletion.processingId === user.id"
                                />
                            </template>
                        </RecordCard>
                    </li>
                </RecordGrid>

                <FilteredEmpty
                    v-else
                    :narrowed="hasFilters"
                    narrowed-title="Ningún usuario coincide con los filtros"
                    narrowed-description="Prueba con otros criterios de búsqueda."
                    :icon="UserX"
                    title="Aún no hay usuarios"
                    description="Crea la primera cuenta de un empleado para comenzar."
                    @clear="clearFilters"
                >
                    <Button
                        v-if="can.create"
                        class="rounded-full"
                        @click="openCreate"
                    >
                        <Plus />
                        Nuevo usuario
                    </Button>
                </FilteredEmpty>
            </div>

            <div
                v-if="users.meta.last_page > 1 || users.data.length > 0"
                class="border-t px-4 py-3"
            >
                <DataPagination :meta="users.meta" noun="usuarios" />
            </div>
        </SurfaceCard>

        <UserFormDialog
            v-model:open="formOpen"
            :user="editingUser"
            :roles="roles"
            :departments="departments"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
