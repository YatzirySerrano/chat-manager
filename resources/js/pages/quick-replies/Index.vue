<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Globe,
    Lock,
    MessageSquareText,
    Pencil,
    Plus,
    Trash2,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import type { FilterTabOption } from '@/components/filters/types';
import PageHeader from '@/components/PageHeader.vue';
import QuickReplyExportMenu from '@/components/quick-replies/QuickReplyExportMenu.vue';
import QuickReplyFormDialog from '@/components/quick-replies/QuickReplyFormDialog.vue';
import QuickReplyListSkeleton from '@/components/quick-replies/QuickReplyListSkeleton.vue';
import QuickReplySandbox from '@/components/quick-replies/QuickReplySandbox.vue';
import '@/components/quick-replies/quick-replies.css';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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

/**
 * The skeleton replaces the list only when loading a tab takes long enough
 * to notice; a fast answer swaps the content directly (with a short fade).
 */
const SKELETON_DELAY_MS = 150;
const showSkeleton = ref(false);
let skeletonTimer: ReturnType<typeof setTimeout> | null = null;

/** Rows of the skeleton: about as many as the list had, so nothing jumps. */
const skeletonRows = ref(4);

/** Tab bar, kept in view when a switch makes the page shorter. */
const segmentBar = ref<HTMLElement | null>(null);

function stopSkeleton(): void {
    if (skeletonTimer !== null) {
        clearTimeout(skeletonTimer);
        skeletonTimer = null;
    }

    showSkeleton.value = false;
}

function prefersReducedMotion(): boolean {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Brings the tab bar back into view if the person had scrolled past it. */
function keepSegmentBarInView(): void {
    const bar = segmentBar.value;

    if (!bar || bar.getBoundingClientRect().top >= 0) {
        return;
    }

    bar.scrollIntoView({
        block: 'start',
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    });
}

onBeforeUnmount(stopSkeleton);

/** Any filter request on its way (the toolbar shows a progress line). */
const loading = ref(false);

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
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
            const switchedSegment = loadingSegment.value !== null;

            loadingSegment.value = null;
            stopSkeleton();

            if (switchedSegment) {
                keepSegmentBarInView();
            }
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
    skeletonRows.value = Math.min(
        Math.max(props.quickReplies.data.length, 3),
        8,
    );
    stopSkeleton();
    skeletonTimer = setTimeout(() => {
        showSkeleton.value = true;
    }, SKELETON_DELAY_MS);
    filterState.segment = segment;
    filterState.department = ALL;
    filterState.owner = ALL;
    filterState.availability = ALL;
    filterState.record = 'active';
    applyFilters();
}

const segmentTabs = computed<FilterTabOption<QuickReplySegment>[]>(() =>
    props.segments.map((segment) => ({
        value: segment.value,
        label: segment.label,
        count: segment.count,
        title: segmentHint(segment.value),
    })),
);

function segmentHint(segment: QuickReplySegment): string {
    return {
        corporate:
            'Respuestas de la organización, para todos o por departamento.',
        mine: 'Solo tú las ves y las usas al escribir «/».',
        others: 'Respuestas personales de los usuarios que supervisas.',
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
            title: 'Ninguno de los usuarios que supervisas tiene respuestas personales',
            description:
                'Cuando los usuarios que supervisas creen respuestas personales podrás consultarlas aquí.',
        };
    }

    return {
        title: 'Aún no hay respuestas corporativas',
        description: props.can.create
            ? 'Crea la primera para que el equipo responda más rápido y con el mismo tono.'
            : 'Cuando haya respuestas disponibles para ti aparecerán aquí.',
    };
});

const currentSegmentLabel = computed(
    () =>
        props.segments.find(
            (segment) => segment.value === props.filters.segment,
        )?.label ?? 'Respuestas rápidas',
);

/** Key of the list shown: a new tab fades its content in. */
const listKey = computed(() =>
    showSkeleton.value
        ? 'loading'
        : `${props.filters.segment}-${viewMode.value}`,
);

/** The tile of a reply says who may use it. */
function availabilityIcon(reply: QuickReplySummary) {
    if (reply.is_personal) {
        return reply.is_own ? Lock : UserRound;
    }

    return reply.is_global ? Globe : UsersRound;
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
                    description: `Nadie podrá usarla, y nadie podrá usar el atajo /${reply.shortcut} para otra respuesta mientras esté eliminada. Si la restauras, vuelve a funcionar. Los mensajes ya enviados no cambian.`,
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
        reply.can.purge && {
            key: 'purge',
            label: 'Eliminar definitivamente',
            icon: Trash2,
            destructive: true,
            onSelect: () => {
                purging.value = reply;
            },
        },
    );
}

/** «Eliminar definitivamente» of a record that is already deleted. */
const purging = ref<QuickReplySummary | null>(null);
const purgeOpen = computed({
    get: () => purging.value !== null,
    set: (open: boolean) => {
        if (!open) {
            purging.value = null;
        }
    },
});
</script>

