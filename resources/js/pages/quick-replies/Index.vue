<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Globe,
    Lock,
    MessageSquareText,
    Pencil,
    Plus,
    Search,
    UserRound,
    X,
} from '@lucide/vue';
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
import { Spinner } from '@/components/ui/spinner';
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
    QuickReplySegment,
    QuickReplySegmentOption,
    QuickReplySort,
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
    segments: QuickReplySegmentOption[];
    recordStates: RecordStateOption[];
    departments: DepartmentOption[];
    assignableDepartments: DepartmentOption[];
    /** Users with personal replies, for the supervision segment. */
    owners: { id: number; name: string }[];
    variables: QuickReplyVariableOption[];
    sampleValues: Record<string, string>;
    bodyMaxLength: number;
    can: {
        create: boolean;
        createPersonal: boolean;
        manageGlobal: boolean;
        use: boolean;
    };
    canExport?: boolean;
}>();

const ALL = 'all';

const SORT_OPTIONS: { value: QuickReplySort; label: string }[] = [
    { value: 'shortcut', label: 'Por atajo' },
    { value: 'title', label: 'Por título' },
    { value: 'recent', label: 'Editadas recientemente' },
];

const filterState = reactive({
    segment: props.filters.segment as QuickReplySegment,
    search: props.filters.search ?? '',
    department:
        props.filters.department === null
            ? ALL
            : String(props.filters.department),
    owner: props.filters.owner === null ? ALL : String(props.filters.owner),
    record: props.filters.record,
    availability: props.filters.availability ?? ALL,
    sort: props.filters.sort as QuickReplySort,
});

const isCorporate = computed(() => filterState.segment === 'corporate');
const isMine = computed(() => filterState.segment === 'mine');
const isOthers = computed(() => filterState.segment === 'others');

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.department !== ALL ||
        filterState.owner !== ALL ||
        filterState.record !== 'active' ||
        filterState.availability !== ALL,
);

/** Segment whose list is being loaded (shown on its button). */
const loadingSegment = ref<QuickReplySegment | null>(null);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.segment !== props.segments[0]?.value) {
        query.segment = filterState.segment;
    }

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    for (const key of ['department', 'availability', 'owner'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.record !== 'active') {
        query.record = filterState.record;
    }

    if (filterState.sort !== 'shortcut') {
        query.sort = filterState.sort;
    }

    router.get(quickRepliesIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['quickReplies', 'filters', 'segments', 'recordStates'],
        onFinish: () => {
            loadingSegment.value = null;
        },
    });
}

/**
 * Each segment has its own filters: switching keeps only the search and the
 * sort.
 */
function selectSegment(segment: QuickReplySegment): void {
    if (filterState.segment === segment) {
        return;
    }

    loadingSegment.value = segment;
    filterState.segment = segment;
    filterState.department = ALL;
    filterState.owner = ALL;
    filterState.availability = ALL;
    filterState.record = 'active';
    applyFilters();
}

function segmentHint(segment: QuickReplySegment): string {
    return {
        corporate:
            'Respuestas de la organización, para todos o por departamento.',
        mine: 'Solo tú las ves y las usas al escribir «/».',
        others: 'Respuestas personales de usuarios de tu alcance.',
    }[segment];
}

/** The owner keeps using personal replies but cannot change them anymore. */
const personalReadOnly = computed(
    () => isMine.value && !props.can.createPersonal,
);

const canCreateHere = computed(() =>
    isMine.value
        ? props.can.createPersonal
        : props.can.create || props.can.createPersonal,
);

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    filterState.search = '';
    filterState.department = ALL;
    filterState.owner = ALL;
    filterState.record = 'active';
    filterState.availability = ALL;
    applyFilters();
}

const emptyState = computed((): { title: string; description: string } => {
    if (hasFilters.value) {
        return {
            title: 'Ninguna respuesta coincide con los filtros',
            description: 'Prueba con otros criterios de búsqueda.',
        };
    }

    if (isMine.value) {
        return props.can.createPersonal
            ? {
                  title: 'Aún no tienes respuestas personales',
                  description:
                      'Crea textos propios para insertarlos con «/». Nadie más los verá ni los podrá usar.',
              }
            : {
                  title: 'No tienes respuestas personales',
                  description:
                      'Pide a un administrador el permiso para crearlas si lo necesitas.',
              };
    }

    if (isOthers.value) {
        return {
            title: 'Nadie de tu alcance tiene respuestas personales',
            description:
                'Cuando los usuarios de tu alcance creen respuestas personales podrás consultarlas aquí.',
        };
    }

    return {
        title: 'Aún no hay respuestas corporativas',
        description: props.can.create
            ? 'Crea la primera para que el equipo responda más rápido y con el mismo tono.'
            : 'Cuando haya respuestas disponibles para ti aparecerán aquí.',
    };
});

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

function availabilityLabel(reply: QuickReplySummary): string {
    if (reply.is_personal) {
        return reply.is_own
            ? 'Solo tú'
            : `Personal de ${reply.owner?.name ?? 'otro usuario'}`;
    }

    return reply.is_global
        ? 'Todos los departamentos'
        : reply.departments.map((department) => department.name).join(', ');
}

