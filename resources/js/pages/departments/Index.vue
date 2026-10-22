<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Building2,
    Eye,
    Pencil,
    Plug,
    Plus,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
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
import { useRecordStateFilter } from '@/composables/useRecordStateFilter';
import { useViewMode } from '@/composables/useViewMode';
import { recordActions } from '@/lib/recordActions';
import { colorPresentations } from '@/lib/colors';
import { departmentDeletionCopy } from '@/lib/deletionCopy';
import {
    destroy as destroyDepartment,
    index as departmentsIndex,
    restore as restoreDepartment,
    show,
} from '@/routes/departments';
import type {
    ColorSwatch,
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
    filters: { record: RecordStateValue };
    recordStates: RecordStateOption[];
    colors: ColorSwatch[];
    can: { create: boolean };
    canExport?: boolean;
}>();

const { mode: viewMode } = useViewMode('departments');

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

function leadersLabel(department: DepartmentSummary): string {
    return department.leaders && department.leaders.length > 0
        ? department.leaders.map((leader) => leader.name).join(', ')
        : 'Sin responsable';
}
</script>

<template>
    <Head title="Departamentos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Departamentos"
            :description="
                can.create
                    ? 'Organiza a los equipos y define quién atiende cada tipo de conversación.'
                    : 'Departamentos a los que perteneces.'
            "
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="departments"
                    :filters="filters"
                />
                <Button v-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo departamento
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard v-if="departments.length > 0 || record !== 'active'">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3"
            >
                <p class="text-sm text-muted-foreground">
                    {{
                        plural(
                            departments.length,
                            'departamento',
                            'departamentos',
                        )
                    }}
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
                v-if="departments.length === 0"
                class="m-4 border-none"
                :icon="Building2"
                title="Ningún departamento en este estado"
                description="Prueba con otro estado del registro."
            />

            <Table v-else-if="viewMode === 'table'">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Departamento</TableHead>
                        <TableHead class="hidden lg:table-cell"
                            >Miembros</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Conexiones</TableHead
                        >
                        <TableHead class="hidden xl:table-cell"
                            >Responsables</TableHead
                        >
                        <TableHead>Estado</TableHead>
                        <TableHead class="pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="department in departments"
                        :key="department.id"
                        :class="
                            department.is_deleted && 'text-muted-foreground'
                        "
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                    v-bind="
                                        colorPresentations(department.color)
                                            .soft
                                    "
                                >
                                    <Building2
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </span>
                                <div class="min-w-0">
                                    <Link
                                        v-if="department.can?.view"
                                        :href="show(department.id)"
                                        class="block truncate font-medium text-foreground hover:underline"
                                    >
                                        {{ department.name }}
                                    </Link>
                                    <p
                                        v-else
                                        class="truncate font-medium text-foreground"
                                    >
                                        {{ department.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        {{
                                            department.description ||
                                            'Sin descripción.'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            {{ department.members_count ?? 0 }}
                        </TableCell>
                        <TableCell class="hidden text-sm lg:table-cell">
                            {{ department.connections_count ?? 0 }}
                        </TableCell>
                        <TableCell
                            class="hidden max-w-56 truncate text-sm xl:table-cell"
                        >
                            {{ leadersLabel(department) }}
                        </TableCell>
                        <TableCell>
                            <DeletedBadge v-if="department.is_deleted" />
                            <span v-else class="text-xs text-muted-foreground"
                                >Activo</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(department)"
                                :record="department.name"
                                :busy="deletion.processingId === department.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else>
                <li v-for="department in departments" :key="department.id">
                    <RecordCard
                        :muted="department.is_deleted"
                        :href="
                            department.can?.view
                                ? show(department.id)
                                : undefined
                        "
                        :label="`Ver departamento ${department.name}`"
                    >
                        <template #leading>
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                v-bind="
                                    colorPresentations(department.color).soft
                                "
                            >
                                <Building2 class="size-4" aria-hidden="true" />
                            </span>
                        </template>
                        <template #title>
                            <span class="block truncate">{{
                                department.name
                            }}</span>
                        </template>
                        <template v-if="department.is_deleted" #badges>
                            <DeletedBadge />
                        </template>
                        <p class="line-clamp-2 text-muted-foreground">
                            {{ department.description || 'Sin descripción.' }}
                        </p>
                        <p
                            class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <UserRound
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                leadersLabel(department)
                            }}</span>
                        </p>
                        <template #meta>
                            <span class="inline-flex items-center gap-1">
                                <Users class="size-3.5" aria-hidden="true" />
                                {{
                                    plural(
                                        department.members_count,
                                        'miembro',
                                        'miembros',
                                    )
                                }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <Plug class="size-3.5" aria-hidden="true" />
                                {{
                                    plural(
                                        department.connections_count,
                                        'conexión',
                                        'conexiones',
                                    )
                                }}
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
            </RecordGrid>
        </SurfaceCard>

        <EmptyState
            v-else
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
