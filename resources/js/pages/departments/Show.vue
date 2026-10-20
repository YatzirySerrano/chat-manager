<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    Power,
    PowerOff,
    UserMinus,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import AddMemberDialog from '@/components/admin/AddMemberDialog.vue';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DepartmentConnectionsCard from '@/components/departments/DepartmentConnectionsCard.vue';
import DepartmentHoursCard from '@/components/departments/DepartmentHoursCard.vue';
import DepartmentMetricsCard from '@/components/departments/DepartmentMetricsCard.vue';
import DepartmentQuickRepliesCard from '@/components/departments/DepartmentQuickRepliesCard.vue';
import DepartmentWelcomeMessageCard from '@/components/departments/DepartmentWelcomeMessageCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import RoleBadge from '@/components/RoleBadge.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getInitials } from '@/composables/useInitials';
import { formatDateTime, formatRelative } from '@/lib/format';
import { swatchClasses } from '@/lib/swatches';
import { index as departmentsIndex, show } from '@/routes/departments';
import { destroy as destroyMember } from '@/routes/departments/members';
import { update as updateStatus } from '@/routes/departments/status';
import type {
    ColorSwatch,
    DepartmentConnection,
    DepartmentMember,
    DepartmentMetrics,
    DepartmentQuickReplies,
    DepartmentSummary,
    MemberCandidate,
    PlatformSettings,
    Weekday,
} from '@/types';

const props = defineProps<{
    department: DepartmentSummary;
    members: DepartmentMember[];
    removableMemberIds: number[];
    candidates: MemberCandidate[];
    colors: ColorSwatch[];
    metrics: DepartmentMetrics;
    platformHours: PlatformSettings['business_hours'];
    weekdays: Weekday[];
    connections: DepartmentConnection[];
    /** Null when the viewer cannot use quick replies. */
    quickReplies: DepartmentQuickReplies | null;
    links: { connections: string | null; quickReplies: string | null };
    can: { update: boolean; changeStatus: boolean; manageMembers: boolean };
}>();

defineOptions({
    layout: (pageProps: { department: DepartmentSummary }) => ({
        breadcrumbs: [
            { title: 'Departamentos', href: departmentsIndex() },
            {
                title: pageProps.department.name,
                href: show(pageProps.department.id),
            },
        ],
    }),
});

const editOpen = ref(false);
const addOpen = ref(false);

/**
 * Members grouped by their role; roles are managed from the interface, so
 * the groups follow whatever roles exist instead of fixed names.
 */
const memberGroups = computed(() => {
    const groups = new Map<
        string,
        { key: string; title: string; members: DepartmentMember[] }
    >();

    for (const member of props.members) {
        const key = member.role ? String(member.role.id) : 'none';
        const group = groups.get(key) ?? {
            key,
            title: member.role?.label ?? 'Sin rol',
            members: [],
        };

        group.members.push(member);
        groups.set(key, group);
    }

    return [...groups.values()].sort((a, b) =>
        a.title.localeCompare(b.title, 'es'),
    );
});

type PendingAction =
    | { type: 'remove'; member: DepartmentMember }
    | { type: 'deactivate' };

const pendingAction = ref<PendingAction | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function showFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function askConfirmation(action: PendingAction): void {
    pendingAction.value = action;
    confirmOpen.value = true;
}

function activate(): void {
    router.visit(updateStatus(props.department.id), {
        data: { is_active: true },
        preserveScroll: true,
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
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
        },
        onError: showFirstError,
    };

    if (action.type === 'deactivate') {
        router.visit(updateStatus(props.department.id), {
            ...options,
            data: { is_active: false },
        });

        return;
    }

    router.visit(
        destroyMember({
            department: props.department.id,
            member: action.member.id,
        }),
        options,
    );
}

const confirmCopy = computed(() => {
    const action = pendingAction.value;

    if (action?.type === 'remove') {
        return {
            title: `¿Retirar a ${action.member.name}?`,
            description:
                'Dejará de ver las conversaciones del departamento y sus conversaciones abiertas en él regresarán a la cola.',
            confirmLabel: 'Retirar',
        };
    }

    return {
        title: `¿Desactivar ${props.department.name}?`,
        description:
            'No se podrán agregar miembros ni recibir nuevas conversaciones. Solo es posible si no tiene conversaciones abiertas.',
        confirmLabel: 'Desactivar',
    };
});
</script>

