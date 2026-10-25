<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, Globe2, Pencil, Plus, ShieldCheck, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import RoleFormDialog from '@/components/admin/RoleFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SurfaceCard from '@/components/SurfaceCard.vue';
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
import { useRecordHighlight } from '@/composables/useRecordHighlight';
import { useRecordStateFilter } from '@/composables/useRecordStateFilter';
import { useViewMode } from '@/composables/useViewMode';
import { recordActions } from '@/lib/recordActions';
import {
    destroy as destroyRole,
    index as rolesIndex,
    restore as restoreRole,
} from '@/routes/admin/roles';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    ManagedRole,
    PermissionGroupCatalog,
    RecordAction,
    RecordStateOption,
    RecordStateValue,
    ScopeOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles y permisos', href: rolesIndex() }],
    },
});

const props = defineProps<{
    roles: ManagedRole[];
    filters: { record: RecordStateValue };
    recordStates: RecordStateOption[];
    catalog: PermissionGroupCatalog[];
    scopes: ScopeOption[];
    grantablePermissions: string[];
    rootCapabilities: string[];
    can: { create: boolean };
    canExport?: boolean;
}>();

const { highlightedId } = useRecordHighlight(() =>
    props.roles.map((role) => role.id),
);

const availableByGroup = computed(() =>
    props.catalog
        .map((group) => ({
            ...group,
            available: group.permissions.filter(
                (permission) => permission.available,
            ),
        }))
        .filter((group) => group.available.length > 0),
);

const availableTotal = computed(() =>
    availableByGroup.value.reduce(
        (total, group) => total + group.available.length,
        0,
    ),
);

function groupSummary(
    role: ManagedRole,
): { label: string; count: number; total: number }[] {
    return availableByGroup.value
        .map((group) => ({
            label: group.label,
            count: group.available.filter((permission) =>
                role.permissions.includes(permission.value),
            ).length,
            total: group.available.length,
        }))
        .filter((group) => group.count > 0);
}

function availableCount(role: ManagedRole): number {
    return availableByGroup.value
        .flatMap((group) => group.available)
        .filter((permission) => role.permissions.includes(permission.value))
        .length;
}

const formOpen = ref(false);
const formReadonly = ref(false);
const editingRole = ref<ManagedRole | null>(null);

function openCreate(): void {
    editingRole.value = null;
    formReadonly.value = false;
    formOpen.value = true;
}

function openRole(role: ManagedRole, readonly: boolean): void {
    editingRole.value = role;
    formReadonly.value = readonly || !role.can.update;
    formOpen.value = true;
}

const { record, apply: applyRecordState } = useRecordStateFilter(
    rolesIndex,
    props.filters.record,
    ['roles', 'filters'],
);

const deletion = useRecordDeletion<ManagedRole>({
    destroy: (role) => destroyRole(role.id),
    restore: (role) => restoreRole(role.id),
    copy: (role, step) =>
        step === 'delete'
            ? {
                  title: `¿Eliminar el rol ${role.label}?`,
                  description:
                      'El rol dejará de otorgar permisos y ya no podrá asignarse. Solo puede eliminarse si nadie lo tiene asignado (incluidos usuarios eliminados). Podrás restaurarlo.',
              }
            : {
                  title: `¿Restaurar el rol ${role.label}?`,
                  description:
                      'Volverá a otorgar sus permisos y podrá asignarse de nuevo.',
              },
});

const { mode: viewMode } = useViewMode('admin.roles');

/**
 * A role can only be deleted while nobody has it (deleted users included);
 * the server explains it when someone still holds it.
 */
function actionsFor(role: ManagedRole): RecordAction[] {
    return recordActions(
        {
            key: 'view',
            label: 'Ver permisos',
            icon: Eye,
            onSelect: () => openRole(role, true),
        },
        role.can.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openRole(role, false),
        },
        ...deletion.actionsFor(role),
    );
}

function usersLabel(role: ManagedRole): string {
    return `${role.users_count} ${role.users_count === 1 ? 'usuario' : 'usuarios'}`;
}
</script>

