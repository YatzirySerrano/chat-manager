<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Eye,
    Globe2,
    MoreHorizontal,
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
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useRecordHighlight } from '@/composables/useRecordHighlight';
import {
    destroy as destroyRole,
    index as rolesIndex,
} from '@/routes/admin/roles';
import { update as updateStatus } from '@/routes/admin/roles/status';
import { index as usersIndex } from '@/routes/admin/users';
import type { ManagedRole, PermissionGroupCatalog, ScopeOption } from '@/types';

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

function openRole(role: ManagedRole): void {
    editingRole.value = role;
    formReadonly.value = !role.can.update;
    formOpen.value = true;
}

type PendingAction = { type: 'deactivate' | 'delete'; role: ManagedRole };

const pendingAction = ref<PendingAction | null>(null);
const confirmOpen = ref(false);
const processingId = ref<number | null>(null);

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

        <ul
            v-if="roles.length > 0"
            class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="role in roles"
                :key="role.id"
                class="flex flex-col rounded-xl border bg-card shadow-card transition-all duration-700"
                :class="[
                    !role.is_active && 'opacity-75',
                    highlightedId === role.id && 'ring-2 ring-primary',
                ]"
            >
                <div class="flex items-start gap-3 p-5 pb-4">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                        :class="
                            role.is_root
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-primary-soft text-primary-soft-foreground'
                        "
                    >
                        <ShieldCheck class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex flex-wrap items-center gap-2 font-semibold"
                        >
                            <span class="truncate">{{ role.label }}</span>
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
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <StatusBadge
                                :active="role.is_active"
                                active-label="Activo"
                                inactive-label="Inactivo"
                            />
                            <span
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <Globe2 class="size-3" /> {{ role.scope_label }}
                            </span>
                        </div>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :disabled="processingId === role.id"
                                :aria-label="`Acciones para el rol ${role.label}`"
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-52">
                            <DropdownMenuItem @select="openRole(role)">
                                <component
                                    :is="role.can.update ? Pencil : Eye"
                                />
                                {{
                                    role.can.update
                                        ? 'Editar rol y permisos'
                                        : 'Ver permisos'
                                }}
                            </DropdownMenuItem>
                            <template v-if="role.can.changeStatus">
                                <DropdownMenuItem
                                    v-if="!role.is_active"
                                    @select="activate(role)"
                                >
                                    <Power />
                                    Activar
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-else
                                    @select="
                                        pendingAction = {
                                            type: 'deactivate',
                                            role,
                                        };
                                        confirmOpen = true;
                                    "
                                >
                                    <PowerOff />
                                    Desactivar
                                </DropdownMenuItem>
                            </template>
                            <template v-if="role.can.delete">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    :disabled="role.users_count > 0"
                                    @select="
                                        pendingAction = {
                                            type: 'delete',
                                            role,
                                        };
                                        confirmOpen = true;
                                    "
                                >
                                    <Trash2 />
                                    {{
                                        role.users_count > 0
                                            ? 'Eliminar (tiene usuarios)'
                                            : 'Eliminar'
                                    }}
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <p
                    class="line-clamp-2 min-h-10 px-5 text-sm text-muted-foreground"
                >
                    {{ role.description || 'Sin descripción.' }}
                </p>

                <div class="space-y-2 px-5 pt-4">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium">Permisos disponibles</span>
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
                    <ul class="flex flex-wrap gap-1.5 pt-1">
                        <li
                            v-for="group in groupSummary(role)"
                            :key="group.label"
                            class="rounded-md bg-secondary px-2 py-0.5 text-xs text-secondary-foreground"
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

                <div
                    class="mt-auto flex items-center justify-between gap-2 border-t px-5 py-3"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Link
                                :href="usersIndex({ query: { role: role.id } })"
                                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            >
                                <Users class="size-4" />
                                {{ role.users_count }}
                                {{
                                    role.users_count === 1
                                        ? 'usuario'
                                        : 'usuarios'
                                }}
                            </Link>
                        </TooltipTrigger>
                        <TooltipContent
                            >Ver los usuarios con este rol</TooltipContent
                        >
                    </Tooltip>
                    <Button variant="outline" size="sm" @click="openRole(role)">
                        {{ role.can.update ? 'Editar' : 'Ver permisos' }}
                    </Button>
                </div>
            </li>
        </ul>

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