<template>
    <Head :title="department.name" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            :title="department.name"
            :description="department.description ?? undefined"
        >
            <template #eyebrow>
                <div class="flex items-center gap-2">
                    <span
                        class="flex size-6 items-center justify-center rounded"
                        :class="swatchClasses[department.color].soft"
                    >
                        <Building2 class="size-3.5" aria-hidden="true" />
                    </span>
                    <StatusBadge :active="department.is_active" />
                </div>
            </template>
            <template #actions>
                <Button
                    v-if="can.update"
                    variant="outline"
                    @click="editOpen = true"
                >
                    <Pencil />
                    Editar
                </Button>
                <template v-if="can.changeStatus">
                    <Button
                        v-if="department.is_active"
                        variant="outline"
                        @click="askConfirmation({ type: 'deactivate' })"
                    >
                        <PowerOff />
                        Desactivar
                    </Button>
                    <Button v-else variant="outline" @click="activate">
                        <Power />
                        Activar
                    </Button>
                </template>
                <Button
                    v-if="can.manageMembers && department.is_active"
                    @click="addOpen = true"
                >
                    <UserPlus />
                    Agregar miembros
                </Button>
            </template>
        </PageHeader>

        <DepartmentMetricsCard
            :metrics="metrics"
            :members-count="department.members_count ?? members.length"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <DepartmentWelcomeMessageCard
                :department="department"
                :can-update="can.update"
            />
            <DepartmentHoursCard
                :department="department"
                :platform-hours="platformHours"
                :weekdays="weekdays"
                :can-update="can.update"
            />
            <DepartmentConnectionsCard
                :connections="connections"
                :manage-url="links.connections"
            />
            <DepartmentQuickRepliesCard
                v-if="quickReplies"
                :quick-replies="quickReplies"
                :manage-url="links.quickReplies"
            />
            <section
                class="rounded-xl border border-dashed bg-card/60 p-5 lg:col-span-2"
                aria-labelledby="assignment-heading"
            >
                <h2 id="assignment-heading" class="text-sm font-semibold">
                    Distribución de conversaciones
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Las reglas de asignación de este departamento se
                    configurarán cuando se habilite la bandeja de atención. Por
                    ahora no hay parámetros que ajustar.
                </p>
            </section>
        </div>

        <h2 class="-mb-2 text-base font-semibold">Miembros</h2>

        <EmptyState
            v-if="members.length === 0"
            :icon="Users"
            title="Este departamento no tiene miembros"
            :description="
                can.manageMembers
                    ? 'Agrega a las personas que atenderán o supervisarán sus conversaciones.'
                    : 'Un administrador debe asignar miembros.'
            "
        >
            <Button
                v-if="can.manageMembers && department.is_active"
                @click="addOpen = true"
            >
                <UserPlus />
                Agregar miembros
            </Button>
        </EmptyState>

        <template v-else>
            <section
                v-for="group in memberGroups"
                :key="group.key"
                class="space-y-3"
                :aria-labelledby="`${group.key}-heading`"
            >
                <h2
                    :id="`${group.key}-heading`"
                    class="flex items-center gap-2 text-sm font-medium"
                >
                    {{ group.title }}
                    <span class="text-muted-foreground tabular-nums">
                        {{ group.members.length }}
                    </span>
                </h2>

                <div
                    v-if="group.members.length > 0"
                    class="overflow-hidden rounded-xl border bg-card shadow-card"
                >
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead>Nombre</TableHead>
                                <TableHead class="hidden sm:table-cell"
                                    >Rol</TableHead
                                >
                                <TableHead>Estado</TableHead>
                                <TableHead class="hidden md:table-cell">
                                    Último acceso
                                </TableHead>
                                <TableHead class="w-24">
                                    <span class="sr-only">Acciones</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="member in group.members"
                                :key="member.id"
                            >
                                <TableCell>
                                    <div class="flex items-center gap-3">
                                        <Avatar class="size-8 rounded-md">
                                            <AvatarFallback
                                                class="rounded-md text-xs"
                                            >
                                                {{ getInitials(member.name) }}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div class="min-w-0">
                                            <p class="truncate font-medium">
                                                {{ member.name }}
                                            </p>
                                            <p
                                                class="truncate text-xs text-muted-foreground"
                                            >
                                                {{ member.email }}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell class="hidden sm:table-cell">
                                    <RoleBadge :role="member.role" />
                                </TableCell>
                                <TableCell>
                                    <StatusBadge :active="member.is_active" />
                                </TableCell>
                                <TableCell class="hidden md:table-cell">
                                    <span
                                        v-if="member.last_login_at"
                                        :title="
                                            formatDateTime(member.last_login_at)
                                        "
                                    >
                                        {{
                                            formatRelative(member.last_login_at)
                                        }}
                                    </span>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        Nunca
                                    </span>
                                </TableCell>
                                <TableCell class="text-right">
                                    <Button
                                        v-if="
                                            removableMemberIds.includes(
                                                member.id,
                                            )
                                        "
                                        variant="ghost"
                                        size="sm"
                                        :aria-label="`Retirar a ${member.name}`"
                                        @click="
                                            askConfirmation({
                                                type: 'remove',
                                                member,
                                            })
                                        "
                                    >
                                        <UserMinus />
                                        <span class="hidden sm:inline"
                                            >Retirar</span
                                        >
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <p
                    v-else
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    {{
                        group.key === 'supervisors'
                            ? 'Sin supervisores asignados.'
                            : 'Sin agentes asignados.'
                    }}
                </p>
            </section>
        </template>

        <DepartmentFormDialog
            v-if="can.update"
            v-model:open="editOpen"
            :department="department"
            :colors="colors"
        />

        <AddMemberDialog
            v-if="can.manageMembers"
            v-model:open="addOpen"
            :department="department"
            :candidates="candidates"
        />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.description"
            :confirm-label="confirmCopy.confirmLabel"
            destructive
            :processing="processing"
            @confirm="confirmPendingAction"
        />
    </div>
</template>