const deletion = useRecordDeletion<QuickReplySummary>({
    destroy: (reply) => destroyQuickReply(reply.id),
    restore: (reply) => restoreQuickReply(reply.id),
    copy: (reply, step) =>
        reply.is_personal
            ? step === 'delete'
                ? {
                      title: `¿Eliminar la respuesta personal /${reply.shortcut}?`,
                      description: reply.is_own
                          ? 'Dejará de aparecer al escribir «/» y podrás volver a usar su atajo. Los mensajes ya enviados no cambian.'
                          : `Es una respuesta personal de ${reply.owner?.name ?? 'otro usuario'}: dejará de aparecerle al escribir «/». Quedará registrado en la auditoría.`,
                  }
                : {
                      title: `¿Restaurar la respuesta personal /${reply.shortcut}?`,
                      description:
                          'Volverá a aparecer al escribir «/», solo para su propietario.',
                  }
            : step === 'delete'
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
            help="respuestas-rapidas"
            title="Respuestas rápidas"
            description="Textos predefinidos que se insertan en el chat escribiendo «/» y su atajo. Se insertan para revisarlos; nunca se envían solos."
        >
            <template v-if="canCreateHere || canExport" #actions>
                <ExportMenu
                    v-if="canExport"
                    report="quick-replies"
                    :filters="filters"
                />
                <Button v-if="canCreateHere" @click="openCreate">
                    <Plus />
                    {{
                        isMine ? 'Nueva respuesta personal' : 'Nueva respuesta'
                    }}
                </Button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <SurfaceCard>
                <div
                    v-if="segments.length > 1"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 pt-4 pb-3"
                >
                    <div
                        role="group"
                        aria-label="Tipo de respuestas"
                        :aria-busy="loadingSegment !== null"
                        class="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1"
                    >
                        <button
                            v-for="segment in segments"
                            :key="segment.value"
                            type="button"
                            :aria-pressed="
                                filterState.segment === segment.value
                            "
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                filterState.segment === segment.value &&
                                'bg-background text-foreground shadow-xs'
                            "
                            @click="selectSegment(segment.value)"
                        >
                            <Spinner
                                v-if="loadingSegment === segment.value"
                                class="size-3.5"
                            />
                            {{ segment.label }}
                            <span
                                class="rounded-full px-1.5 text-xs tabular-nums"
                                :class="
                                    filterState.segment === segment.value
                                        ? 'bg-muted'
                                        : 'bg-background'
                                "
                                >{{ segment.count }}</span
                            >
                        </button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ segmentHint(filterState.segment) }}
                    </p>
                </div>

                <p
                    v-if="personalReadOnly"
                    class="mx-4 mt-4 flex items-start gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                >
                    <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    Ya no tienes permiso para crear ni editar respuestas
                    personales. Puedes seguir usando las activas al escribir
                    «/».
                </p>

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
                            v-if="isOthers"
                            v-model="filterState.owner"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-44"
                                aria-label="Filtrar por usuario"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Todos los usuarios</SelectItem
                                >
                                <SelectItem
                                    v-for="owner in owners"
                                    :key="owner.id"
                                    :value="String(owner.id)"
                                >
                                    {{ owner.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            v-if="!isMine"
                            v-model="filterState.department"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-44"
                                :aria-label="
                                    isOthers
                                        ? 'Filtrar por departamento del usuario'
                                        : 'Filtrar por departamento'
                                "
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">{{
                                    isOthers
                                        ? 'Usuarios de todos los departamentos'
                                        : 'Todos los departamentos'
                                }}</SelectItem>
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
                            v-if="isCorporate"
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
                        <Select
                            v-model="filterState.sort"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-48"
                                aria-label="Ordenar respuestas"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in SORT_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
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
                                    {{ availabilityLabel(reply) }}
                                </p>
                            </TableCell>
                            <TableCell class="hidden 2xl:table-cell">
                                <span
                                    v-if="reply.is_personal"
                                    class="inline-flex items-center gap-1 text-xs"
                                >
                                    <component
                                        :is="reply.is_own ? Lock : UserRound"
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{ availabilityLabel(reply) }}
                                </span>
                                <span
                                    v-else-if="reply.is_global"
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
                                    v-if="reply.is_personal"
                                    class="inline-flex items-center gap-1"
                                >
                                    <component
                                        :is="reply.is_own ? Lock : UserRound"
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{ availabilityLabel(reply) }}
                                </span>
                                <span
                                    v-else-if="reply.is_global"
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
                    :title="emptyState.title"
                    :description="emptyState.description"
                >
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        @click="clearFilters"
                        >Limpiar filtros</Button
                    >
                    <Button
                        v-else-if="canCreateHere && !isOthers"
                        @click="openCreate"
                    >
                        <Plus />
                        {{
                            isMine
                                ? 'Nueva respuesta personal'
                                : 'Nueva respuesta'
                        }}
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
            v-model:open="formOpen"
            :quick-reply="editingReply"
            :departments="assignableDepartments"
            :variables="variables"
            :sample-values="sampleValues"
            :body-max-length="bodyMaxLength"
            :can-manage-global="can.manageGlobal"
            :can-create-corporate="can.create"
            :can-create-personal="can.createPersonal"
            :default-type="isMine || !can.create ? 'personal' : 'corporate'"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
