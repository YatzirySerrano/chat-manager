<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Pencil,
    Plus,
    Search,
    SearchCheck,
    ShieldCheck,
    Trash2,
    UserCheck,
    UserX,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import UserFormDialog from '@/components/admin/UserFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import RoleBadge from '@/components/RoleBadge.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { getInitials } from '@/composables/useInitials';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { useRecordHighlight } from '@/composables/useRecordHighlight';
import { useViewMode } from '@/composables/useViewMode';
import { formatDateTime, formatRelative } from '@/lib/format';
import { recordActions } from '@/lib/recordActions';
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

    // Inertia cancels the previous pending visit, so typing fast never lets an
    // older response overwrite the latest results.
    router.get(usersIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['users', 'filters', 'stats'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

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
    filterState.search = missing.value.email;
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
                      'El usuario perderá el acceso de inmediato y sus conversaciones abiertas regresarán a la cola de su departamento. Su historial se conserva y podrás restaurarlo.',
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

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
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
                <Button v-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo usuario
                </Button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <StatCard
                label="Activos"
                :value="stats.active"
                :icon="UserCheck"
                hint="Dentro de tu alcance; pueden iniciar sesión"
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
            class="flex flex-col gap-3 rounded-xl border border-primary/25 bg-primary-soft px-4 py-3 text-sm text-primary-soft-foreground sm:flex-row sm:items-center"
            role="status"
        >
            <SearchCheck class="size-4 shrink-0" />
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

        <SurfaceCard>
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
                        placeholder="Buscar por nombre o correo"
                        class="pl-9"
                        aria-label="Buscar usuarios"
                    />
                </div>
                <ViewModeToggle v-model="viewMode" class="order-2" />
                <div
                    class="order-3 grid w-full grid-cols-2 gap-2 sm:grid-cols-4 lg:flex lg:flex-wrap"
                >
                    <Select
                        v-model="filterState.role"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-40"
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
                    <RecordStateFilter
                        v-model="filterState.record"
                        :options="recordStates"
                        @change="applyFilters"
                    />
                    <Select
                        v-model="filterState.sort"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-40"
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
                    <Button
                        v-if="hasFilters"
                        variant="ghost"
                        class="col-span-2 sm:col-span-1"
                        @click="clearFilters"
                    >
                        <X />
                        Limpiar
                    </Button>
                </div>
            </div>

            <Table v-if="users.data.length > 0 && viewMode === 'table'">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Usuario</TableHead>
                        <TableHead class="hidden sm:table-cell">Rol</TableHead>
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
                        v-for="user in users.data"
                        :key="user.id"
                        class="transition-colors duration-700"
                        :class="[
                            user.is_deleted && 'text-muted-foreground',
                            highlightedId === user.id && 'bg-primary-soft/70',
                        ]"
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <Avatar class="size-9 rounded-lg">
                                    <AvatarFallback
                                        class="rounded-lg bg-primary-soft text-xs font-semibold text-primary-soft-foreground"
                                    >
                                        {{ getInitials(user.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <p
                                        class="flex items-center gap-1.5 truncate font-medium text-foreground"
                                    >
                                        {{ user.name }}
                                        <span
                                            v-if="user.is_current_user"
                                            class="text-xs font-normal text-muted-foreground"
                                            >(tú)</span
                                        >
                                        <Tooltip v-if="user.two_factor_enabled">
                                            <TooltipTrigger as-child>
                                                <ShieldCheck
                                                    class="size-3.5 text-success"
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
                                    <DeletedBadge
                                        v-if="user.is_deleted"
                                        class="mt-1 sm:hidden"
                                    />
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
                                >
                                    +{{ user.departments.length - 3 }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-muted-foreground">
                                {{
                                    user.role?.is_root
                                        ? 'Toda la organización'
                                        : 'Sin asignar'
                                }}
                            </span>
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <DeletedBadge v-if="user.is_deleted" />
                            <span v-else class="text-xs text-muted-foreground"
                                >Activo</span
                            >
                        </TableCell>
                        <TableCell class="hidden xl:table-cell">
                            <span
                                v-if="user.last_login_at"
                                class="text-sm"
                                :title="formatDateTime(user.last_login_at)"
                            >
                                {{ formatRelative(user.last_login_at) }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >Nunca</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(user)"
                                :record="user.name"
                                :busy="deletion.processingId === user.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else-if="users.data.length > 0">
                <li v-for="user in users.data" :key="user.id">
                    <RecordCard
                        :muted="user.is_deleted"
                        :highlighted="highlightedId === user.id"
                    >
                        <template #leading>
                            <Avatar class="size-9 rounded-lg">
                                <AvatarFallback
                                    class="rounded-lg bg-primary-soft text-xs font-semibold text-primary-soft-foreground"
                                >
                                    {{ getInitials(user.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </template>
                        <template #title>
                            <span class="flex min-w-0 items-center gap-1.5">
                                <span class="truncate">{{ user.name }}</span>
                                <span
                                    v-if="user.is_current_user"
                                    class="shrink-0 text-xs font-normal text-muted-foreground"
                                    >(tú)</span
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
                        <p v-else class="text-xs text-muted-foreground">
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
                                {{ formatRelative(user.last_login_at) }}</span
                            >
                            <span v-else>Nunca ha iniciado sesión</span>
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(user)"
                                :record="user.name"
                                :busy="deletion.processingId === user.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>

            <EmptyState
                v-else
                class="m-4 border-none"
                :icon="UserX"
                :title="
                    hasFilters
                        ? 'Ningún usuario coincide con los filtros'
                        : 'Aún no hay usuarios'
                "
                :description="
                    hasFilters
                        ? 'Prueba con otros criterios de búsqueda.'
                        : 'Crea la primera cuenta de un empleado para comenzar.'
                "
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    @click="clearFilters"
                    >Limpiar filtros</Button
                >
                <Button v-else-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo usuario
                </Button>
            </EmptyState>

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
