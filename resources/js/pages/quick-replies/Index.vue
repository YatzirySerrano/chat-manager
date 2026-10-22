<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Globe, MessageSquareText, Pencil, Plus, Search, X } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import QuickReplyFormDialog from '@/components/quick-replies/QuickReplyFormDialog.vue';
import QuickReplySandbox from '@/components/quick-replies/QuickReplySandbox.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import WhatsAppText from '@/components/WhatsAppText.vue';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { useViewMode } from '@/composables/useViewMode';
import { recordActions } from '@/lib/recordActions';
import {
    destroy as destroyQuickReply,
    index as quickRepliesIndex,
    restore as restoreQuickReply,
} from '@/routes/quick-replies';
import type {
    DepartmentOption,
    Paginated,
    QuickReplyFilters,
    QuickReplySummary,
    QuickReplyVariableOption,
    RecordAction,
    RecordStateOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Respuestas rápidas', href: quickRepliesIndex() },
        ],
    },
});

const props = defineProps<{
    quickReplies: Paginated<QuickReplySummary>;
    filters: QuickReplyFilters;
    recordStates: RecordStateOption[];
    departments: DepartmentOption[];
    assignableDepartments: DepartmentOption[];
    variables: QuickReplyVariableOption[];
    sampleValues: Record<string, string>;
    bodyMaxLength: number;
    can: { create: boolean; manageGlobal: boolean; use: boolean };
    canExport?: boolean;
}>();

const ALL = 'all';

