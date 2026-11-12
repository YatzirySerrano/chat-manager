<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';
import {
    ArrowLeftRight,
    CheckCircle2,
    CircleCheck,
    Clock3,
    Hourglass,
    Inbox,
    MessageCircleReply,
    MoonStar,
    RotateCcw,
    Send,
    Target,
    Timer,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import BacklogChart from '@/components/charts/BacklogChart.vue';
import BarChart from '@/components/charts/BarChart.vue';
import ChannelChart from '@/components/charts/ChannelChart.vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import LineChart from '@/components/charts/LineChart.vue';
import RankedBarsChart from '@/components/charts/RankedBarsChart.vue';
import ResponseTrendChart from '@/components/charts/ResponseTrendChart.vue';
import SlaComplianceChart from '@/components/charts/SlaComplianceChart.vue';
import StatusChart from '@/components/charts/StatusChart.vue';
import type {
    BarItem,
    BarSeries,
    ChartTableColumn,
    LineSeries,
} from '@/components/charts/types';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AgentEvolutionChart from '@/components/reports/AgentEvolutionChart.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import BreakdownTable from '@/components/reports/BreakdownTable.vue';
import GranularityToggle from '@/components/reports/GranularityToggle.vue';
import HourHeatmap from '@/components/reports/HourHeatmap.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import ProductivityTable from '@/components/reports/ProductivityTable.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import StatCard from '@/components/StatCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import {
    chartTokens,
    formatBucket,
    formatNumber,
    granularityNoun,
} from '@/composables/useChartTheme';
import { useFeatures } from '@/composables/useFeatures';
import { usePermissions } from '@/composables/usePermissions';
import { inboxLink } from '@/lib/analyticsLinks';
import { formatDuration } from '@/lib/format';
import { index as reportsIndex } from '@/routes/reports';
import type {
    AgentProductivityRow,
    AnalyticsBreakdownRow,
    AnalyticsDuration,
    AnalyticsGranularity,
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
    AnalyticsRange,
    AnalyticsSla,
    OperationalAnalytics,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reportes', href: reportsIndex() }],
    },
});

const props = defineProps<{
    filters: AnalyticsQuery;
    period: AnalyticsPeriod;
    invalidFilters: string | null;
    presets: { value: AnalyticsPreset; label: string }[];
    maxDays: number;
    scope: 'all' | 'departments' | 'own';
    options: AnalyticsOptions;
    analytics?: OperationalAnalytics;
    canExport: boolean;
    canExportProductivity?: boolean;
    canExportSla?: boolean;
}>();

const { can } = usePermissions();
const canOpenInbox = computed(() => can('conversations.view'));

const loading = ref(false);
const removeStart = router.on('start', (event) => {
    if (!event.detail.visit.async) {
        loading.value = true;
    }
});
const removeFinish = router.on('finish', () => {
    loading.value = false;
});

onUnmounted(() => {
    removeStart();
    removeFinish();
});

function applyFilters(query: AnalyticsQuery): void {
    router.get(reportsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['filters', 'period', 'invalidFilters', 'analytics'],
    });
}

function changeGranularity(granularity: AnalyticsGranularity): void {
    applyFilters({ ...props.filters, granularity });
}

/* ---------- Views of the page (kept in the URL hash, so a link opens the same view) */

const allViews = [
    { key: 'resumen', label: 'Resumen' },
    { key: 'productividad', label: 'Productividad' },
    { key: 'sla', label: 'Cumplimiento de SLA' },
    { key: 'desgloses', label: 'Desgloses' },
] as const;

type ViewKey = (typeof allViews)[number]['key'];

/* Productivity and SLA compliance are phase two: the server sends neither while it is off. */
const { phaseTwo } = useFeatures();

const views = computed(() =>
    allViews.filter(
        (item) =>
            phaseTwo.value ||
            (item.key !== 'productividad' && item.key !== 'sla'),
    ),
);

const view = ref<ViewKey>('resumen');

function selectView(key: ViewKey): void {
    view.value = key;

    if (typeof window !== 'undefined') {
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${window.location.search}#${key}`,
        );
    }
}

function onTabKeydown(event: KeyboardEvent, index: number): void {
    const step =
        event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

    if (step === 0) {
        return;
    }

    event.preventDefault();
    const next =
        views.value[(index + step + views.value.length) % views.value.length];

    if (next) {
        selectView(next.key);
        document.getElementById(`reports-tab-${next.key}`)?.focus();
    }
}

onMounted(() => {
    const hash = window.location.hash.replace('#', '');
    const match = views.value.find((item) => item.key === hash);

    if (match) {
        view.value = match.key;
    }
});

const exportTarget = computed(() => {
    if (view.value === 'productividad' && props.canExportProductivity) {
        return {
            report: 'agent-productivity',
            label: 'Exportar productividad',
        };
    }

    if (view.value === 'sla' && props.canExportSla) {
        return { report: 'sla-compliance', label: 'Exportar cumplimiento' };
    }

    return props.canExport
        ? { report: 'analytics', label: 'Exportar reporte' }
        : null;
});

