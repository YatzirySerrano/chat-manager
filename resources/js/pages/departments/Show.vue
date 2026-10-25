<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    RotateCcw,
    Trash2,
    UserMinus,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import AddMemberDialog from '@/components/admin/AddMemberDialog.vue';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DeletedRecordNotice from '@/components/DeletedRecordNotice.vue';
import DepartmentAutomationCard from '@/components/departments/DepartmentAutomationCard.vue';
import DepartmentConnectionsCard from '@/components/departments/DepartmentConnectionsCard.vue';
import DepartmentHoursCard from '@/components/departments/DepartmentHoursCard.vue';
import DepartmentDistributionCard from '@/components/departments/DepartmentDistributionCard.vue';
import DepartmentMetricsCard from '@/components/departments/DepartmentMetricsCard.vue';
import DepartmentQuickRepliesCard from '@/components/departments/DepartmentQuickRepliesCard.vue';
import DepartmentWelcomeMessageCard from '@/components/departments/DepartmentWelcomeMessageCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import RoleBadge from '@/components/RoleBadge.vue';
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
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { colorPresentations } from '@/lib/colors';
import { departmentDeletionCopy } from '@/lib/deletionCopy';
import { formatDateTime, formatRelative } from '@/lib/format';
import { toastFirstError } from '@/lib/toastErrors';
import {
    destroy as destroyDepartment,
    index as departmentsIndex,
    restore as restoreDepartment,
    show,
} from '@/routes/departments';
import { destroy as destroyMember } from '@/routes/departments/members';
import type {
    ColorSwatch,
    DepartmentAutomation,
    DepartmentConnection,
    DepartmentMember,
    DepartmentMetrics,
    DepartmentQuickReplies,
    DepartmentSummary,
    DistributionModeValue,
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
    distributionModes: {
        value: DistributionModeValue;
        label: string;
        description: string;
    }[];
    automation: DepartmentAutomation;
    can: {
        update: boolean;
        delete: boolean;
        restore: boolean;
        manageMembers: boolean;
    };
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

const deletion = useRecordDeletion<DepartmentSummary>({
    destroy: (department) => destroyDepartment(department.id),
    restore: (department) => restoreDepartment(department.id),
    copy: departmentDeletionCopy,
});

const pendingRemoval = ref<DepartmentMember | null>(null);
const removalOpen = ref(false);
const removing = ref(false);

function askRemoval(member: DepartmentMember): void {
    pendingRemoval.value = member;
    removalOpen.value = true;
}

function confirmRemoval(): void {
    const member = pendingRemoval.value;

    if (!member) {
        return;
    }

    router.visit(
        destroyMember({ department: props.department.id, member: member.id }),
        {
            preserveScroll: true,
            onStart: () => (removing.value = true),
            onFinish: () => {
                removing.value = false;
                removalOpen.value = false;
            },
            onError: toastFirstError,
        },
    );
}
</script>

<template>
    <Head :title="department.name" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            help="departamentos"
            show-title
            :title="department.name"
            :description="department.description ?? undefined"
        >
            <template #eyebrow>
                <div class="flex items-center gap-2">
                    <span
                        class="flex size-6 items-center justify-center rounded"
                        v-bind="colorPresentations(department.color).soft"
                    >
                        <Building2 class="size-3.5" aria-hidden="true" />
                    </span>
                    <DeletedBadge v-if="department.is_deleted" />
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
                <Button
                    v-if="can.delete"
                    variant="outline"
                    class="text-destructive hover:text-destructive"
                    @click="deletion.ask('delete', department)"
                >
                    <Trash2 />
                    Eliminar
                </Button>
                <Button
                    v-if="can.restore"
                    variant="outline"
                    @click="deletion.ask('restore', department)"
                >
                    <RotateCcw />
                    Restaurar
                </Button>
                <Button v-if="can.manageMembers" @click="addOpen = true">
                    <UserPlus />
                    Agregar miembros
                </Button>
            </template>
        </PageHeader>

        <DeletedRecordNotice
            v-if="department.is_deleted"
            title="Este departamento está eliminado"
        >
            No recibe ni atiende conversaciones. Sus miembros conservan la
            membresía sin efecto y el historial mantiene el departamento
            original. Restáuralo para volver a operar con él.
        </DeletedRecordNotice>

        <DepartmentMetricsCard
            :metrics="metrics"
            :members-count="department.members_count ?? members.length"
        />

        <div class="grid gap-6 lg:grid-cols-2">
            <DepartmentDistributionCard
                :department="department"
                :modes="distributionModes"
                :can-update="can.update"
            />
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
            <DepartmentAutomationCard
                :automation="automation"
                :connections="connections"
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
            <Button v-if="can.manageMembers" @click="addOpen = true">
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
                                :class="
                                    member.is_deleted && 'text-muted-foreground'
                                "
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
                                    <DeletedBadge v-if="member.is_deleted" />
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >Activo</span
                                    >
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
                                        @click="askRemoval(member)"
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
            v-model:open="removalOpen"
            :title="`¿Retirar a ${pendingRemoval?.name ?? ''}?`"
            description="Dejará de ver las conversaciones del departamento y sus conversaciones abiertas en él regresarán a la cola."
            confirm-label="Retirar"
            destructive
            :processing="removing"
            @confirm="confirmRemoval"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