<template>
    <Head title="Respuestas rápidas" />

    <div class="page-shell">
        <PageHeader
            help="respuestas-rapidas"
            title="Respuestas rápidas"
            description="Textos predefinidos que se insertan en el chat escribiendo «/» y su atajo. Se insertan para revisarlos; nunca se envían solos."
        >
            <template v-if="canCreateHere || canExport" #actions>
                <QuickReplyExportMenu
                    v-if="canExport"
                    :filters="filters"
                    :current-label="currentSegmentLabel"
                    :filtered="hasFilters"
                />
                <Button
                    v-if="canCreateHere"
                    data-tour="quick-replies.new"
                    @click="openCreate"
                >
                    <Plus />
                    {{
                        isMine ? 'Nueva respuesta personal' : 'Nueva respuesta'
                    }}
                </Button>
            </template>
        </PageHeader>

        <div class="split-layout reveal [--aside-w:24rem]">
            <DirectoryPanel
                :busy="loading && !showSkeleton"
                toolbar-class="grid gap-2"
            >
                <template #toolbar>
                    <div
                        ref="segmentBar"
                        class="flex scroll-mt-20 flex-wrap items-center gap-2"
                        data-tour="quick-replies.search"
                    >
                        <div
                            v-if="segments.length > 1"
                            data-tour="quick-replies.segments"
                            :aria-busy="loadingSegment !== null"
                            class="min-w-0 max-sm:w-full"
                        >
                            <FilterTabs
                                :model-value="filterState.segment"
                                :options="segmentTabs"
                                label="Tipo de respuestas"
                                @update:model-value="selectSegment"
                            />
                        </div>
                        <!--
                            The live region is the stable wrapper: a region
                            inserted fresh on every change is often not
                            announced. The hint is also each tab's title.
                        -->
                        <div
                            v-if="segments.length > 1"
                            aria-live="polite"
                            data-segment-hint
                            class="sr-only"
                        >
                            <p :key="filterState.segment">
                                {{ segmentHint(filterState.segment) }}
                            </p>
                        </div>
                        <FilterSearch
                            v-model="filterState.search"
                            label="Buscar respuestas rápidas"
                            placeholder="Buscar por título, atajo o texto"
                            :loading="loading"
                            class="max-sm:basis-full sm:min-w-48"
                        />
                        <ViewModeToggle v-model="viewMode" />
                    </div>

                    <ActiveFilters
                        :pills="[]"
                        :clearable="hasFilters"
                        @clear="clearFilters"
                    >
                        <div
                            class="qr-filters grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap"
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
                                    class="w-full sm:w-48"
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
                                    class="w-full sm:w-36"
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
                                class="sm:w-32"
                                @change="applyFilters"
                            />
                        </div>
                    </ActiveFilters>
                </template>

                <p
                    v-if="personalReadOnly"
                    class="mx-3 mt-3 flex items-start gap-2 rounded-xl border border-dashed bg-surface-sunken/50 p-3 text-sm text-muted-foreground sm:mx-4"
                >
                    <Lock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    Ya no tienes permiso para crear ni editar respuestas
                    personales. Puedes seguir usando las activas al escribir
                    «/».
                </p>

                <Transition
                    enter-active-class="transition-[opacity,translate] duration-200 ease-standard"
                    enter-from-class="translate-y-1 opacity-0"
                    leave-active-class="hidden"
                >
                    <div :key="listKey" :aria-busy="loadingSegment !== null">
                        <QuickReplyListSkeleton
                            v-if="showSkeleton"
                            :rows="skeletonRows"
                            :layout="viewMode"
                        />
                        <ul
                            v-else-if="
                                quickReplies.data.length > 0 &&
                                viewMode === 'table'
                            "
                            role="list"
                            class="dir-rows"
                        >
                            <DirectoryRow
                                v-for="reply in quickReplies.data"
                                :key="reply.id"
                                :muted="reply.is_deleted"
                            >
                                <template #leading>
                                    <DirectoryAvatar
                                        :name="reply.title"
                                        :icon="availabilityIcon(reply)"
                                        shape="square"
                                        :tone="
                                            reply.is_deleted ? 'muted' : 'auto'
                                        "
                                    />
                                </template>
                                <template #title>
                                    <span
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <span
                                            class="shrink-0 rounded-md bg-primary-soft px-1.5 py-0.5 font-mono text-xs font-medium text-primary-soft-foreground"
                                            >/{{ reply.shortcut }}</span
                                        >
                                        <span class="truncate">{{
                                            reply.title
                                        }}</span>
                                    </span>
                                </template>
                                <template #subtitle>
                                    {{ reply.body }}
                                </template>
                                <template #badges>
                                    <DeletedBadge
                                        v-if="reply.is_deleted"
                                        feminine
                                    />
                                    <span class="contents md:hidden">
                                        <span
                                            v-if="
                                                reply.is_personal ||
                                                reply.is_global
                                            "
                                            class="inline-flex max-w-full items-center gap-1 text-xs text-muted-foreground"
                                        >
                                            <component
                                                :is="availabilityIcon(reply)"
                                                class="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">{{
                                                availabilityLabel(reply)
                                            }}</span>
                                        </span>
                                        <span
                                            v-else
                                            class="flex min-w-0 flex-wrap justify-end gap-1"
                                        >
                                            <DepartmentTag
                                                v-for="department in reply.departments"
                                                :key="department.id"
                                                :name="department.name"
                                                :color="department.color"
                                                :muted="!department.is_active"
                                            />
                                        </span>
                                    </span>
                                </template>
                                <template #aside>
                                    <span
                                        class="flex w-44 justify-end text-right"
                                    >
                                        <span
                                            v-if="
                                                reply.is_personal ||
                                                reply.is_global
                                            "
                                            class="inline-flex max-w-full items-center gap-1 text-xs text-muted-foreground"
                                        >
                                            <component
                                                :is="availabilityIcon(reply)"
                                                class="size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">{{
                                                availabilityLabel(reply)
                                            }}</span>
                                        </span>
                                        <span
                                            v-else
                                            class="flex min-w-0 flex-wrap justify-end gap-1"
                                        >
                                            <DepartmentTag
                                                v-for="department in reply.departments"
                                                :key="department.id"
                                                :name="department.name"
                                                :color="department.color"
                                                :muted="!department.is_active"
                                            />
                                        </span>
                                    </span>
                                </template>
                                <template #actions>
                                    <RecordActions
                                        :actions="actionsFor(reply)"
                                        :record="`la respuesta /${reply.shortcut}`"
                                        :busy="
                                            deletion.processingId === reply.id
                                        "
                                    />
                                </template>
                            </DirectoryRow>
                        </ul>

                        <ul
                            v-else-if="quickReplies.data.length > 0"
                            role="list"
                            class="dir-grid"
                        >
                            <li
                                v-for="reply in quickReplies.data"
                                :key="reply.id"
                            >
                                <RecordCard
                                    :muted="reply.is_deleted"
                                    class="dir-card"
                                >
                                    <template #leading>
                                        <DirectoryAvatar
                                            :name="reply.title"
                                            :icon="availabilityIcon(reply)"
                                            shape="square"
                                            size="sm"
                                            :tone="
                                                reply.is_deleted
                                                    ? 'muted'
                                                    : 'auto'
                                            "
                                        />
                                    </template>
                                    <template #title>
                                        <span
                                            class="flex min-w-0 items-center gap-2"
                                        >
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
                                            v-if="
                                                reply.is_personal ||
                                                reply.is_global
                                            "
                                            class="inline-flex items-center gap-1"
                                        >
                                            <component
                                                :is="availabilityIcon(reply)"
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            {{ availabilityLabel(reply) }}
                                        </span>
                                        <template v-else>
                                            <DepartmentTag
                                                v-for="department in reply.departments"
                                                :key="department.id"
                                                :name="department.name"
                                                :color="department.color"
                                                :muted="!department.is_active"
                                            />
                                        </template>
                                    </template>
                                    <template #actions>
                                        <RecordActions
                                            :actions="actionsFor(reply)"
                                            :record="`la respuesta /${reply.shortcut}`"
                                            :busy="
                                                deletion.processingId ===
                                                reply.id
                                            "
                                        />
                                    </template>
                                </RecordCard>
                            </li>
                        </ul>

                        <FilteredEmpty
                            v-else
                            :narrowed="hasFilters"
                            narrowed-title="Ninguna respuesta coincide con los filtros"
                            narrowed-description="Quita algún filtro o cambia la búsqueda para ver más respuestas."
                            :title="emptyState.title"
                            :description="emptyState.description"
                            :icon="MessageSquareText"
                            @clear="clearFilters"
                        >
                            <Button
                                v-if="canCreateHere && !isOthers"
                                class="rounded-full"
                                @click="openCreate"
                            >
                                <Plus />
                                {{
                                    isMine
                                        ? 'Nueva respuesta personal'
                                        : 'Nueva respuesta'
                                }}
                            </Button>
                        </FilteredEmpty>
                    </div>
                </Transition>

                <template
                    v-if="
                        !showSkeleton &&
                        (quickReplies.meta.last_page > 1 ||
                            quickReplies.data.length > 0)
                    "
                    #footer
                >
                    <DataPagination
                        :meta="quickReplies.meta"
                        noun="respuestas"
                    />
                </template>
            </DirectoryPanel>

            <SurfaceCard
                v-if="can.use"
                class="overflow-visible xl:sticky xl:top-20"
                data-tour="quick-replies.sandbox"
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

        <PermanentDeleteDialog
            v-if="purging"
            v-model:open="purgeOpen"
            title="Eliminar respuesta rápida definitivamente"
            :description="`Vas a borrar para siempre la respuesta rápida /${purging.shortcut}.`"
            :erased="[
                'La respuesta rápida y su texto',
                'Su asignación a departamentos; el atajo queda libre',
            ]"
            :kept="[
                'Los mensajes que ya se enviaron con ella',
                'El registro en Auditoría',
            ]"
            :url="`/quick-replies/${purging.id}/permanent`"
        />
    </div>
</template>
