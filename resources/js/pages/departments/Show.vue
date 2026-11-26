<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Pencil,
    Plug,
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
import ConnectionChips from '@/components/departments/ConnectionChips.vue';
import DepartmentConnectionsCard from '@/components/departments/DepartmentConnectionsCard.vue';
import DepartmentHoursCard from '@/components/departments/DepartmentHoursCard.vue';
import DepartmentDistributionCard from '@/components/departments/DepartmentDistributionCard.vue';
import DepartmentMetricsCard from '@/components/departments/DepartmentMetricsCard.vue';
import DepartmentQuickRepliesCard from '@/components/departments/DepartmentQuickRepliesCard.vue';
import DepartmentWelcomeMessageCard from '@/components/departments/DepartmentWelcomeMessageCard.vue';
import DepartmentServiceRulesCard from '@/components/service-rules/DepartmentServiceRulesCard.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryHero from '@/components/directory/DirectoryHero.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import PageHeader from '@/components/PageHeader.vue';
import RoleBadge from '@/components/RoleBadge.vue';
import { Button } from '@/components/ui/button';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
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
    ServiceRuleSet,
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
    /** Service rules of the department with what it inherits (ServiceRules::describe()); null while phase two is off. */
    serviceRules: ServiceRuleSet | null;
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

