<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ChevronRight,
    CircleCheck,
    Clock,
    FileText,
    RefreshCw,
    TriangleAlert,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TemplateStatusBadge from '@/components/templates/TemplateStatusBadge.vue';
import '@/components/templates/templates.css';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import {
    formatLocale,
    formatRelative,
    formatTemplateQuality,
} from '@/lib/format';
import {
    index as templatesIndex,
    show as showTemplate,
    sync as syncTemplates,
} from '@/routes/templates';
import type {
    MessageTemplateFilters,
    MessageTemplateStatusValue,
    MessageTemplateSummary,
    Paginated,
    RecordStateOption,
    TemplateConnectionOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Plantillas de WhatsApp', href: templatesIndex() },
        ],
    },
});

const props = defineProps<{
    templates: Paginated<MessageTemplateSummary>;
    filters: MessageTemplateFilters;
    recordStates: RecordStateOption[];
    statuses: { value: MessageTemplateStatusValue; label: string }[];
    categories: { value: string; label: string }[];
    languages: string[];
    connections: TemplateConnectionOption[];
    stats: {
        total: number;
        approved: number;
        pending: number;
        attention: number;
    };
    canExport?: boolean;
}>();

const filterState = reactive({
    search: props.filters.search ?? '',
    connection: props.filters.connection
        ? String(props.filters.connection)
        : '',
    status: props.filters.status ?? '',
    category: props.filters.category ?? '',
    language: props.filters.language ?? '',
    record: props.filters.record,
});

const connectionOptions = computed(() => [
    { value: '', label: 'Todas las conexiones' },
    ...props.connections.map((connection) => ({
        value: String(connection.id),
        label: connection.name,
    })),
]);
const statusOptions = computed(() => [
    { value: '', label: 'Todos los estados' },
    ...props.statuses.map((status) => ({
        value: status.value as string,
        label: status.label,
    })),
]);
const categoryOptions = computed(() => [
    { value: '', label: 'Todas las categorías' },
    ...props.categories,
]);
const languageOptions = computed(() => [
    { value: '', label: 'Todos los idiomas' },
    ...props.languages.map((language) => ({
        value: language,
        label: formatLocale(language),
    })),
]);

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.connection !== '' ||
        filterState.status !== '' ||
        filterState.category !== '' ||
        filterState.language !== '' ||
        filterState.record !== 'active',
);

/** A filter request on its way (the toolbar shows a progress line). */
const loading = ref(false);

