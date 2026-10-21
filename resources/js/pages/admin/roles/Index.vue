<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Eye,
    Globe2,
    Pencil,
    Plus,
    Power,
    PowerOff,
    ShieldCheck,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import RoleFormDialog from '@/components/admin/RoleFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import StatusBadge from '@/components/StatusBadge.vue';
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
import { useRecordHighlight } from '@/composables/useRecordHighlight';
import { useViewMode } from '@/composables/useViewMode';
import { recordActions } from '@/lib/recordActions';
import {
    destroy as destroyRole,
    index as rolesIndex,
} from '@/routes/admin/roles';
import { update as updateStatus } from '@/routes/admin/roles/status';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    ManagedRole,
    PermissionGroupCatalog,
    RecordAction,
    ScopeOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles y permisos', href: rolesIndex() }],
    },
});

const props = defineProps<{
    roles: ManagedRole[];
    catalog: PermissionGroupCatalog[];
    scopes: ScopeOption[];
    grantablePermissions: string[];
    rootCapabilities: string[];
    can: { create: boolean };
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

type PendingAction = { type: 'deactivate' | 'delete'; role: ManagedRole };

const pendingAction = ref<PendingAction | null>(null);
const confirmOpen = ref(false);
const processingId = ref<number | null>(null);

function askConfirmation(action: PendingAction): void {
    pendingAction.value = action;
    confirmOpen.value = true;
}

const { mode: viewMode } = useViewMode('admin.roles');

/**
 * A role can only be deleted while nobody has it, so the button is offered
 * only then (the server rejects it otherwise).
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
        role.can.changeStatus &&
            !role.is_active && {
                key: 'activate',
                label: 'Activar',
                icon: Power,
                onSelect: () => activate(role),
            },
        role.can.changeStatus &&
            role.is_active && {
                key: 'deactivate',
                label: 'Desactivar',
                icon: PowerOff,
                onSelect: () => askConfirmation({ type: 'deactivate', role }),
            },
        role.can.delete &&
            role.users_count === 0 && {
                key: 'delete',
                label: 'Eliminar',
                icon: Trash2,
                destructive: true,
                secondary: true,
                onSelect: () => askConfirmation({ type: 'delete', role }),
            },
    );
}

function usersLabel(role: ManagedRole): string {
    return `${role.users_count} ${role.users_count === 1 ? 'usuario' : 'usuarios'}`;
}

function showFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function activate(role: ManagedRole): void {
    router.visit(updateStatus(role.id), {
        data: { is_active: true },
        preserveScroll: true,
        onStart: () => (processingId.value = role.id),
        onFinish: () => (processingId.value = null),
        onError: showFirstError,
    });
}

function confirmPendingAction(): void {
    const action = pendingAction.value;

    if (!action) {
        return;
    }

    const options = {
        preserveScroll: true,
        onStart: () => (processingId.value = action.role.id),
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (processingId.value = null),
        onError: (errors: Record<string, string>) => {
            confirmOpen.value = false;
            showFirstError(errors);
        },
    };

    if (action.type === 'deactivate') {
        router.visit(updateStatus(action.role.id), {
            ...options,
            data: { is_active: false },
        });

        return;
    }

    router.visit(destroyRole(action.role.id), options);
}

const confirmCopy = computed(() => {
    const action = pendingAction.value;
    const users = action?.role.users_count ?? 0;

    if (action?.type === 'delete') {
        return {
            title: `¿Eliminar el rol ${action.role.label}?`,
            description:
                'El rol no tiene usuarios asignados. Esta acción no se puede deshacer.',
            confirmLabel: 'Eliminar rol',
        };
    }

    return {
        title: `¿Desactivar el rol ${action?.role.label ?? ''}?`,
        description:
            users > 0
                ? `${users} ${users === 1 ? 'usuario perderá' : 'usuarios perderán'} todos los permisos de este rol en su siguiente acción. Conservarán su cuenta e historial.`
                : 'No podrá asignarse a nuevos usuarios mientras esté desactivado.',
        confirmLabel: 'Desactivar rol',
    };
});
</script>

<template>
    <Head title="Roles y permisos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Roles y permisos"
            description="Define qué puede hacer cada persona (permisos) y sobre qué datos (alcance). Los cambios se aplican de inmediato."
        >
            <template v-if="can.create" #actions>
                <Button @click="openCreate">
                    <Plus />
                    Nuevo rol
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard v-if="roles.length > 0">
            <div
                class="flex items-center justify-between gap-2 border-b px-4 py-3"
            >
                <p class="text-sm text-muted-foreground">
                    {{ roles.length }}
                    {{ roles.length === 1 ? 'rol' : 'roles' }} ·
                    {{ availableTotal }} permisos disponibles
                </p>
                <ViewModeToggle v-model="viewMode" />
            </div>

            <Table v-if="viewMode === 'table'">
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
                            !role.is_active && 'text-muted-foreground',
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
                                                ? 'Autoridad administrativa'
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
                            <StatusBadge :active="role.is_active" />
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(role)"
                                :record="`el rol ${role.label}`"
                                :busy="processingId === role.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else>
                <li v-for="role in roles" :key="role.id">
                    <RecordCard
                        :muted="!role.is_active"
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
                            <StatusBadge :active="role.is_active" />
                            <Badge
                                v-if="role.is_root"
                                variant="secondary"
                                class="font-normal"
                                >Autoridad administrativa</Badge
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
                                :busy="processingId === role.id"
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
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.description"
            :confirm-label="confirmCopy.confirmLabel"
            destructive
            :processing="processingId !== null"
            @confirm="confirmPendingAction"
        />
    </div>
</template>