/** Members in the order of their role groups, for one flat grid. */
const sortedMembers = computed(() =>
    memberGroups.value.flatMap((group) => group.members),
);

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

    <div class="page-shell">
        <DirectoryHero>
            <template #avatar>
                <DirectoryAvatar
                    :name="department.name"
                    :icon="Building2"
                    :color="department.color"
                    :tone="department.is_deleted ? 'muted' : 'auto'"
                    size="xl"
                    shape="square"
                    class="dir-hero-avatar"
                />
            </template>
            <PageHeader
                help="departamentos"
                show-title
                :title="department.name"
                :description="department.description ?? undefined"
            >
                <template v-if="department.is_deleted" #eyebrow>
                    <DeletedBadge />
                </template>
                <template #actions>
                    <Button
                        v-if="can.update"
                        variant="outline"
                        class="bg-card/70"
                        @click="editOpen = true"
                    >
                        <Pencil />
                        Editar
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="outline"
                        class="bg-card/70 text-destructive hover:text-destructive"
                        @click="deletion.ask('delete', department)"
                    >
                        <Trash2 />
                        Eliminar
                    </Button>
                    <Button
                        v-if="can.restore"
                        variant="outline"
                        class="bg-card/70"
                        @click="deletion.ask('restore', department)"
                    >
                        <RotateCcw />
                        Restaurar
                    </Button>
                </template>
            </PageHeader>
            <template #footer>
                <span
                    class="dir-stat inline-flex items-center gap-1.5 px-2.5 py-1 text-xs"
                >
                    <Users
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="font-semibold tabular-nums">{{
                        department.members_count ?? members.length
                    }}</span>
                    {{
                        (department.members_count ?? members.length) === 1
                            ? 'miembro'
                            : 'miembros'
                    }}
                </span>
                <span
                    class="dir-stat inline-flex items-center gap-1.5 px-2.5 py-1 text-xs"
                >
                    <Plug
                        class="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="font-semibold tabular-nums">{{
                        connections.length
                    }}</span>
                    <span class="sr-only">{{
                        connections.length === 1 ? 'conexión' : 'conexiones'
                    }}</span>
                </span>
                <ConnectionChips :connections="connections" :limit="4" />
                <span
                    class="dir-stat inline-flex items-center gap-1.5 px-2.5 py-1 text-xs"
                >
                    <span
                        class="size-2 rounded-full bg-current"
                        :class="
                            automation.hours.is_open
                                ? 'live-dot text-success'
                                : 'text-warning'
                        "
                        aria-hidden="true"
                    />
                    {{
                        automation.hours.is_open
                            ? 'Dentro del horario'
                            : 'Fuera del horario'
                    }}
                </span>
            </template>
        </DirectoryHero>

        <DeletedRecordNotice
            v-if="department.is_deleted"
            title="Este departamento está eliminado"
        >
            No recibe ni atiende conversaciones; su historial se conserva.
            Restáuralo para volver a operar.
        </DeletedRecordNotice>

        <DepartmentMetricsCard
            :metrics="metrics"
            :members-count="department.members_count ?? members.length"
        />

        <section
            class="dir-panel"
            aria-labelledby="department-members-title"
            data-tour="department.members"
        >
            <header
                class="flex flex-wrap items-center gap-3 border-b border-[color-mix(in_oklch,var(--border)_70%,transparent)] px-4 py-3"
            >
                <h2
                    id="department-members-title"
                    class="flex items-center gap-2 font-display text-lg font-semibold"
                >
                    Miembros
                    <span
                        class="rounded-full bg-muted px-2 font-sans text-xs font-medium text-muted-foreground tabular-nums"
                        >{{ members.length }}</span
                    >
                </h2>
                <ul
                    v-if="memberGroups.length > 1"
                    class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                    aria-label="Por rol"
                >
                    <li
                        v-for="group in memberGroups"
                        :key="group.key"
                        class="rounded-full border px-2 py-0.5"
                    >
                        {{ group.title }}
                        <span
                            class="font-medium text-foreground tabular-nums"
                            >{{ group.members.length }}</span
                        >
                    </li>
                </ul>
                <Button
                    v-if="can.manageMembers"
                    size="sm"
                    class="ml-auto max-sm:min-h-10"
                    @click="addOpen = true"
                >
                    <UserPlus />
                    Agregar miembros
                </Button>
            </header>

            <FilteredEmpty
                v-if="members.length === 0"
                :narrowed="false"
                narrowed-title=""
                title="Sin miembros"
                :description="
                    can.manageMembers
                        ? 'Agrega a quienes atenderán sus conversaciones.'
                        : 'Un administrador debe asignar miembros.'
                "
                :icon="Users"
            />

            <ul
                v-else
                class="auto-grid p-3 [--col-min:17rem] [--grid-gap:0.5rem] sm:p-4"
                role="list"
            >
                <li
                    v-for="member in sortedMembers"
                    :key="member.id"
                    class="flex min-w-0 items-center gap-3 rounded-lg border bg-card px-3 py-2.5"
                    :class="member.is_deleted && 'opacity-60'"
                >
                    <DirectoryAvatar
                        :name="member.name"
                        :user-id="member.id"
                        :tone="member.is_deleted ? 'muted' : 'auto'"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex min-w-0 items-center gap-1.5 text-sm font-medium"
                        >
                            <span class="truncate">{{ member.name }}</span>
                            <DeletedBadge v-if="member.is_deleted" />
                        </p>
                        <p
                            class="truncate text-xs text-muted-foreground"
                            :title="
                                member.last_login_at
                                    ? `${member.email} · último acceso ${formatDateTime(member.last_login_at)}`
                                    : member.email
                            "
                        >
                            <span
                                class="mr-1 inline-block size-1.5 rounded-full align-middle"
                                :class="
                                    member.online
                                        ? 'bg-success'
                                        : 'bg-muted-foreground/40'
                                "
                                aria-hidden="true"
                            />
                            <span
                                :class="
                                    member.online && 'font-medium text-success'
                                "
                                >{{
                                    member.online
                                        ? 'En sesión'
                                        : member.last_activity_at
                                          ? `Sin actividad desde ${formatRelative(member.last_activity_at)}`
                                          : member.last_login_at
                                            ? `Fuera de sesión · entró ${formatRelative(member.last_login_at)}`
                                            : 'Nunca ha entrado'
                                }}</span
                            >
                            ·
                            {{
                                member.last_reply_at
                                    ? `contestó ${formatRelative(member.last_reply_at)}`
                                    : 'sin respuestas en 30 días'
                            }}
                        </p>
                    </div>
                    <RoleBadge :role="member.role" class="shrink-0" />
                    <Button
                        v-if="removableMemberIds.includes(member.id)"
                        variant="ghost"
                        size="icon-sm"
                        class="size-9 shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive sm:size-8"
                        :aria-label="`Retirar a ${member.name}`"
                        :title="`Retirar a ${member.name}`"
                        @click="askRemoval(member)"
                    >
                        <UserMinus />
                    </Button>
                </li>
            </ul>
        </section>

        <!--
          Settings cards have very different heights: columns pack them
          without the empty halves a two-column grid leaves.
        -->
        <div
            class="gap-6 lg:columns-2 2xl:columns-3 [&>*]:mb-6 [&>*]:break-inside-avoid"
        >
            <DepartmentDistributionCard
                data-tour="department.distribution"
                :department="department"
                :modes="distributionModes"
                :can-update="can.update"
            />
            <DepartmentWelcomeMessageCard
                :department="department"
                :can-update="can.update"
            />
            <DepartmentHoursCard
                data-tour="department.hours"
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

        <DepartmentServiceRulesCard
            v-if="serviceRules"
            data-tour="department.service-rules"
            :department="department"
            :rules="serviceRules"
            :can-update="can.update"
        />

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
            description="Dejará de ver las conversaciones del departamento y sus conversaciones abiertas en él volverán a «Pendientes» del departamento."
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
