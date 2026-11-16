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
import DepartmentConnectionsCard from '@/components/departments/DepartmentConnectionsCard.vue';
import DepartmentHoursCard from '@/components/departments/DepartmentHoursCard.vue';
import DepartmentDistributionCard from '@/components/departments/DepartmentDistributionCard.vue';
import DepartmentMetricsCard from '@/components/departments/DepartmentMetricsCard.vue';
import DepartmentQuickRepliesCard from '@/components/departments/DepartmentQuickRepliesCard.vue';
import DepartmentWelcomeMessageCard from '@/components/departments/DepartmentWelcomeMessageCard.vue';
import DepartmentServiceRulesCard from '@/components/service-rules/DepartmentServiceRulesCard.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryHero from '@/components/directory/DirectoryHero.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
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
                    <Button v-if="can.manageMembers" @click="addOpen = true">
                        <UserPlus />
                        Agregar miembros
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
                    {{ connections.length === 1 ? 'conexión' : 'conexiones' }}
                </span>
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
            No recibe ni atiende conversaciones. Sus miembros conservan la
            membresía sin efecto y el historial mantiene el departamento
            original. Restáuralo para volver a operar con él.
        </DeletedRecordNotice>

        <DepartmentMetricsCard
            :metrics="metrics"
            :members-count="department.members_count ?? members.length"
        />

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

        <section class="grid gap-4" aria-labelledby="department-members-title">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2
                        id="department-members-title"
                        class="font-display text-xl font-semibold"
                    >
                        Miembros
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Quienes atienden o supervisan las conversaciones del
                        departamento, por rol.
                    </p>
                </div>
                <Button
                    v-if="can.manageMembers && members.length > 0"
                    variant="outline"
                    size="sm"
                    class="rounded-full max-sm:min-h-11 max-sm:w-full"
                    @click="addOpen = true"
                >
                    <UserPlus />
                    Agregar miembros
                </Button>
            </div>

            <div
                v-if="members.length === 0"
                class="surface-float"
                data-tour="department.members"
            >
                <FilteredEmpty
                    :narrowed="false"
                    narrowed-title=""
                    title="Este departamento no tiene miembros"
                    :description="
                        can.manageMembers
                            ? 'Agrega a las personas que atenderán o supervisarán sus conversaciones.'
                            : 'Un administrador debe asignar miembros.'
                    "
                    :icon="Users"
                >
                    <Button v-if="can.manageMembers" @click="addOpen = true">
                        <UserPlus />
                        Agregar miembros
                    </Button>
                </FilteredEmpty>
            </div>

            <template v-else>
                <section
                    v-for="(group, position) in memberGroups"
                    :key="group.key"
                    :data-tour="
                        position === 0 ? 'department.members' : undefined
                    "
                    class="dir-panel"
                    :aria-labelledby="`members-group-${group.key}`"
                >
                    <header
                        class="flex items-center gap-2 border-b border-[color-mix(in_oklch,var(--border)_70%,transparent)] px-4 py-3"
                    >
                        <h3
                            :id="`members-group-${group.key}`"
                            class="text-sm font-semibold"
                        >
                            {{ group.title }}
                        </h3>
                        <span
                            class="rounded-full bg-muted px-2 text-xs font-medium text-muted-foreground tabular-nums"
                        >
                            {{ group.members.length }}
                        </span>
                    </header>
                    <ul class="dir-rows" role="list">
                        <DirectoryRow
                            v-for="member in group.members"
                            :key="member.id"
                            :muted="member.is_deleted"
                        >
                            <template #leading>
                                <DirectoryAvatar
                                    :name="member.name"
                                    :tone="member.is_deleted ? 'muted' : 'auto'"
                                />
                            </template>
                            <template #title>
                                <span class="font-sans">{{ member.name }}</span>
                            </template>
                            <template #subtitle>{{ member.email }}</template>
                            <template #badges>
                                <RoleBadge :role="member.role" />
                                <DeletedBadge v-if="member.is_deleted" />
                                <span
                                    class="text-xs text-muted-foreground md:hidden"
                                    :title="
                                        member.last_login_at
                                            ? formatDateTime(
                                                  member.last_login_at,
                                              )
                                            : undefined
                                    "
                                >
                                    {{
                                        member.last_login_at
                                            ? `Último acceso ${formatRelative(member.last_login_at)}`
                                            : 'Nunca ha entrado'
                                    }}
                                </span>
                            </template>
                            <template #aside>
                                <span
                                    class="w-40 text-right text-xs"
                                    :title="
                                        member.last_login_at
                                            ? formatDateTime(
                                                  member.last_login_at,
                                              )
                                            : undefined
                                    "
                                >
                                    <span class="block text-muted-foreground"
                                        >Último acceso</span
                                    >
                                    <span class="text-foreground">{{
                                        member.last_login_at
                                            ? formatRelative(
                                                  member.last_login_at,
                                              )
                                            : 'Nunca'
                                    }}</span>
                                </span>
                            </template>
                            <template
                                v-if="removableMemberIds.includes(member.id)"
                                #actions
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground hover:bg-destructive/10 hover:text-destructive max-sm:size-10 max-sm:p-0"
                                    :aria-label="`Retirar a ${member.name}`"
                                    @click="askRemoval(member)"
                                >
                                    <UserMinus />
                                    <span class="hidden sm:inline"
                                        >Retirar</span
                                    >
                                </Button>
                            </template>
                        </DirectoryRow>
                    </ul>
                </section>
            </template>
        </section>

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
