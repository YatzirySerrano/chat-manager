<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, Pencil, Plus, ShieldCheck, ShieldEllipsis } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import RoleFormDialog from '@/components/admin/RoleFormDialog.vue';
import RolePermissionsSheet from '@/components/admin/RolePermissionsSheet.vue';
import RoleSummaryCard from '@/components/admin/RoleSummaryCard.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
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
    areaCountLabel,
    scopeIcon,
    summarizeRole,
    usersCountLabel,
} from '@/lib/roles';
import type { RolePermissionSummary } from '@/lib/roles';
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

const summaries = computed(
    () =>
        new Map<number, RolePermissionSummary>(
            props.roles.map((role) => [
                role.id,
                summarizeRole(role, props.catalog),
            ]),
        ),
);

function summaryOf(role: ManagedRole): RolePermissionSummary {
    return summaries.value.get(role.id) ?? summarizeRole(role, props.catalog);
}

const availableTotal = computed(
    () =>
        props.catalog
            .flatMap((group) => group.permissions)
            .filter((permission) => permission.available).length,
);

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

const sheetOpen = ref(false);
const viewedRole = ref<ManagedRole | null>(null);

function viewPermissions(role: ManagedRole): void {
    viewedRole.value = role;
    sheetOpen.value = true;
}

/** «Editar rol» from the detail: the sheet closes before the editor opens. */
function editFromSheet(role: ManagedRole): void {
    sheetOpen.value = false;
    void nextTick(() => openRole(role, false));
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
            label: 'Consultar',
            icon: Eye,
            onSelect: () => viewPermissions(role),
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
    return usersCountLabel(role.users_count);
}

function roleDescription(role: ManagedRole): string {
    return role.is_root
        ? 'Superadministrador: control total de la plataforma; no se puede eliminar.'
        : role.description || 'Sin descripción.';
}
</script>

<template>
    <Head title="Roles y permisos" />

    <div class="page-shell">
        <PageHeader
            help="roles"
            title="Roles y permisos"
            description="Define qué puede hacer cada persona (permisos) y qué información puede ver (alcance): toda la organización, sus departamentos o solo lo asignado. Los cambios se aplican de inmediato."
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="roles"
                    :filters="filters"
                />
                <Button
                    v-if="can.create"
                    data-tour="roles.new"
                    @click="openCreate"
                >
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
                <div class="flex items-center gap-2" data-tour="roles.toolbar">
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
                description="Cambia el filtro (Activos, Eliminados o Todos)."
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
                        v-for="(role, position) in roles"
                        :key="role.id"
                        :data-tour="position === 0 ? 'roles.card' : undefined"
                        class="transition-colors duration-(--duration-panel)"
                        :class="[
                            role.is_deleted && 'text-muted-foreground',
                            highlightedId === role.id && 'bg-primary-soft/70',
                        ]"
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                                    aria-hidden="true"
                                >
                                    <component
                                        :is="
                                            role.is_root
                                                ? ShieldEllipsis
                                                : ShieldCheck
                                        "
                                        class="size-4"
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
                                        {{ roleDescription(role) }}
                                    </p>
                                    <p
                                        class="flex flex-wrap items-center gap-x-2 gap-y-0.5 pt-0.5 text-xs text-muted-foreground lg:hidden"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            <component
                                                :is="scopeIcon(role.scope)"
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ role.scope_label }}
                                        </span>
                                        <span class="tabular-nums"
                                            >{{ summaryOf(role).enabled }}
                                            habilitados ·
                                            {{ summaryOf(role).areas }}
                                            {{
                                                summaryOf(role).areas === 1
                                                    ? 'área'
                                                    : 'áreas'
                                            }}</span
                                        >
                                    </p>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            <span
                                class="inline-flex items-center gap-1.5 whitespace-nowrap"
                            >
                                <component
                                    :is="scopeIcon(role.scope)"
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                {{ role.scope_label }}
                            </span>
                        </TableCell>
                        <TableCell
                            class="hidden text-sm whitespace-nowrap tabular-nums lg:table-cell"
                            :title="areaCountLabel(summaryOf(role).areas)"
                        >
                            {{ summaryOf(role).enabled }} habilitados ·
                            {{ summaryOf(role).areas }}
                            {{ summaryOf(role).areas === 1 ? 'área' : 'áreas' }}
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
                <li
                    v-for="(role, position) in roles"
                    :key="role.id"
                    :data-tour="position === 0 ? 'roles.card' : undefined"
                >
                    <RoleSummaryCard
                        :role="role"
                        :summary="summaryOf(role)"
                        :actions="deletion.actionsFor(role)"
                        :highlighted="highlightedId === role.id"
                        :busy="deletion.processingId === role.id"
                        @view-permissions="viewPermissions(role)"
                        @edit="openRole(role, false)"
                    />
                </li>
            </RecordGrid>
        </SurfaceCard>

        <EmptyState
            v-else
            :icon="ShieldCheck"
            title="Aún no hay roles"
            description="Aún no se han creado los roles básicos. Pide al equipo técnico que los cree; después podrás crearlos y editarlos aquí."
        />

        <RolePermissionsSheet
            v-model:open="sheetOpen"
            :role="viewedRole"
            :catalog="catalog"
            :scopes="scopes"
            :can-edit="viewedRole?.can.update ?? false"
            @edit="editFromSheet"
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
