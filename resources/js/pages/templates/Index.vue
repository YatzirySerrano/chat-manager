<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    Clock,
    FileText,
    RefreshCw,
    Search,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TemplateStatusBadge from '@/components/templates/TemplateStatusBadge.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
                syncable.length > 0 ? 'split-layout [--aside-w:24rem]' : 'grid'
            "
        >
            <SurfaceCard>
                <div
                    class="flex flex-col gap-2 border-b p-4"
                    role="search"
                    data-tour="templates.search"
                >
                    <div class="relative min-w-0">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="filterState.search"
                            type="search"
                            placeholder="Buscar por nombre"
                            class="pl-9"
                            aria-label="Buscar plantillas"
                        />
                    </div>
                    <div
                        class="auto-grid items-center [--col-min:11rem] [--grid-gap:0.5rem]"
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
                            @change="applyFilters"
                        />
                        <Button
                            v-if="hasFilters"
                            variant="ghost"
                            class="justify-self-start"
                            @click="clearFilters"
                        >
                            <X />
                            Limpiar
                        </Button>
                    </div>
                </div>

                <Table
                    v-if="templates.data.length > 0"
                    data-tour="templates.table"
                >
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="pl-5">Plantilla</TableHead>
                            <TableHead class="hidden md:table-cell">
                                Conexión
                            </TableHead>
                            <TableHead class="hidden sm:table-cell">
                                Categoría
                            </TableHead>
                            <TableHead>Estado</TableHead>
                            <TableHead class="hidden lg:table-cell">
                                Contenido
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="template in templates.data"
                            :key="template.id"
                            :class="
                                template.is_deleted && 'text-muted-foreground'
                            "
                        >
                            <TableCell class="py-3 pl-5">
                                <Link
                                    :href="showTemplate(template.id)"
                                    prefetch
                                    class="font-medium underline-offset-4 hover:text-primary hover:underline"
                                >
                                    {{ template.name }}
                                </Link>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatLocale(template.language) }}
                                </p>
                            </TableCell>
                            <TableCell class="hidden md:table-cell">
                                {{ template.connection?.name }}
                            </TableCell>
                            <TableCell class="hidden sm:table-cell">
                                {{ template.category.label ?? '—' }}
                            </TableCell>
                            <TableCell>
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <DeletedBadge
                                        v-if="template.is_deleted"
                                        feminine
                                    />
                                    <TemplateStatusBadge
                                        v-else
                                        :status="template.status"
                                    />
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
                                </div>
                            </TableCell>
                            <TableCell class="hidden w-2/5 lg:table-cell">
                                <p
                                    class="line-clamp-2 text-xs whitespace-normal text-muted-foreground"
                                >
                                    {{ template.structure.body }}
                                </p>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <EmptyState
                    v-else
                    class="m-4 border-none"
                    :icon="FileText"
                    :title="
                        hasFilters
                            ? 'Ninguna plantilla coincide con los filtros'
                            : 'Aún no se han traído plantillas de Meta'
                    "
                    :description="
                        hasFilters
                            ? 'Prueba con otros criterios de búsqueda.'
                            : 'Crea las plantillas en WhatsApp Manager y sincronízalas con la conexión correspondiente.'
                    "
                >
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </Button>
                </EmptyState>

                <div
                    v-if="
                        templates.meta.last_page > 1 ||
                        templates.data.length > 0
                    "
                    class="border-t px-4 py-3"
                >
                    <DataPagination :meta="templates.meta" noun="plantillas" />
                </div>
            </SurfaceCard>

            <SurfaceCard
                v-if="syncable.length > 0"
                data-tour="templates.sync"
                title="Actualizar desde Meta"
                description="Trae de Meta las plantillas de cada conexión y su estado actual. También se actualizan solas cada 6 horas y cuando Meta avisa un cambio."
                body-class="divide-y"
            >
                <div
                    v-for="connection in syncable"
                    :key="connection.id"
                    class="row-accent flex flex-wrap items-center justify-between gap-3 px-5 py-3.5"
                >
                    <div class="min-w-0">
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
                        :disabled="syncing !== null"
                        :loading="syncing === connection.id"
                        :aria-label="`Actualizar desde Meta ${connection.name}`"
                        @click="sync(connection)"
                    >
                        <RefreshCw v-if="syncing !== connection.id" />
                        Actualizar
                    </Button>
                </div>
            </SurfaceCard>
        </div>
    </div>
</template>