const scopeDescription = computed(
    () =>
        ({
            all: 'Indicadores de toda la operación.',
            departments:
                'Indicadores de tus departamentos y de las conversaciones que atiendes.',
            own: 'Tus propios indicadores de atención.',
        })[props.scope],
);

const statusFiltered = computed(() => props.filters.status !== undefined);

const comparisonText = computed(() =>
    props.period.comparison.partial
        ? `el mismo tramo transcurrido del periodo anterior (${props.period.comparison.label})`
        : `el periodo anterior (${props.period.comparison.label})`,
);

function duration(figure: AnalyticsDuration): string | null {
    return figure.value === null ? null : formatDuration(figure.value);
}

function count(value: number | null): string | null {
    return value === null ? null : value.toLocaleString('es-MX');
}

function share(part: number | null, total: number | null): string | undefined {
    if (part === null || total === null || total === 0) {
        return undefined;
    }

    return `${Math.round((part / total) * 100)} % de las recibidas`;
}

function samples(figure: AnalyticsDuration): string {
    return figure.samples === 0
        ? 'Aún no hay solicitudes suficientes para calcularlo'
        : `Promedio de ${figure.samples.toLocaleString('es-MX')} ${figure.samples === 1 ? 'solicitud medida' : 'solicitudes medidas'} (n)`;
}

function range(value: AnalyticsRange): string {
    return value.min === null || value.max === null
        ? ''
        : ` Mínimo ${formatDuration(value.min)} · máximo ${formatDuration(value.max)}.`;
}

function previousDuration(figure: AnalyticsDuration): string | null {
    return figure.previous === null ? null : formatDuration(figure.previous);
}

function slaHint(sla: AnalyticsSla, pending: string): string {
    const target = formatDuration(sla.target_seconds);
    const base =
        sla.samples === 0
            ? `Objetivo general de hoy: ${target} en horario de atención. Ninguna solicitud medida aún`
            : `${sla.met.toLocaleString('es-MX')} de ${sla.samples.toLocaleString('es-MX')} dentro de ${target} en horario de atención (n = ${sla.samples.toLocaleString('es-MX')})`;

    return `${base}. Solo referencia: usa el objetivo general vigente hoy y no cuenta las que siguen sin ${pending}.`;
}

/* ---------- Time cards (one definition each, calendar and business time) */

type TimeCard = {
    key: string;
    label: string;
    icon: LucideIcon;
    figure: AnalyticsDuration;
    hint: string;
};

const calendarTimes = computed<TimeCard[]>(() => {
    const summary = props.analytics?.summary;

    if (!summary) {
        return [];
    }

    return [
        {
            key: 'first_attention',
            label: 'Espera hasta la primera atención',
            icon: Timer,
            figure: summary.first_attention_seconds,
            hint: `Desde que llega hasta que una persona la acepta, la asigna o responde (la asignación automática no cuenta). ${samples(summary.first_attention_seconds)}`,
        },
        {
            key: 'first_response',
            label: 'Espera hasta la primera respuesta',
            icon: MessageCircleReply,
            figure: summary.first_response_seconds,
            hint: `Desde que llega hasta el primer mensaje escrito por una persona (sin automáticos ni fallidos). ${samples(summary.first_response_seconds)}.${range(summary.first_response_range)}`,
        },
        {
            key: 'resolution',
            label: 'Duración total hasta la resolución',
            icon: CheckCircle2,
            figure: summary.resolution_seconds,
            hint: `Desde que llega hasta su primer cierre. ${samples(summary.resolution_seconds)}.${range(summary.resolution_range)}`,
        },
    ];
});

const businessTimes = computed<TimeCard[]>(() => {
    const summary = props.analytics?.summary;

    if (!summary) {
        return [];
    }

    return [
        {
            key: 'first_response_business',
            label: 'Espera hasta la primera respuesta',
            icon: MessageCircleReply,
            figure: summary.first_response_business_seconds,
            hint: samples(summary.first_response_business_seconds),
        },
        {
            key: 'resolution_business',
            label: 'Duración total hasta la resolución',
            icon: CheckCircle2,
            figure: summary.resolution_business_seconds,
            hint: samples(summary.resolution_business_seconds),
        },
    ];
});

/* ---------- Trend: received vs. previous period, and closed in the period */

const trend = computed(() => props.analytics?.trend ?? null);

const trendLabels = computed(() =>
    (trend.value?.points ?? []).map((point) => ({
        short: point.label,
        long: formatBucket(point.key, trend.value?.granularity ?? 'day').long,
    })),
);

const trendSeries = computed<LineSeries[]>(() => {
    const points = trend.value?.points ?? [];

    return [
        {
            key: 'received',
            name: 'Recibidas',
            values: points.map((point) => point.received),
            color: chartTokens.primary,
            area: true,
        },
        {
            key: 'resolved',
            name: 'Finalizadas en el periodo',
            values: points.map((point) => point.resolved),
            color: chartTokens.secondary,
        },
        {
            key: 'previous_received',
            name: 'Recibidas en el periodo anterior',
            values: points.map((point) => point.previous_received),
            color: chartTokens.comparison,
            dashed: true,
        },
    ];
});

