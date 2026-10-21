<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Building2,
    Eye,
    Pencil,
    Plug,
    Plus,
    Power,
    PowerOff,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import StatusBadge from '@/components/StatusBadge.vue';
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
import { useViewMode } from '@/composables/useViewMode';
import { recordActions } from '@/lib/recordActions';
import { swatchClasses } from '@/lib/swatches';
import { index as departmentsIndex, show } from '@/routes/departments';
import { update as updateStatus } from '@/routes/departments/status';
import type { ColorSwatch, DepartmentSummary, RecordAction } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Departamentos', href: departmentsIndex() }],
    },
});

const props = defineProps<{
    departments: DepartmentSummary[];
    colors: ColorSwatch[];
    can: { create: boolean };
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

const pendingDeactivation = ref<DepartmentSummary | null>(null);
const confirmOpen = ref(false);
const processingId = ref<number | null>(null);

function showFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function changeStatus(department: DepartmentSummary, active: boolean): void {
    router.visit(updateStatus(department.id), {
        data: { is_active: active },
        preserveScroll: true,
        onStart: () => (processingId.value = department.id),
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (processingId.value = null),
        onError: (errors) => {
            confirmOpen.value = false;
            showFirstError(errors);
        },
    });
}

function askDeactivation(department: DepartmentSummary): void {
    pendingDeactivation.value = department;
    confirmOpen.value = true;
}

function confirmDeactivation(): void {
    if (pendingDeactivation.value) {
        changeStatus(pendingDeactivation.value, false);
    }
}

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
        department.can?.changeStatus &&
            !department.is_active && {
                key: 'activate',
                label: 'Activar',
                icon: Power,
                onSelect: () => changeStatus(department, true),
            },
        department.can?.changeStatus &&
            department.is_active && {
                key: 'deactivate',
                label: 'Desactivar',
                icon: PowerOff,
                onSelect: () => askDeactivation(department),
            },
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
            <template v-if="can.create" #actions>
                <Button @click="openCreate">
                    <Plus />
                    Nuevo departamento
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard v-if="departments.length > 0">
            <div
                class="flex items-center justify-between gap-2 border-b px-4 py-3"
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
                <ViewModeToggle v-model="viewMode" />
            </div>

            <Table v-if="viewMode === 'table'">
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
                            !department.is_active && 'text-muted-foreground'
                        "
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                    :class="
                                        swatchClasses[department.color].soft
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
                            <StatusBadge :active="department.is_active" />
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(department)"
                                :record="department.name"
                                :busy="processingId === department.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else>
                <li v-for="department in departments" :key="department.id">
                    <RecordCard :muted="!department.is_active">
                        <template #leading>
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                :class="swatchClasses[department.color].soft"
                            >
                                <Building2 class="size-4" aria-hidden="true" />
                            </span>
                        </template>
                        <template #title>
                            <Link
                                v-if="department.can?.view"
                                :href="show(department.id)"
                                class="block truncate hover:underline"
                            >
                                {{ department.name }}
                            </Link>
                            <span v-else class="block truncate">{{
                                department.name
                            }}</span>
                        </template>
                        <template #badges>
                            <StatusBadge :active="department.is_active" />
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
                                :busy="processingId === department.id"
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
            v-model:open="confirmOpen"
            :title="`¿Desactivar ${pendingDeactivation?.name ?? 'el departamento'}?`"
            description="No se podrán agregar miembros ni recibir nuevas conversaciones. Solo es posible si no tiene conversaciones abiertas."
            confirm-label="Desactivar"
            destructive
            :processing="processingId !== null"
            @confirm="confirmDeactivation"
        />
    </div>
</template>