const filterState = reactive({
    search: props.filters.search ?? '',
    department:
        props.filters.department === null
            ? ALL
            : String(props.filters.department),
    record: props.filters.record,
    availability: props.filters.availability ?? ALL,
});

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.department !== ALL ||
        filterState.record !== 'active' ||
        filterState.availability !== ALL,
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    for (const key of ['department', 'availability'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.record !== 'active') {
        query.record = filterState.record;
    }

    router.get(quickRepliesIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['quickReplies', 'filters'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    filterState.search = '';
    filterState.department = ALL;
    filterState.record = 'active';
    filterState.availability = ALL;
    applyFilters();
}

const formOpen = ref(false);
const editingReply = ref<QuickReplySummary | null>(null);

function openCreate(): void {
    editingReply.value = null;
    formOpen.value = true;
}

function openEdit(reply: QuickReplySummary): void {
    editingReply.value = reply;
    formOpen.value = true;
}

const deletion = useRecordDeletion<QuickReplySummary>({
    destroy: (reply) => destroyQuickReply(reply.id),
    restore: (reply) => restoreQuickReply(reply.id),
    copy: (reply, step) =>
        step === 'delete'
            ? {
                  title: `¿Eliminar la respuesta /${reply.shortcut}?`,
                  description: `Nadie podrá usarla y su atajo /${reply.shortcut} quedará reservado mientras esté eliminada. Los mensajes ya enviados no cambian. Podrás restaurarla.`,
              }
            : {
                  title: `¿Restaurar la respuesta /${reply.shortcut}?`,
                  description:
                      'Volverá a aparecer al escribir «/» en el chat para quienes la tienen disponible.',
              },
});

const { mode: viewMode } = useViewMode('quick-replies');

function actionsFor(reply: QuickReplySummary): RecordAction[] {
    return recordActions(
        reply.can.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openEdit(reply),
        },
        ...deletion.actionsFor(reply),
    );
}
</script>

<template>
    <Head title="Respuestas rápidas" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Respuestas rápidas"
            description="Textos predefinidos que el equipo inserta en el chat escribiendo «/» y su atajo. Se insertan para revisarlos; nunca se envían solos."
        >
            <template v-if="can.create || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="quick-replies"
                    :filters="filters"
                />
                <Button v-if="can.create" @click="openCreate">
                    <Plus />
                    Nueva respuesta
                </Button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <SurfaceCard>
                <div
                    class="flex flex-wrap items-center gap-2 border-b p-4"
                    role="search"
                >
                    <div class="relative order-1 min-w-0 flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="filterState.search"
                            type="search"
                            placeholder="Buscar por título, atajo o texto"
                            class="pl-9"
                            aria-label="Buscar respuestas rápidas"
                        />
                    </div>
                    <ViewModeToggle v-model="viewMode" class="order-2" />
                    <div
                        class="order-3 grid w-full grid-cols-2 gap-2 sm:flex sm:flex-wrap"
                    >
                        <Select
                            v-model="filterState.department"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-44"
                                aria-label="Filtrar por departamento"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Todos los departamentos</SelectItem
                                >
                                <SelectItem
                                    v-for="department in departments"
                                    :key="department.id"
                                    :value="String(department.id)"
                                >
                                    {{ department.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            v-model="filterState.availability"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-40"
                                aria-label="Filtrar por disponibilidad"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Cualquier alcance</SelectItem
                                >
                                <SelectItem value="global"
                                    >Para todos</SelectItem
                                >
                                <SelectItem value="departments"
                                    >Por departamento</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <RecordStateFilter
                            v-model="filterState.record"
                            :options="recordStates"
                            class="sm:w-36"
                            @change="applyFilters"
                        />
                        <Button
                            v-if="hasFilters"
                            variant="ghost"
                            @click="clearFilters"
                        >
                            <X />
                            Limpiar
                        </Button>
                    </div>
                </div>

                <Table
                    v-if="quickReplies.data.length > 0 && viewMode === 'table'"
                >
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="pl-5">Respuesta</TableHead>
                            <TableHead class="hidden 2xl:table-cell"
                                >Disponible para</TableHead
                            >
                            <TableHead class="hidden sm:table-cell"
                                >Estado</TableHead
                            >
                            <TableHead class="pr-5 text-right">
                                <span class="sr-only">Acciones</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="reply in quickReplies.data"
                            :key="reply.id"
                            :class="reply.is_deleted && 'text-muted-foreground'"
                        >
                            <TableCell class="w-full max-w-0 py-3 pl-5">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="shrink-0 rounded-md bg-primary-soft px-1.5 py-0.5 font-mono text-xs font-medium text-primary-soft-foreground"
                                        >/{{ reply.shortcut }}</span
                                    >
                                    <span
                                        class="truncate font-medium text-foreground"
                                        >{{ reply.title }}</span
                                    >
                                </div>
                                <p
                                    class="mt-1 truncate text-xs text-muted-foreground"
                                >
                                    {{ reply.body }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-muted-foreground 2xl:hidden"
                                >
                                    {{
                                        reply.is_global
                                            ? 'Todos los departamentos'
                                            : reply.departments
                                                  .map(
                                                      (department) =>
                                                          department.name,
                                                  )
                                                  .join(', ')
                                    }}
                                </p>
                            </TableCell>
                            <TableCell class="hidden 2xl:table-cell">
                                <span
                                    v-if="reply.is_global"
                                    class="inline-flex items-center gap-1 text-xs"
                                >
                                    <Globe class="size-3" aria-hidden="true" />
                                    Todos los departamentos
                                </span>
                                <div v-else class="flex flex-wrap gap-1">
                                    <DepartmentTag
                                        v-for="department in reply.departments"
                                        :key="department.id"
                                        :name="department.name"
                                        :color="department.color"
                                        :muted="!department.is_active"
                                    />
                                </div>
                            </TableCell>
                            <TableCell class="hidden sm:table-cell">
                                <DeletedBadge
                                    v-if="reply.is_deleted"
                                    feminine
                                />
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >Activa</span
                                >
                            </TableCell>
                            <TableCell class="w-px pr-5 text-right">
                                <RecordActions
                                    :actions="actionsFor(reply)"
                                    :record="`la respuesta /${reply.shortcut}`"
                                    :busy="deletion.processingId === reply.id"
                                />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <RecordGrid v-else-if="quickReplies.data.length > 0">
                    <li v-for="reply in quickReplies.data" :key="reply.id">
                        <RecordCard :muted="reply.is_deleted">
                            <template #title>
                                <span class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="shrink-0 rounded-md bg-primary-soft px-1.5 py-0.5 font-mono text-xs font-medium text-primary-soft-foreground"
                                        >/{{ reply.shortcut }}</span
                                    >
                                    <span class="truncate">{{
                                        reply.title
                                    }}</span>
                                </span>
                            </template>
                            <template v-if="reply.is_deleted" #badges>
                                <DeletedBadge feminine />
                            </template>
                            <p
                                class="line-clamp-3 wrap-anywhere text-muted-foreground"
                            >
                                <WhatsAppText :text="reply.body" />
                            </p>
                            <template #meta>
                                <span
                                    v-if="reply.is_global"
                                    class="inline-flex items-center gap-1"
                                >
                                    <Globe class="size-3" aria-hidden="true" />
                                    Todos los departamentos
                                </span>
                                <DepartmentTag
                                    v-for="department in reply.departments"
                                    v-else
                                    :key="department.id"
                                    :name="department.name"
                                    :color="department.color"
                                    :muted="!department.is_active"
                                />
                            </template>
                            <template #actions>
                                <RecordActions
                                    :actions="actionsFor(reply)"
                                    :record="`la respuesta /${reply.shortcut}`"
                                    :busy="deletion.processingId === reply.id"
                                />
                            </template>
                        </RecordCard>
                    </li>
                </RecordGrid>

                <EmptyState
                    v-else
                    class="m-4 border-none"
                    :icon="MessageSquareText"
                    :title="
                        hasFilters
                            ? 'Ninguna respuesta coincide con los filtros'
                            : 'Aún no hay respuestas rápidas'
                    "
                    :description="
                        hasFilters
                            ? 'Prueba con otros criterios de búsqueda.'
                            : can.create
                              ? 'Crea la primera para que el equipo responda más rápido y con el mismo tono.'
                              : 'Cuando haya respuestas disponibles para ti aparecerán aquí.'
                    "
                >
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        @click="clearFilters"
                        >Limpiar filtros</Button
                    >
                    <Button v-else-if="can.create" @click="openCreate">
                        <Plus />
                        Nueva respuesta
                    </Button>
                </EmptyState>

                <div
                    v-if="
                        quickReplies.meta.last_page > 1 ||
                        quickReplies.data.length > 0
                    "
                    class="border-t px-4 py-3"
                >
                    <DataPagination
                        :meta="quickReplies.meta"
                        noun="respuestas"
                    />
                </div>
            </SurfaceCard>

            <SurfaceCard
                v-if="can.use"
                class="overflow-visible"
                title="Probar respuesta"
                description="Escribe «/» como lo harás en el chat para buscar e insertar una respuesta."
                body-class="p-4"
            >
                <QuickReplySandbox :variables="variables" />
            </SurfaceCard>
        </div>

        <QuickReplyFormDialog
            v-if="can.create"
            v-model:open="formOpen"
            :quick-reply="editingReply"
            :departments="assignableDepartments"
            :variables="variables"
            :sample-values="sampleValues"
            :body-max-length="bodyMaxLength"
            :can-manage-global="can.manageGlobal"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