const trendEmpty = computed(() =>
    (trend.value?.points ?? []).every(
        (point) =>
            point.received === 0 &&
            point.resolved === 0 &&
            point.previous_received === 0,
    ),
);

const trendLegend = computed(() =>
    trendSeries.value.map((series) => ({
        key: series.key,
        label: series.name,
        color: series.color ?? chartTokens.primary,
        shape: series.dashed ? ('dashed' as const) : ('line' as const),
    })),
);

const trendRows = computed(() =>
    (trend.value?.points ?? []).map((point, index) => ({
        ...point,
        period: trendLabels.value[index]?.long ?? point.label,
    })),
);

const trendColumns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    { key: 'received', label: 'Recibidas', align: 'right' },
    {
        key: 'resolved',
        label: 'Finalizadas en el periodo (primer cierre)',
        align: 'right',
    },
    {
        key: 'previous_received',
        label: 'Recibidas (periodo anterior)',
        align: 'right',
    },
];

const responseTotal = computed(() => {
    const figure = props.analytics?.summary.first_response_seconds;

    if (!figure || figure.value === null) {
        return undefined;
    }

    const current = `Promedio ${formatDuration(figure.value)} (n = ${formatNumber(figure.samples)})`;

    return figure.previous === null
        ? current
        : `${current} · antes ${formatDuration(figure.previous)}`;
});

/* ---------- Productivity */

const productivity = computed(() => props.analytics?.productivity ?? null);

const productivitySeries: BarSeries[] = [
    { key: 'attended', name: 'Atendidas', color: chartTokens.primary },
    {
        key: 'closes',
        name: 'Finalizadas (cierres)',
        color: chartTokens.secondary,
    },
];

const BAR_LIMIT = 10;

const productivityItems = computed<BarItem[]>(() =>
    (productivity.value?.rows ?? []).slice(0, BAR_LIMIT).map((row, index) => ({
        key: row.id === null ? `hidden-${index}` : String(row.id),
        label: row.name,
        values: [row.attended, row.closes],
    })),
);

const productivityColumns: ChartTableColumn[] = [
    { key: 'name', label: 'Colaborador' },
    { key: 'attended', label: 'Atendidas', align: 'right' },
    { key: 'closes', label: 'Finalizadas (cierres)', align: 'right' },
];

function pendingHref(row: AgentProductivityRow): string | undefined {
    return canOpenInbox.value && row.id !== null
        ? inboxLink(props.filters, { agent: row.id, status: 'active' })
        : undefined;
}

/* ---------- Service level */

const sla = computed(() => props.analytics?.sla ?? null);

const slaTrendLabels = computed(() =>
    (sla.value?.trend.points ?? []).map((point) => ({
        short: point.label,
        long: formatBucket(point.key, sla.value?.trend.granularity ?? 'day')
            .long,
    })),
);

const slaTrendSeries = computed<LineSeries[]>(() => [
    {
        key: 'rate',
        name: 'Cumplimiento (%)',
        values: (sla.value?.trend.points ?? []).map((point) => point.rate),
        color: chartTokens.primary,
    },
]);

const slaTrendRows = computed(() =>
    (sla.value?.trend.points ?? []).map((point, index) => ({
        ...point,
        period: slaTrendLabels.value[index]?.long ?? point.label,
        samples: point.met + point.breached,
    })),
);

const percent = (value: unknown): string =>
    typeof value === 'number' ? `${formatNumber(value)} %` : 'Sin datos';

const slaTrendColumns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    { key: 'met', label: 'Dentro del plazo', align: 'right' },
    { key: 'breached', label: 'Fuera del plazo', align: 'right' },
    { key: 'samples', label: 'Evaluados (n)', align: 'right' },
    { key: 'rate', label: 'Cumplimiento', align: 'right', format: percent },
];

const delaySeries: BarSeries[] = [
    { key: 'breached', name: 'Fuera del plazo', color: 'var(--destructive)' },
];

function delayItems(
    rows: { id: number | null; name: string; breached: number }[],
): BarItem[] {
    return rows.slice(0, BAR_LIMIT).map((row, index) => ({
        key: row.id === null ? `none-${index}` : String(row.id),
        label: row.name,
        values: [row.breached],
        muted: row.id === null,
    }));
}

function delayColumns(dimension: string): ChartTableColumn[] {
    return [
        { key: 'name', label: dimension },
        { key: 'breached', label: 'Fuera del plazo', align: 'right' },
        { key: 'met', label: 'Dentro del plazo', align: 'right' },
        { key: 'in_progress', label: 'En curso', align: 'right' },
        { key: 'samples', label: 'Evaluados (n)', align: 'right' },
        { key: 'rate', label: 'Cumplimiento', align: 'right', format: percent },
        // Two units, never one average: each objective counts its own time.
        {
            key: 'exceeded_business_seconds',
            label: 'Exceso promedio (tiempo hábil)',
            align: 'right',
            format: (value) =>
                typeof value === 'number' ? formatDuration(value) : '—',
        },
        {
            key: 'exceeded_calendar_seconds',
            label: 'Exceso promedio (tiempo corrido)',
            align: 'right',
            format: (value) =>
                typeof value === 'number' ? formatDuration(value) : '—',
        },
    ];
}

