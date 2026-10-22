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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatRelative } from '@/lib/format';
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

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

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

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Plantillas de WhatsApp"
            description="Plantillas aprobadas por Meta para iniciar o retomar conversaciones fuera de la ventana de 24 horas. Se crean y editan en WhatsApp Manager; aquí se sincronizan y consultan."
        >
            <template v-if="canExport" #actions>
                <ExportMenu
                    report="message-templates"
                    :filters="{ ...filters, record: filters.record }"
                />
            </template>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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

        <SurfaceCard
            v-if="syncable.length > 0"
            title="Sincronización con Meta"
            description="Copia el estado actual de las plantillas de la cuenta de WhatsApp Business de cada conexión. También se sincronizan automáticamente cada 6 horas y al recibir cambios de estado por webhook."
            body-class="grid gap-2 p-4"
        >
            <div
                v-for="connection in syncable"
                :key="connection.id"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
            >
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">
                        {{ connection.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            connection.last_synced_at
                                ? `Última sincronización ${formatRelative(connection.last_synced_at)}`
                                : 'Nunca sincronizada'
                        }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="syncing !== null"
                    @click="sync(connection)"
                >
                    <Spinner v-if="syncing === connection.id" />
                    <RefreshCw v-else />
                    Sincronizar
                </Button>
            </div>
        </SurfaceCard>

        <SurfaceCard>
            <div
                class="grid gap-2 border-b p-4 sm:grid-cols-2 lg:grid-cols-6"
                role="search"
            >
                <div class="relative min-w-0 lg:col-span-2">
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
                <select
                    v-model="filterState.connection"
                    :class="selectClass"
                    aria-label="Conexión"
                    @change="applyFilters"
                >
                    <option value="">Todas las conexiones</option>
                    <option
                        v-for="connection in connections"
                        :key="connection.id"
                        :value="String(connection.id)"
                    >
                        {{ connection.name }}
                    </option>
                </select>
                <select
                    v-model="filterState.status"
                    :class="selectClass"
                    aria-label="Estado"
                    @change="applyFilters"
                >
                    <option value="">Todos los estados</option>
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
                <select
                    v-model="filterState.category"
                    :class="selectClass"
                    aria-label="Categoría"
                    @change="applyFilters"
                >
                    <option value="">Todas las categorías</option>
                    <option
                        v-for="category in categories"
                        :key="category.value"
                        :value="category.value"
                    >
                        {{ category.label }}
                    </option>
                </select>
                <select
                    v-model="filterState.language"
                    :class="selectClass"
                    aria-label="Idioma"
                    @change="applyFilters"
                >
                    <option value="">Todos los idiomas</option>
                    <option
                        v-for="language in languages"
                        :key="language"
                        :value="language"
                    >
                        {{ language }}
                    </option>
                </select>
                <div class="flex gap-2 lg:col-span-6">
                    <RecordStateFilter
                        v-if="recordStates.length > 1"
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

            <Table v-if="templates.data.length > 0">
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
                        :class="template.is_deleted && 'text-muted-foreground'"
                    >
                        <TableCell class="py-3 pl-5">
                            <Link
                                :href="showTemplate(template.id)"
                                class="font-medium hover:underline"
                            >
                                {{ template.name }}
                            </Link>
                            <p class="text-xs text-muted-foreground">
                                {{ template.language }}
                            </p>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ template.connection?.name }}
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            {{ template.category.label ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-1.5">
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
                                    Calidad {{ template.quality_score }}
                                </span>
                            </div>
                        </TableCell>
                        <TableCell class="hidden max-w-sm lg:table-cell">
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
                        : 'Aún no hay plantillas sincronizadas'
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
                v-if="templates.meta.last_page > 1 || templates.data.length > 0"
                class="border-t px-4 py-3"
            >
                <DataPagination :meta="templates.meta" noun="plantillas" />
            </div>
        </SurfaceCard>
    </div>
</template>
