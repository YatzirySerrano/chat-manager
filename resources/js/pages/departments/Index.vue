<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building2,
    Eye,
    Inbox,
    MessagesSquare,
    Pencil,
    Plug,
    Plus,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import ConnectionChips from '@/components/departments/ConnectionChips.vue';
import MemberAvatarStack from '@/components/departments/MemberAvatarStack.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import StatCard from '@/components/StatCard.vue';
import { Button } from '@/components/ui/button';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { useRecordStateFilter } from '@/composables/useRecordStateFilter';
import { useViewMode } from '@/composables/useViewMode';
import { departmentDeletionCopy } from '@/lib/deletionCopy';
import { recordActions } from '@/lib/recordActions';
import {
    destroy as destroyDepartment,
    index as departmentsIndex,
    restore as restoreDepartment,
    show,
} from '@/routes/departments';
import type {
    ColorSwatch,
    DepartmentPreview,
    DepartmentSummary,
    RecordAction,
    RecordStateOption,
    RecordStateValue,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Departamentos', href: departmentsIndex() }],
    },
});

const props = defineProps<{
    departments: DepartmentSummary[];
    /** Members, connections and live conversations per department id. */
    previews?: Record<number, DepartmentPreview>;
    filters: { record: RecordStateValue };
    recordStates: RecordStateOption[];
    colors: ColorSwatch[];
    can: { create: boolean };
    canExport?: boolean;
}>();

const { mode: viewMode } = useViewMode('departments');

/* What needs attention across the departments on screen. */
const overview = computed(() => ({
    withoutLeader: props.departments.filter(
        (department) => (department.leaders ?? []).length === 0,
    ).length,
    withoutMembers: props.departments.filter(
        (department) => (department.members_count ?? 0) === 0,
    ).length,
    withoutConnection: props.departments.filter(
        (department) => (department.connections_count ?? 0) === 0,
    ).length,
}));

const formOpen = ref(false);
const editingDepartment = ref<DepartmentSummary | null>(null);

const canEditAny = computed(
    () =>
        props.can.create ||
        props.departments.some((department) => department.can?.update),
);

function openCreate(): void {
    editingDepartment.value = null;
    formOpen.value = true;
}

function openEdit(department: DepartmentSummary): void {
    editingDepartment.value = department;
    formOpen.value = true;
}

const { record, apply: applyRecordState } = useRecordStateFilter(
    departmentsIndex,
    props.filters.record,
    ['departments', 'filters'],
);

/*
 * Search within the departments on screen (the list is not paginated): by
 * name, description or responsible, ignoring accents and case.
 */
const search = ref('');

function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const visibleDepartments = computed(() => {
    const words = normalize(search.value.trim()).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return props.departments;
    }

    return props.departments.filter((department) => {
        const haystack = normalize(
            [
                department.name,
                department.description ?? '',
                ...(department.leaders ?? []).map((leader) => leader.name),
            ].join(' '),
        );

        return words.every((word) => haystack.includes(word));
    });
});

const narrowed = computed(() => search.value.trim() !== '');

function clearSearch(): void {
    search.value = '';
}

/* The record state reloads the list: the rows dim while it arrives. */
const loading = ref(false);
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.method === 'get' && !event.detail.visit.async) {
        loading.value = true;
    }
});
const stopFinish = router.on('finish', () => (loading.value = false));

onUnmounted(() => {
    stopStart();
    stopFinish();
});

const deletion = useRecordDeletion<DepartmentSummary>({
    destroy: (department) => destroyDepartment(department.id),
    restore: (department) => restoreDepartment(department.id),
    copy: departmentDeletionCopy,
});

function actionsFor(department: DepartmentSummary): RecordAction[] {
    return recordActions(
        department.can?.view && {
            key: 'view',
            label: 'Ver',
            icon: Eye,
            href: show(department.id),
        },
        department.can?.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openEdit(department),
        },
        ...deletion.actionsFor(department),
    );
}

function plural(count: number | undefined, one: string, many: string): string {
    const value = count ?? 0;

    return `${value} ${value === 1 ? one : many}`;
}

const EMPTY_PREVIEW: DepartmentPreview = {
    members: [],
    connections: [],
    conversations: { pending: 0, open: 0 },
};

function previewOf(department: DepartmentSummary): DepartmentPreview {
    return props.previews?.[department.id] ?? EMPTY_PREVIEW;
}

function leadersLabel(department: DepartmentSummary): string {
    return department.leaders && department.leaders.length > 0
        ? department.leaders.map((leader) => leader.name).join(', ')
        : 'Sin responsable';
}
</script>