const slaEmptyText = computed(() =>
    sla.value?.available_since === null
        ? 'El seguimiento de SLA comienza con esta versión: aún no hay registros.'
        : 'Sin ciclos de respuesta evaluados en este periodo.',
);

/* ---------- Breakdowns: top 10 bars above the complete table */

const breakdownSeries: BarSeries[] = [
    { key: 'received', name: 'Recibidas', color: chartTokens.primary },
    { key: 'attended', name: 'Atendidas', color: chartTokens.secondary },
];

const breakdownLegend = breakdownSeries.map((series) => ({
    key: series.key,
    label: series.name,
    color: series.color ?? chartTokens.primary,
    shape: 'square' as const,
}));

function breakdownBars(rows: AnalyticsBreakdownRow[]): BarItem[] {
    return rows.slice(0, BAR_LIMIT).map((row, index) => ({
        key: row.id === null ? `none-${index}` : String(row.id),
        label: row.name,
        values: [row.received, row.attended],
        muted: row.id === null,
    }));
}

function showsBars(rows: AnalyticsBreakdownRow[]): boolean {
    return rows.length > 1 && rows.some((row) => row.received > 0);
}

function barsNote(rows: AnalyticsBreakdownRow[]): string {
    return rows.length > BAR_LIMIT
        ? `Los ${BAR_LIMIT} con más solicitudes recibidas; la tabla muestra los ${formatNumber(rows.length)}.`
        : 'Recibidas y atendidas en el periodo.';
}

const breakdowns = computed(() => {
    const analytics = props.analytics;

    if (!analytics) {
        return [];
    }

    return [
        {
            key: 'departments',
            dimension: 'Departamento',
            title: 'Comparativo por departamento',
            description: 'Departamento actual de cada solicitud',
            rows: analytics.by_department,
            showLoad: true,
            visible: props.scope !== 'own',
        },
        {
            key: 'categories',
            dimension: 'Categoría',
            title: 'Por categoría',
            description: 'Categoría actual de cada solicitud',
            rows: analytics.by_category,
            showLoad: false,
            visible: true,
        },
        {
            key: 'connections',
            dimension: 'Conexión',
            title: 'Por conexión',
            description: 'Número de WhatsApp o Chat web por el que llegó',
            rows: analytics.by_connection,
            showLoad: false,
            visible: true,
        },
        {
            key: 'agents',
            dimension: 'Responsable actual',
            title:
                props.scope === 'own'
                    ? 'Tu cartera'
                    : 'Cartera por responsable actual',
            description:
                (analytics.current.agents === null
                    ? ''
                    : `${analytics.current.agents} ${analytics.current.agents === 1 ? 'agente activo' : 'agentes activos'} · `) +
                'Solicitudes del periodo según quién es hoy (o fue al cerrar) su responsable. No mide el trabajo de cada persona: eso está en «Productividad».',
            rows: analytics.by_agent,
            showLoad: true,
            visible: true,
        },
    ].filter((breakdown) => breakdown.visible);
});

const loadingCards = [
    { key: 'trend', title: 'Volumen de solicitudes', shape: 'line' },
    {
        key: 'first-response',
        title: '¿Estamos respondiendo más rápido?',
        shape: 'line',
    },
    { key: 'backlog', title: 'Acumuladas sin atender', shape: 'line' },
    { key: 'statuses', title: 'Estado de lo recibido', shape: 'bars' },
] as const;
</script>

