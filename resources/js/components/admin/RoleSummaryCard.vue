<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ListChecks, Pencil, ShieldCheck, ShieldEllipsis } from '@lucide/vue';
import { computed } from 'vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    areaCountLabel,
    permissionCountLabel,
    scopeIcon,
    usersCountLabel,
} from '@/lib/roles';
import type { RolePermissionSummary } from '@/lib/roles';
import { index as usersIndex } from '@/routes/admin/users';
import type { ManagedRole, RecordAction } from '@/types';

/**
 * Executive card of a role for the «Tarjetas» view: icon, name, short
 * description, scope, holders and a permission summary (how many permissions
 * and areas), with «Ver permisos» for the full detail and «Editar» when the
 * viewer may change it. No per-module chips: the detail lives in the sheet.
 */
const props = defineProps<{
    role: ManagedRole;
    summary: RolePermissionSummary;
    actions: RecordAction[];
    highlighted?: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{ viewPermissions: []; edit: [] }>();

const roleIcon = computed(() =>
    props.role.is_root ? ShieldEllipsis : ShieldCheck,
);

const description = computed(() =>
    props.role.is_root
        ? 'Superadministrador: control total de la plataforma; no se puede eliminar.'
        : props.role.description || 'Sin descripción.',
);
</script>

<template>
    <RecordCard
        :muted="role.is_deleted"
        :highlighted="highlighted"
        class="gap-4"
    >
        <template #leading>
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                aria-hidden="true"
            >
                <component :is="roleIcon" class="size-5" />
            </span>
        </template>
        <template #title>
            <h3 class="truncate text-base font-semibold">{{ role.label }}</h3>
        </template>
        <template #summary>
            <span class="line-clamp-2">{{ description }}</span>
        </template>
        <template
            v-if="
                role.is_deleted ||
                role.is_current_user_role ||
                role.users_count === 0
            "
            #badges
        >
            <DeletedBadge v-if="role.is_deleted" />
            <Badge
                v-if="role.is_current_user_role"
                variant="outline"
                class="font-normal"
                >Tu rol</Badge
            >
            <Badge
                v-if="role.users_count === 0 && !role.is_deleted"
                variant="warning"
                class="font-normal"
                >Sin usuarios</Badge
            >
        </template>

        <dl
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 border-t pt-3"
        >
            <dt class="text-xs font-medium text-muted-foreground">Alcance</dt>
            <dd class="flex min-w-0 items-center gap-1.5 text-sm">
                <component
                    :is="scopeIcon(role.scope)"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="min-w-0 truncate">{{ role.scope_label }}</span>
            </dd>
            <dt class="text-xs font-medium text-muted-foreground">Usuarios</dt>
            <dd class="min-w-0 text-sm">
                <Link
                    :href="usersIndex({ query: { role: role.id } })"
                    class="rounded-sm tabular-nums underline-offset-4 hover:underline"
                    :aria-label="`Ver los ${usersCountLabel(role.users_count)} con el rol ${role.label}`"
                >
                    {{ usersCountLabel(role.users_count) }}
                </Link>
            </dd>
            <dt class="text-xs font-medium text-muted-foreground">Permisos</dt>
            <dd class="min-w-0 text-sm text-pretty tabular-nums">
                {{ permissionCountLabel(summary.enabled) }}
                <span class="text-muted-foreground">·</span>
                {{ areaCountLabel(summary.areas) }}
            </dd>
        </dl>

        <template #meta>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-tour="roles.view-permissions"
                    @click="emit('viewPermissions')"
                >
                    <ListChecks aria-hidden="true" />
                    Ver permisos
                </Button>
                <Button
                    v-if="role.can.update && !role.is_deleted"
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-label="`Editar el rol ${role.label}`"
                    @click="emit('edit')"
                >
                    <Pencil aria-hidden="true" />
                    Editar
                </Button>
            </div>
        </template>
        <template #actions>
            <RecordActions
                :actions="actions"
                :record="`el rol ${role.label}`"
                :busy="busy"
            />
        </template>
    </RecordCard>
</template>