<template>
    <Head title="Roles y permisos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            help="roles"
            title="Roles y permisos"
            description="Define qué puede hacer cada persona (permisos) y sobre qué datos (alcance). Los cambios se aplican de inmediato."
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="roles"
                    :filters="filters"
                />
                <Button v-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo rol
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard v-if="roles.length > 0 || record !== 'active'">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
            >
                <p class="text-sm text-muted-foreground">
                    {{ roles.length }}
                    {{ roles.length === 1 ? 'rol' : 'roles' }} ·
                    {{ availableTotal }} permisos disponibles
                </p>
                <div class="flex items-center gap-2">
                    <RecordStateFilter
                        v-model="record"
                        :options="recordStates"
                        @change="applyRecordState"
                    />
                    <ViewModeToggle v-model="viewMode" />
                </div>
            </div>

            <EmptyState
                v-if="roles.length === 0"
                class="m-4 border-none"
                :icon="ShieldCheck"
                title="Ningún rol en este estado"
                description="Prueba con otro estado del registro."
            />

            <Table v-else-if="viewMode === 'table'">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Rol</TableHead>
                        <TableHead class="hidden lg:table-cell"
                            >Alcance</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Permisos</TableHead
                        >
                        <TableHead class="hidden xl:table-cell"
                            >Usuarios</TableHead
                        >
                        <TableHead>Estado</TableHead>
                        <TableHead class="pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="role in roles"
                        :key="role.id"
                        class="transition-colors duration-700"
                        :class="[
                            role.is_deleted && 'text-muted-foreground',
                            highlightedId === role.id && 'bg-primary-soft/70',
                        ]"
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                    :class="
                                        role.is_root
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-primary-soft text-primary-soft-foreground'
                                    "
                                >
                                    <ShieldCheck
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="flex flex-wrap items-center gap-1.5 font-medium text-foreground"
                                    >
                                        <span class="truncate">{{
                                            role.label
                                        }}</span>
                                        <Badge
                                            v-if="role.is_current_user_role"
                                            variant="outline"
                                            class="font-normal"
                                            >Tu rol</Badge
                                        >
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            role.is_root
                                                ? 'Superadministrador: autoridad raíz de la plataforma'
                                                : role.description ||
                                                  'Sin descripción.'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            {{ role.scope_label }}
                        </TableCell>
                        <TableCell
                            class="hidden text-sm tabular-nums lg:table-cell"
                        >
                            {{ availableCount(role) }} de
                            {{ availableTotal }}
                        </TableCell>
                        <TableCell class="hidden xl:table-cell">
                            <Link
                                :href="usersIndex({ query: { role: role.id } })"
                                class="text-sm hover:underline"
                                :aria-label="`Ver los ${usersLabel(role)} con el rol ${role.label}`"
                            >
                                {{ usersLabel(role) }}
                            </Link>
                        </TableCell>
                        <TableCell>
                            <DeletedBadge v-if="role.is_deleted" />
                            <span v-else class="text-xs text-muted-foreground"
                                >Activo</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(role)"
                                :record="`el rol ${role.label}`"
                                :busy="deletion.processingId === role.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else>
                <li v-for="role in roles" :key="role.id">
                    <RecordCard
                        :muted="role.is_deleted"
                        :highlighted="highlightedId === role.id"
                    >
                        <template #leading>
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                :class="
                                    role.is_root
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-primary-soft text-primary-soft-foreground'
                                "
                            >
                                <ShieldCheck
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                        </template>
                        <template #title>
                            <span class="block truncate">{{ role.label }}</span>
                        </template>
                        <template #subtitle>
                            <span class="inline-flex items-center gap-1">
                                <Globe2 class="size-3" aria-hidden="true" />
                                {{ role.scope_label }}
                            </span>
                        </template>
                        <template #badges>
                            <DeletedBadge v-if="role.is_deleted" />
                            <Badge
                                v-if="role.is_root"
                                variant="secondary"
                                class="font-normal"
                                >Superadministrador</Badge
                            >
                            <Badge
                                v-if="role.is_current_user_role"
                                variant="outline"
                                class="font-normal"
                                >Tu rol</Badge
                            >
                        </template>
                        <p class="line-clamp-2 text-muted-foreground">
                            {{ role.description || 'Sin descripción.' }}
                        </p>
                        <div class="space-y-1.5">
                            <div
                                class="flex items-center justify-between text-xs"
                            >
                                <span class="font-medium"
                                    >Permisos disponibles</span
                                >
                                <span class="text-muted-foreground tabular-nums"
                                    >{{ availableCount(role) }} de
                                    {{ availableTotal }}</span
                                >
                            </div>
                            <div
                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                aria-hidden="true"
                            >
                                <div
                                    class="h-full rounded-full bg-primary transition-all"
                                    :style="{
                                        width: `${availableTotal ? (availableCount(role) / availableTotal) * 100 : 0}%`,
                                    }"
                                />
                            </div>
                            <ul class="flex flex-wrap gap-1 pt-0.5">
                                <li
                                    v-for="group in groupSummary(role)"
                                    :key="group.label"
                                    class="rounded-md bg-secondary px-1.5 py-0.5 text-xs text-secondary-foreground"
                                >
                                    {{ group.label }} · {{ group.count }}/{{
                                        group.total
                                    }}
                                </li>
                                <li
                                    v-if="groupSummary(role).length === 0"
                                    class="text-xs text-muted-foreground"
                                >
                                    Sin permisos disponibles asignados.
                                </li>
                            </ul>
                        </div>
                        <template #meta>
                            <Link
                                :href="usersIndex({ query: { role: role.id } })"
                                class="inline-flex items-center gap-1 hover:text-foreground hover:underline"
                                :aria-label="`Ver los ${usersLabel(role)} con el rol ${role.label}`"
                            >
                                <Users class="size-3.5" aria-hidden="true" />
                                {{ usersLabel(role) }}
                            </Link>
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(role)"
                                :record="`el rol ${role.label}`"
                                :busy="deletion.processingId === role.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>
        </SurfaceCard>

        <EmptyState
            v-else
            :icon="ShieldCheck"
            title="Aún no hay roles"
            description="Ejecuta php artisan app:sync-permissions para crear los roles iniciales."
        />

        <RoleFormDialog
            v-model:open="formOpen"
            :role="editingRole"
            :catalog="catalog"
            :scopes="scopes"
            :grantable-permissions="grantablePermissions"
            :root-capabilities="rootCapabilities"
            :readonly="formReadonly"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