<template>
    <Head title="Reportes" />

    <div class="page-shell">
        <PageHeader
            help="reportes"
            title="Reportes operativos"
            :description="scopeDescription"
        >
            <template v-if="exportTarget" #actions>
                <span data-tour="reports.export" class="inline-flex">
                    <ExportMenu
                        :key="exportTarget.report"
                        :report="exportTarget.report"
                        :filters="filters"
                        :label="exportTarget.label"
                        heading="Exportar con el periodo y filtros actuales"
                    />
                </span>
            </template>
        </PageHeader>

        <AnalyticsFilterBar
            data-tour="reports.filters"
            :query="filters"
            :period="period"
            :presets="presets"
            :options="options"
            :max-days="maxDays"
            @change="applyFilters"
        />

        <StatusNote v-if="invalidFilters" tone="warning">
            No se pudieron aplicar los filtros ({{ invalidFilters }}). Se
            muestran los últimos 30 días.
        </StatusNote>

        <div
            class="flex flex-wrap gap-1 rounded-lg border bg-card p-1"
            role="tablist"
            aria-label="Vistas del reporte"
            data-reports-tabs
        >
            <button
                v-for="(item, index) in views"
                :id="`reports-tab-${item.key}`"
                :key="item.key"
                type="button"
                role="tab"
                class="shrink-0 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :class="
                    view === item.key
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                :aria-selected="view === item.key"
                :aria-controls="`reports-panel-${item.key}`"
                :tabindex="view === item.key ? 0 : -1"
                :data-view="item.key"
                @click="selectView(item.key)"
                @keydown="onTabKeydown($event, index)"
            >
                {{ item.label }}
            </button>
        </div>

        <Deferred data="analytics">
            <template #fallback>
                <div class="flex flex-col gap-(--section-gap)" aria-busy="true">
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        aria-label="Cargando reportes"
                    >
                        <StatCard
                            v-for="index in 6"
                            :key="index"
                            label="Cargando…"
                            :value="null"
                            loading
                        />
                    </div>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <ChartCard
                            v-for="card in loadingCards"
                            :key="card.key"
                            :title="card.title"
                            :skeleton="card.shape"
                            loading
                        />
                    </div>
                </div>
            </template>

            <div
                v-if="analytics"
                :id="`reports-panel-${view}`"
                role="tabpanel"
                :aria-labelledby="`reports-tab-${view}`"
                class="flex flex-col gap-(--section-gap) transition-opacity"
                :class="{ 'opacity-60': loading }"
                :aria-busy="loading"
            >
                <!-- ============================== Resumen -->
                <template v-if="view === 'resumen'">
                    <section
                        aria-labelledby="kpis-heading"
                        class="flex flex-col gap-3"
                    >
                        <SectionHeading
                            id="kpis-heading"
                            title="Volumen"
                            :description="`Solicitudes recibidas ${period.label}; cada una cuenta una sola vez, sin importar reasignaciones ni mensajes. Comparado con ${comparisonText}.`"
                        />
                        <div
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                            data-tour="reports.kpis"
                        >
                            <KpiCard
                                label="Recibidas"
                                :icon="Inbox"
                                :value="count(analytics.summary.received.value)"
                                :current="analytics.summary.received.value"
                                :previous="analytics.summary.received.previous"
                                hint="Solicitudes nuevas en el periodo"
                                data-kpi="received"
                            />
                            <KpiCard
                                label="Atendidas"
                                :icon="UserRoundCheck"
                                :value="count(analytics.summary.attended.value)"
                                :current="analytics.summary.attended.value"
                                :previous="analytics.summary.attended.previous"
                                better="higher"
                                :hint="
                                    share(
                                        analytics.summary.attended.value,
                                        analytics.summary.received.value,
                                    ) ??
                                    'De las recibidas: aceptadas, asignadas por una persona o respondidas'
                                "
                                data-kpi="attended"
                            />
                            <KpiCard
                                label="Finalizadas (de las recibidas)"
                                :icon="CheckCircle2"
                                :value="count(analytics.summary.resolved.value)"
                                :current="analytics.summary.resolved.value"
                                :previous="analytics.summary.resolved.previous"
                                better="higher"
                                :hint="`${share(analytics.summary.resolved.value, analytics.summary.received.value) ?? 'Sin recibidas'} · recibidas en el periodo y cerradas al menos una vez`"
                                data-kpi="resolved"
                            />
                            <KpiCard
                                label="Finalizadas en el periodo"
                                :icon="CircleCheck"
                                :value="
                                    count(
                                        analytics.summary.closed_in_period
                                            .value,
                                    )
                                "
                                :current="
                                    analytics.summary.closed_in_period.value
                                "
                                :previous="
                                    analytics.summary.closed_in_period.previous
                                "
                                better="higher"
                                hint="Su primer cierre ocurrió en el periodo, sin importar cuándo llegaron"
                                data-kpi="closed_in_period"
                            />
                            <KpiCard
                                label="Pendientes ahora"
                                :icon="Clock3"
                                :value="count(analytics.current.pending)"
                                live
                                :hint="`Esperan que alguien las acepte · ${analytics.current.open.toLocaleString('es-MX')} en atención${statusFiltered ? ' · el filtro de estado no se aplica a esta cifra en vivo' : ''}`"
                                :href="
                                    canOpenInbox &&
                                    analytics.current.pending > 0
                                        ? inboxLink(filters, {
                                              status: 'pending',
                                          })
                                        : undefined
                                "
                                data-kpi="pending"
                            />
                            <KpiCard
                                label="Acumuladas sin atender"
                                :icon="Hourglass"
                                :value="count(analytics.backlog.now)"
                                live
                                :hint="`Sin atención de una persona ni cierre, ahora · ${analytics.backlog.start.toLocaleString('es-MX')} al inicio del periodo`"
                                data-kpi="backlog"
                            />
                        </div>
                    </section>

                    <section
                        aria-labelledby="charts-heading"
                        class="flex flex-col gap-3"
                    >
                        <SectionHeading
                            id="charts-heading"
                            title="Evolución del periodo"
                            :description="period.label"
                        />
                        <div class="grid gap-3 lg:grid-cols-2">
                            <ChartCard
                                title="¿Cuántas solicitudes llegan y cuántas cerramos?"
                                :description="`Recibidas y finalizadas (por la fecha de su primer cierre) por ${granularityNoun(analytics.trend.granularity)}, contra las recibidas del periodo anterior`"
                                :total="
                                    analytics.summary.received.value === null
                                        ? undefined
                                        : `${formatNumber(analytics.summary.received.value)} recibidas · ${formatNumber(analytics.summary.closed_in_period.value ?? 0)} finalizadas en el periodo`
                                "
                                :empty="trendEmpty"
                                empty-text="Sin solicitudes en este periodo ni en el anterior"
                                storage-key="reports.trend"
                                toggle-tour="reports.table-toggle"
                                data-tour="reports.trend"
                                data-chart="trend"
                            >
                                <template #actions>
                                    <GranularityToggle
                                        :value="period.granularity"
                                        :options="period.granularities"
                                        @change="changeGranularity"
                                    />
                                </template>
                                <LineChart
                                    :labels="trendLabels"
                                    :series="trendSeries"
                                    :description="`Solicitudes recibidas ${period.label}: ${formatNumber(analytics.summary.received.value ?? 0)} en total, con las finalizadas en el periodo y las recibidas del periodo anterior`"
                                />
                                <template #legend>
                                    <ChartLegend :items="trendLegend" />
                                </template>
                                <template #table>
                                    <ChartDataTable
                                        caption="Volumen de solicitudes"
                                        :columns="trendColumns"
                                        :rows="trendRows"
                                    />
                                </template>
                            </ChartCard>

                            <ResponseTrendChart
                                :trend="analytics.response_trend"
                                :total="responseTotal"
                                :previous-label="period.previous.label"
                                storage-key="reports.response-trend"
                            />

                            <BacklogChart
                                :backlog="analytics.backlog"
                                :status-filtered="statusFiltered"
                                storage-key="reports.backlog"
                            />

                            <StatusChart
                                :statuses="analytics.statuses"
                                storage-key="reports.statuses"
                            />
                        </div>
                    </section>

                    <SurfaceCard
                        title="Tiempos de atención"
                        :description="`Promedios del periodo comparados con ${comparisonText}. Solo se promedian las solicitudes que tienen el dato.`"
                        body-class="flex flex-col gap-5 p-4 sm:p-5"
                        data-tour="reports.times"
                    >
                        <div class="space-y-3">
                            <h3 class="text-sm font-semibold">
                                Tiempo real (todas las horas)
                            </h3>
                            <div
                                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                <KpiCard
                                    v-for="card in calendarTimes"
                                    :key="card.key"
                                    :label="card.label"
                                    :icon="card.icon"
                                    :value="duration(card.figure)"
                                    :current="card.figure.value"
                                    :previous="card.figure.previous"
                                    :previous-label="
                                        previousDuration(card.figure)
                                    "
                                    better="lower"
                                    :hint="card.hint"
                                    :data-kpi="card.key"
                                />
                                <KpiCard
                                    v-if="analytics.sla"
                                    label="Tiempo de respuesta por mensaje del cliente"
                                    :icon="MessageCircleReply"
                                    :value="
                                        analytics.sla.response_time.avg === null
                                            ? null
                                            : formatDuration(
                                                  analytics.sla.response_time
                                                      .avg,
                                              )
                                    "
                                    live
                                    unavailable-label="Sin datos"
                                    :hint="
                                        analytics.sla.response_time.avg === null
                                            ? analytics.sla.available_since ===
                                              null
                                                ? 'Se mide por ciclo de respuesta desde esta versión: aún no hay registros'
                                                : `Sin respuestas medidas en el periodo. Disponible desde ${formatBucket(analytics.sla.available_since, 'day').long}`
                                            : `Entre cada mensaje del cliente y la respuesta de una persona. Mínimo ${formatDuration(analytics.sla.response_time.min ?? 0)} · máximo ${formatDuration(analytics.sla.response_time.max ?? 0)} · n = ${formatNumber(analytics.sla.response_time.samples)} ciclos`
                                    "
                                    data-kpi="cycle_response"
                                />
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="space-y-0.5">
                                <h3 class="text-sm font-semibold">
                                    Solo horario de atención
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    Solo cuentan las horas de atención del
                                    departamento que recibió la solicitud; sin
                                    horario configurado, la atención es continua
                                    y coincide con el tiempo real.
                                </p>
                            </div>
                            <div
                                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                <KpiCard
                                    v-for="card in businessTimes"
                                    :key="card.key"
                                    :label="card.label"
                                    :icon="card.icon"
                                    :value="duration(card.figure)"
                                    :current="card.figure.value"
                                    :previous="card.figure.previous"
                                    :previous-label="
                                        previousDuration(card.figure)
                                    "
                                    better="lower"
                                    :hint="card.hint"
                                    :data-kpi="card.key"
                                />
                            </div>
                        </div>
                    </SurfaceCard>

                    <section
                        aria-labelledby="quality-heading"
                        class="flex flex-col gap-3"
                    >
                        <SectionHeading
                            id="quality-heading"
                            title="Seguimiento y calidad"
                        />
                        <div
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <KpiCard
                                label="Reabiertas"
                                :icon="RotateCcw"
                                :value="count(analytics.summary.reopened.value)"
                                :current="analytics.summary.reopened.value"
                                :previous="analytics.summary.reopened.previous"
                                better="lower"
                                hint="Solicitudes reabiertas al menos una vez"
                            />
                            <KpiCard
                                label="Transferencias"
                                :icon="ArrowLeftRight"
                                :value="
                                    count(analytics.summary.transfers.value)
                                "
                                :current="analytics.summary.transfers.value"
                                :previous="analytics.summary.transfers.previous"
                                hint="Total de transferencias realizadas"
                            />
                            <KpiCard
                                label="Fuera de horario"
                                :icon="MoonStar"
                                :value="
                                    count(analytics.summary.out_of_hours.value)
                                "
                                :current="analytics.summary.out_of_hours.value"
                                :previous="
                                    analytics.summary.out_of_hours.previous
                                "
                                unavailable-label="Sin horario laboral configurado"
                                :hint="
                                    analytics.summary.out_of_hours.value ===
                                    null
                                        ? 'Activa el horario en Configuración o en el departamento'
                                        : `De ${analytics.summary.out_of_hours_measurable.toLocaleString('es-MX')} con horario aplicable`
                                "
                            />
                            <KpiCard
                                label="Errores de envío (mensajes)"
                                :icon="Send"
                                :value="
                                    count(analytics.summary.failed_out.value)
                                "
                                :current="analytics.summary.failed_out.value"
                                :previous="
                                    analytics.summary.failed_out.previous
                                "
                                better="lower"
                                :hint="
                                    analytics.summary.failure_rate.value ===
                                    null
                                        ? 'Sin mensajes salientes en el periodo'
                                        : `${analytics.summary.failure_rate.value} % de ${(analytics.summary.messages_out.value ?? 0).toLocaleString('es-MX')} mensajes salientes (incluye automáticos) · ${(analytics.summary.messages_in.value ?? 0).toLocaleString('es-MX')} entrantes`
                                "
                            />
                        </div>
                    </section>

                    <SurfaceCard
                        title="¿En qué días y horarios llegan más solicitudes?"
                        description="Solicitudes recibidas por día de la semana y hora"
                        body-class="p-4 sm:p-5"
                        data-tour="reports.heatmap"
                    >
                        <HourHeatmap
                            :cells="analytics.heatmap.cells"
                            :max="analytics.heatmap.max"
                        />
                    </SurfaceCard>
                </template>

                <!-- ============================== Productividad -->
                <template v-else-if="view === 'productividad' && productivity">
                    <StatusNote data-productivity-note>
                        Cada cifra se atribuye a quien hizo el trabajo, según el
                        historial de la solicitud (aceptaciones, asignaciones,
                        transferencias, cierres y respuestas). Una solicitud
                        atendida por dos personas cuenta para ambas: la suma por
                        colaborador puede superar las
                        {{ formatNumber(productivity.conversations) }}
                        solicitudes distintas atendidas en el periodo.
                    </StatusNote>
                    <StatusNote
                        v-if="!productivity.complete"
                        tone="warning"
                        data-productivity-incomplete
                    >
                        Productividad sin calcular para parte del periodo: hay
                        solicitudes que aún no tienen el detalle por persona
                        porque falta reconstruir la analítica tras la
                        actualización. Las cifras por persona son parciales y
                        donde no hay nada calculado se indica «Sin datos», no
                        cero. Pide a quien administra el servidor que ejecute la
                        reconstrucción de analítica.
                    </StatusNote>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <RankedBarsChart
                            title="¿Quién atendió y finalizó más solicitudes?"
                            :description="
                                productivity.rows.length > BAR_LIMIT
                                    ? `Los ${BAR_LIMIT} colaboradores con más solicitudes atendidas; la tabla los muestra a todos`
                                    : 'Solicitudes del periodo atendidas y cierres realizados por cada persona'
                            "
                            :items="productivityItems"
                            :series="productivitySeries"
                            :columns="productivityColumns"
                            :rows="productivity.rows"
                            :empty-text="
                                productivity.complete
                                    ? 'Nadie atendió solicitudes recibidas en este periodo'
                                    : 'Sin datos: la productividad de este periodo aún no se ha calculado'
                            "
                            storage-key="reports.productivity"
                            data-chart="productivity"
                        />
                        <AgentEvolutionChart
                            :evolution="productivity.evolution"
                            storage-key="reports.agent-evolution"
                        />
                    </div>

                    <SurfaceCard
                        :title="
                            scope === 'own'
                                ? 'Tu productividad'
                                : 'Productividad por colaborador'
                        "
                        description="Ordena por cualquier columna. Las cifras del periodo son de las solicitudes recibidas en él; «Pendientes actuales» y «Vencidas» son en vivo."
                        data-breakdown="productivity"
                    >
                        <ProductivityTable
                            :rows="productivity.rows"
                            :complete="productivity.complete"
                            :pending-href="pendingHref"
                        />
                    </SurfaceCard>
                </template>

                <!-- ============================== Cumplimiento de SLA -->
                <template v-else-if="view === 'sla' && sla">
                    <div class="grid gap-3 lg:grid-cols-2">
                        <SlaComplianceChart
                            :sla="sla"
                            :status-filtered="statusFiltered"
                            storage-key="reports.sla"
                        />
                        <ChartCard
                            title="¿Mejora el cumplimiento?"
                            :description="`Porcentaje de ciclos respondidos dentro del plazo por ${granularityNoun(sla.trend.granularity)}`"
                            :empty="sla.totals.samples === 0"
                            :empty-text="slaEmptyText"
                            storage-key="reports.sla-trend"
                            footnote="Los huecos son periodos sin ciclos evaluados."
                            data-chart="sla-trend"
                        >
                            <LineChart
                                :labels="slaTrendLabels"
                                :series="slaTrendSeries"
                                description="Cumplimiento de SLA a lo largo del periodo, en porcentaje"
                            />
                            <template #table>
                                <ChartDataTable
                                    caption="Cumplimiento de SLA por periodo"
                                    :columns="slaTrendColumns"
                                    :rows="slaTrendRows"
                                />
                            </template>
                        </ChartCard>
                        <RankedBarsChart
                            title="¿Qué departamento acumula más retrasos?"
                            description="Respuestas fuera del plazo por el departamento que tenía la solicitud al iniciar el ciclo"
                            :items="
                                delayItems(
                                    sla.by_department.filter(
                                        (row) => row.samples > 0,
                                    ),
                                )
                            "
                            :series="delaySeries"
                            :columns="delayColumns('Departamento')"
                            :rows="sla.by_department"
                            :empty-text="
                                sla.totals.samples === 0
                                    ? slaEmptyText
                                    : 'Ningún departamento tiene respuestas fuera del plazo'
                            "
                            storage-key="reports.sla-departments"
                            data-chart="sla-departments"
                        />
                        <RankedBarsChart
                            title="¿En qué categorías nos retrasamos más?"
                            description="Respuestas fuera del plazo por la categoría de la solicitud al iniciar el ciclo"
                            :items="
                                delayItems(
                                    (sla.by_category ?? []).filter(
                                        (row) => row.samples > 0,
                                    ),
                                )
                            "
                            :series="delaySeries"
                            :columns="delayColumns('Categoría')"
                            :rows="sla.by_category ?? []"
                            :empty-text="
                                sla.totals.samples === 0
                                    ? slaEmptyText
                                    : 'Ninguna categoría tiene respuestas fuera del plazo'
                            "
                            storage-key="reports.sla-categories"
                            data-chart="sla-categories"
                        />
                    </div>

                    <SurfaceCard
                        v-if="
                            analytics.summary.first_response_sla ||
                            analytics.summary.resolution_sla
                        "
                        title="Referencia con el objetivo general de hoy"
                        description="Cálculo anterior a los ciclos de respuesta, útil para periodos sin registro por ciclo. No es el cumplimiento oficial."
                        body-class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 sm:p-5"
                        data-sla-reference
                    >
                        <KpiCard
                            v-if="analytics.summary.first_response_sla"
                            label="Primera respuesta dentro del objetivo general"
                            :icon="Target"
                            :value="
                                analytics.summary.first_response_sla.rate ===
                                null
                                    ? null
                                    : `${analytics.summary.first_response_sla.rate} %`
                            "
                            live
                            unavailable-label="Sin datos"
                            :hint="
                                slaHint(
                                    analytics.summary.first_response_sla,
                                    'respuesta',
                                )
                            "
                        />
                        <KpiCard
                            v-if="analytics.summary.resolution_sla"
                            label="Resolución dentro del objetivo general"
                            :icon="Target"
                            :value="
                                analytics.summary.resolution_sla.rate === null
                                    ? null
                                    : `${analytics.summary.resolution_sla.rate} %`
                            "
                            live
                            unavailable-label="Sin datos"
                            :hint="
                                slaHint(
                                    analytics.summary.resolution_sla,
                                    'cierre',
                                )
                            "
                        />
                    </SurfaceCard>
                </template>

                <!-- ============================== Desgloses -->
                <template v-else-if="view === 'desgloses'">
                    <ChannelChart
                        :channels="analytics.by_channel"
                        storage-key="reports.channels"
                    />
                    <SurfaceCard
                        v-for="breakdown in breakdowns"
                        :key="breakdown.key"
                        :title="breakdown.title"
                        :description="breakdown.description"
                        :data-breakdown="breakdown.key"
                    >
                        <div
                            v-if="showsBars(breakdown.rows)"
                            class="flex flex-col gap-3 border-b px-4 py-4 sm:px-5"
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ barsNote(breakdown.rows) }}
                            </p>
                            <BarChart
                                :items="breakdownBars(breakdown.rows)"
                                :series="breakdownSeries"
                                :description="`${breakdown.title}: recibidas y atendidas de los ${Math.min(BAR_LIMIT, breakdown.rows.length)} con más solicitudes`"
                            />
                            <ChartLegend :items="breakdownLegend" />
                        </div>
                        <BreakdownTable
                            :rows="breakdown.rows"
                            :dimension="breakdown.dimension"
                            :show-load="breakdown.showLoad"
                        />
                    </SurfaceCard>
                </template>
            </div>
        </Deferred>
    </div>
</template>