function applyFilters(): void {
    const query: Record<string, string> = {};

    for (const key of [
        'connection',
        'status',
        'category',
        'language',
    ] as const) {
        if (filterState[key] !== '') {
            query[key] = filterState[key];
        }
    }

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    if (filterState.record !== 'active') {
        query.record = filterState.record;
    }

    router.get(templatesIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['templates', 'filters'],
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    Object.assign(filterState, {
        search: '',
        connection: '',
        status: '',
        category: '',
        language: '',
        record: 'active',
    });
    applyFilters();
}

const syncing = ref<number | null>(null);
const syncable = computed(() =>
    props.connections.filter((connection) => connection.can_sync),
);

function sync(connection: TemplateConnectionOption): void {
    router.post(syncTemplates.url(connection.id), undefined, {
        preserveScroll: true,
        onStart: () => (syncing.value = connection.id),
        onFinish: () => (syncing.value = null),
    });
}
</script>

<template>
    <Head title="Plantillas de WhatsApp" />

    <div class="page-shell">
        <PageHeader
            help="plantillas"
            title="Plantillas de WhatsApp"
            description="Plantillas aprobadas por Meta para iniciar o retomar conversaciones fuera de la ventana de 24 horas. Se crean y editan en WhatsApp Manager; aquí se consultan y se actualizan desde Meta."
        >
            <template v-if="canExport" #actions>
                <ExportMenu
                    report="message-templates"
                    :filters="{ ...filters, record: filters.record }"
                />
            </template>
        </PageHeader>

        <div
            class="grid reveal-stagger gap-3 sm:grid-cols-2 xl:grid-cols-4"
            data-tour="templates.stats"
        >
            <StatCard
                label="Plantillas"
                :value="stats.total"
                :icon="FileText"
            />
            <StatCard
                label="Aprobadas por Meta"
                :value="stats.approved"
                :icon="CircleCheck"
            />
            <StatCard
                label="En revisión"
                :value="stats.pending"
                :icon="Clock"
            />
            <StatCard
                label="Requieren atención"
                :value="stats.attention"
                :icon="TriangleAlert"
                hint="Rechazadas, pausadas o deshabilitadas"
            />
        </div>

        <div
            :class="
                syncable.length > 0
                    ? 'split-layout reveal [--aside-w:24rem]'
                    : 'grid reveal'
            "
        >
            <DirectoryPanel :busy="loading" toolbar-class="max-sm:static">
                <template #toolbar>
                    <div class="grid gap-3" data-tour="templates.search">
                        <FilterSearch
                            v-model="filterState.search"
                            label="Buscar plantillas"
                            placeholder="Buscar por nombre"
                            :loading="loading"
                        />
                        <ActiveFilters
                            :pills="[]"
                            :clearable="hasFilters"
                            @clear="clearFilters"
                        >
                            <div
                                class="tpl-filters grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap"
                            >
                                <AppSelect
                                    v-model="filterState.connection"
                                    :options="connectionOptions"
                                    aria-label="Conexión"
                                    data-filter="connection"
                                    @change="applyFilters"
                                />
                                <AppSelect
                                    v-model="filterState.status"
                                    :options="statusOptions"
                                    aria-label="Estado"
                                    data-filter="status"
                                    @change="applyFilters"
                                />
                                <AppSelect
                                    v-model="filterState.category"
                                    :options="categoryOptions"
                                    aria-label="Categoría"
                                    data-filter="category"
                                    @change="applyFilters"
                                />
                                <AppSelect
                                    v-model="filterState.language"
                                    :options="languageOptions"
                                    aria-label="Idioma"
                                    data-filter="language"
                                    @change="applyFilters"
                                />
                                <RecordStateFilter
                                    v-if="recordStates.length > 1"
                                    v-model="filterState.record"
                                    :options="recordStates"
                                    class="sm:w-36"
                                    @change="applyFilters"
                                />
                            </div>
                        </ActiveFilters>
                    </div>
                </template>

                <ul
                    v-if="templates.data.length > 0"
                    role="list"
                    class="dir-rows"
                    data-tour="templates.table"
                >
                    <DirectoryRow
                        v-for="template in templates.data"
                        :key="template.id"
                        :href="showTemplate(template.id)"
                        :label="`Ver plantilla ${template.name}`"
                        :muted="template.is_deleted"
                    >
                        <template #leading>
                            <DirectoryAvatar
                                :name="template.name"
                                :icon="FileText"
                                shape="square"
                                :tone="template.is_deleted ? 'muted' : 'auto'"
                            />
                        </template>
                        <template #title>{{ template.name }}</template>
                        <template #subtitle>
                            {{
                                [
                                    formatLocale(template.language),
                                    template.connection?.name,
                                ]
                                    .filter(Boolean)
                                    .join(', ')
                            }}
                        </template>
                        <template #badges>
                            <DeletedBadge v-if="template.is_deleted" feminine />
                            <TemplateStatusBadge
                                v-else
                                :status="template.status"
                            />
                            <span
                                v-if="template.category.label"
                                class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground"
                                >{{ template.category.label }}</span
                            >
                            <span
                                v-if="template.quality_score"
                                class="text-xs text-muted-foreground"
                            >
                                Calidad
                                {{
                                    formatTemplateQuality(
                                        template.quality_score,
                                    )
                                }}
                            </span>
                        </template>
                        <template v-if="template.structure.body" #aside>
                            <p
                                class="line-clamp-2 w-56 text-xs leading-relaxed text-pretty lg:w-72 xl:w-80"
                            >
                                {{ template.structure.body }}
                            </p>
                        </template>
                        <template #actions>
                            <ChevronRight
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </template>
                    </DirectoryRow>
                </ul>

                <FilteredEmpty
                    v-else
                    :narrowed="hasFilters"
                    narrowed-title="Ninguna plantilla coincide con los filtros"
                    narrowed-description="Quita algún filtro o cambia la búsqueda para ver más plantillas."
                    title="Aún no se han traído plantillas de Meta"
                    description="Crea las plantillas en WhatsApp Manager y sincronízalas con la conexión correspondiente."
                    :icon="FileText"
                    @clear="clearFilters"
                />

                <template
                    v-if="
                        templates.meta.last_page > 1 ||
                        templates.data.length > 0
                    "
                    #footer
                >
                    <DataPagination :meta="templates.meta" noun="plantillas" />
                </template>
            </DirectoryPanel>

            <SurfaceCard
                v-if="syncable.length > 0"
                class="self-start xl:sticky xl:top-20"
                data-tour="templates.sync"
                title="Actualizar desde Meta"
                description="Trae de Meta las plantillas de cada conexión y su estado actual. También se actualizan solas cada 6 horas y cuando Meta avisa un cambio."
            >
                <ul role="list" class="dir-rows">
                    <li
                        v-for="connection in syncable"
                        :key="connection.id"
                        class="dir-row flex flex-wrap items-center gap-3 py-2.5 pr-3 pl-3.5"
                    >
                        <DirectoryAvatar
                            :name="connection.name"
                            :icon="RefreshCw"
                            shape="square"
                            size="sm"
                            :class="
                                syncing === connection.id &&
                                '[&_svg]:animate-spin'
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ connection.name }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    connection.last_synced_at
                                        ? `Actualizada ${formatRelative(connection.last_synced_at)}`
                                        : 'Aún no se ha actualizado desde Meta'
                                }}
                            </p>
                        </div>
                        <Button
                            variant="outline"
                            size="sm"
                            class="rounded-full"
                            :disabled="syncing !== null"
                            :loading="syncing === connection.id"
                            :aria-label="`Actualizar desde Meta ${connection.name}`"
                            @click="sync(connection)"
                        >
                            <RefreshCw v-if="syncing !== connection.id" />
                            Actualizar
                        </Button>
                    </li>
                </ul>
            </SurfaceCard>
        </div>
    </div>
</template>