<template>
    <Head title="Departamentos" />

    <div class="page-shell">
        <PageHeader
            help="departamentos"
            title="Departamentos"
            :description="
                can.create
                    ? 'Equipos, sus miembros y los canales que atienden.'
                    : 'Departamentos a los que perteneces.'
            "
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="departments"
                    :filters="filters"
                />
                <Button
                    v-if="can.create"
                    class="max-sm:w-full"
                    data-tour="departments.new"
                    @click="openCreate"
                >
                    <Plus />
                    Nuevo departamento
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="departments.length > 0 && record === 'active'"
            class="grid reveal-stagger grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <StatCard
                label="Departamentos"
                :value="departments.length"
                :icon="Building2"
            />
            <StatCard
                label="Sin responsable"
                :value="overview.withoutLeader"
                :icon="UserRound"
            />
            <StatCard
                label="Sin miembros"
                :value="overview.withoutMembers"
                :icon="Users"
            />
            <StatCard
                label="Sin conexión"
                :value="overview.withoutConnection"
                :icon="Plug"
            />
        </div>

        <DirectoryPanel
            v-if="departments.length > 0 || record !== 'active'"
            class="reveal [--i:4]"
            :busy="loading"
        >
            <template #toolbar>
                <div class="flex flex-wrap items-center gap-2">
                    <FilterSearch
                        v-model="search"
                        label="Buscar departamentos"
                        placeholder="Buscar por nombre, descripción o responsable"
                        class="basis-full sm:basis-auto"
                    />
                    <RecordStateFilter
                        v-model="record"
                        :options="recordStates"
                        class="flex-1 sm:w-36 sm:flex-none"
                        @change="applyRecordState"
                    />
                    <ViewModeToggle v-model="viewMode" />
                </div>
                <p
                    v-if="narrowed"
                    class="mt-2 px-1 text-xs text-muted-foreground"
                    aria-live="polite"
                >
                    {{
                        `${plural(visibleDepartments.length, 'departamento', 'departamentos')} de ${departments.length}`
                    }}
                </p>
            </template>

            <FilteredEmpty
                v-if="visibleDepartments.length === 0"
                :narrowed="narrowed"
                narrowed-title="Ningún departamento coincide con la búsqueda"
                narrowed-description="Prueba con otro nombre o con el de su responsable."
                title="Ningún departamento en este estado"
                description="Cambia el filtro (Activos, Eliminados o Todos)."
                :icon="Building2"
                @clear="clearSearch"
            />

            <ul v-else-if="viewMode === 'table'" class="dir-rows" role="list">
                <DirectoryRow
                    v-for="(department, position) in visibleDepartments"
                    :key="department.id"
                    :href="
                        department.can?.view ? show(department.id) : undefined
                    "
                    :label="`Ver departamento ${department.name}`"
                    :muted="department.is_deleted"
                    class="enter-fade"
                    :style="{ '--i': Math.min(position, 10) }"
                    :data-tour="position === 0 ? 'departments.list' : undefined"
                >
                    <template #leading>
                        <DirectoryAvatar
                            :name="department.name"
                            :icon="Building2"
                            :color="department.color"
                            :tone="department.is_deleted ? 'muted' : 'auto'"
                            shape="square"
                        />
                    </template>
                    <template #title>{{ department.name }}</template>
                    <template v-if="department.description" #subtitle>
                        {{ department.description }}
                    </template>
                    <template #badges>
                        <DeletedBadge v-if="department.is_deleted" />
                        <span
                            class="inline-flex min-w-0 items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                            :class="
                                (department.leaders ?? []).length === 0 &&
                                'bg-warning-soft text-warning-soft-foreground'
                            "
                            :title="`Responsables: ${leadersLabel(department)}`"
                        >
                            <UserRound
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="max-w-56 truncate">{{
                                leadersLabel(department)
                            }}</span>
                        </span>
                        <span
                            v-if="
                                previewOf(department).conversations.pending > 0
                            "
                            class="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning-soft-foreground tabular-nums"
                            :title="`${previewOf(department).conversations.pending} pendientes de tomar`"
                        >
                            <Inbox class="size-3" aria-hidden="true" />
                            {{ previewOf(department).conversations.pending }}
                            <span class="sr-only">pendientes</span>
                        </span>
                        <span
                            v-if="previewOf(department).conversations.open > 0"
                            class="inline-flex items-center gap-1 rounded-full bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary-soft-foreground tabular-nums"
                            :title="`${previewOf(department).conversations.open} en atención`"
                        >
                            <MessagesSquare class="size-3" aria-hidden="true" />
                            {{ previewOf(department).conversations.open }}
                            <span class="sr-only">en atención</span>
                        </span>
                    </template>
                    <div
                        class="mt-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 md:hidden"
                    >
                        <MemberAvatarStack
                            :members="previewOf(department).members"
                            :total="department.members_count ?? 0"
                            :max="3"
                        />
                        <ConnectionChips
                            :connections="previewOf(department).connections"
                            :limit="2"
                        />
                    </div>
                    <template #aside>
                        <span class="flex w-36 items-center">
                            <MemberAvatarStack
                                :members="previewOf(department).members"
                                :total="department.members_count ?? 0"
                            />
                        </span>
                        <span class="flex w-[23rem] items-center">
                            <ConnectionChips
                                :connections="previewOf(department).connections"
                                :limit="2"
                            />
                        </span>
                    </template>
                    <template #actions>
                        <RecordActions
                            collapse-on-phone
                            :actions="actionsFor(department)"
                            :record="department.name"
                            :busy="deletion.processingId === department.id"
                        />
                    </template>
                </DirectoryRow>
            </ul>

            <ul v-else class="dir-grid" role="list">
                <li
                    v-for="(department, position) in visibleDepartments"
                    :key="department.id"
                    class="enter-spring"
                    :style="{ '--i': Math.min(position, 10) }"
                    :data-tour="position === 0 ? 'departments.list' : undefined"
                >
                    <RecordCard
                        class="dir-card"
                        :muted="department.is_deleted"
                        :href="
                            department.can?.view
                                ? show(department.id)
                                : undefined
                        "
                        :label="`Ver departamento ${department.name}`"
                    >
                        <template #leading>
                            <DirectoryAvatar
                                :name="department.name"
                                :icon="Building2"
                                :color="department.color"
                                :tone="department.is_deleted ? 'muted' : 'auto'"
                                shape="square"
                            />
                        </template>
                        <template #title>
                            <span class="block truncate">{{
                                department.name
                            }}</span>
                        </template>
                        <template v-if="department.is_deleted" #badges>
                            <DeletedBadge />
                        </template>
                        <p
                            v-if="department.description"
                            class="line-clamp-1 text-muted-foreground"
                        >
                            {{ department.description }}
                        </p>
                        <div class="flex items-center justify-between gap-3">
                            <MemberAvatarStack
                                :members="previewOf(department).members"
                                :total="department.members_count ?? 0"
                            />
                            <span
                                class="flex items-center gap-1.5 text-xs tabular-nums"
                            >
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5"
                                    :class="
                                        previewOf(department).conversations
                                            .pending > 0
                                            ? 'bg-warning-soft font-medium text-warning-soft-foreground'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    :title="`${previewOf(department).conversations.pending} pendientes de tomar`"
                                >
                                    <Inbox class="size-3" aria-hidden="true" />
                                    {{
                                        previewOf(department).conversations
                                            .pending
                                    }}
                                    <span class="sr-only">pendientes</span>
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-muted-foreground"
                                    :title="`${previewOf(department).conversations.open} en atención`"
                                >
                                    <MessagesSquare
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{
                                        previewOf(department).conversations.open
                                    }}
                                    <span class="sr-only">en atención</span>
                                </span>
                            </span>
                        </div>
                        <ConnectionChips
                            :connections="previewOf(department).connections"
                            :limit="3"
                        />
                        <template #meta>
                            <span
                                class="inline-flex min-w-0 items-center gap-1"
                                :class="
                                    (department.leaders ?? []).length === 0 &&
                                    'text-warning-soft-foreground'
                                "
                                :title="`Responsables: ${leadersLabel(department)}`"
                            >
                                <UserRound
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{
                                    leadersLabel(department)
                                }}</span>
                            </span>
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(department)"
                                :record="department.name"
                                :busy="deletion.processingId === department.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </ul>
        </DirectoryPanel>

        <div v-else class="reveal surface-float">
            <EmptyState
                class="border-none bg-transparent"
                :icon="Building2"
                :title="
                    can.create
                        ? 'Aún no hay departamentos'
                        : 'No perteneces a ningún departamento'
                "
                :description="
                    can.create
                        ? 'Crea el primer departamento para organizar la atención.'
                        : 'Un administrador debe agregarte a un departamento.'
                "
            >
                <Button v-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo departamento
                </Button>
            </EmptyState>
        </div>

        <DepartmentFormDialog
            v-if="canEditAny"
            v-model:open="formOpen"
            :department="editingDepartment"
            :colors="colors"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
