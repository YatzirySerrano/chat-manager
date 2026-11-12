<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    CircleCheck,
    Hourglass,
    Inbox,
    MessageCircle,
    Timer,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import BacklogChart from '@/components/charts/BacklogChart.vue';
import ChannelChart from '@/components/charts/ChannelChart.vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import LineChart from '@/components/charts/LineChart.vue';
import RankedBarsChart from '@/components/charts/RankedBarsChart.vue';
import ResponseTrendChart from '@/components/charts/ResponseTrendChart.vue';
import SlaComplianceChart from '@/components/charts/SlaComplianceChart.vue';
import StatusChart from '@/components/charts/StatusChart.vue';
import TeamBarsChart from '@/components/charts/TeamBarsChart.vue';
import type {
    BarItem,
    BarSeries,
    ChartTableColumn,
    LineSeries,
} from '@/components/charts/types';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import GranularityToggle from '@/components/reports/GranularityToggle.vue';
import HourHeatmap from '@/components/reports/HourHeatmap.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import StatCard from '@/components/StatCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import {
    chartTokens,
    formatBucket,
    formatDuration,
    formatNumber,
    formatPercent,
    granularityNoun,
} from '@/composables/useChartTheme';
import { useFeatures } from '@/composables/useFeatures';
import { usePermissions } from '@/composables/usePermissions';
import { inboxLink } from '@/lib/analyticsLinks';
import { dashboard } from '@/routes';
import { index as connectionsIndex } from '@/routes/connections';
import { index as reportsIndex } from '@/routes/reports';
import type {
    AnalyticsGranularity,
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
    DashboardCharts,
    DashboardOverview,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Panel principal',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    canExport?: boolean;
    canViewMetrics: boolean;
    overview?: DashboardOverview | null;
    charts?: DashboardCharts | null;
    filters?: AnalyticsQuery;
    period?: AnalyticsPeriod;
    periods?: AnalyticsPreset[];
    options?: AnalyticsOptions;
    canViewReports?: boolean;
}>();

const presetLabels: Record<string, string> = {
    today: 'Hoy',
    '7d': 'Últimos 7 días',
    '30d': 'Últimos 30 días',
};

const presets = computed(() =>
    (props.periods ?? []).map((value) => ({
        value,
        label: presetLabels[value] ?? value,
    })),
);

/* While new filters load, the current figures stay visible but dimmed. */
const refreshing = ref(false);
const removeStart = router.on('start', (event) => {
    if (!event.detail.visit.async) {
        refreshing.value = true;
    }
});
const removeFinish = router.on('finish', () => {
    refreshing.value = false;
});

onUnmounted(() => {
    removeStart();
    removeFinish();
});

function applyFilters(query: AnalyticsQuery): void {
    router.get(dashboard.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['filters', 'period', 'overview', 'charts'],
    });
}

function changeGranularity(granularity: AnalyticsGranularity): void {
    applyFilters({ ...props.filters, granularity });
}

const page = usePage();
const { can } = usePermissions();

const firstName = computed(
    () => page.props.auth.user.name.split(' ')[0] ?? page.props.auth.user.name,
);

const scopeDescription = computed(() => {
    switch (props.overview?.scope) {
        case 'all':
            return 'Vista general de toda la operación.';
        case 'departments':
            return 'Vista general de tus departamentos.';
        case 'own':
            return 'Vista general de tu bandeja de atención.';
        default:
            return props.canViewMetrics
                ? 'Cargando indicadores…'
                : 'Usa el menú lateral para ir a los módulos de tu rol.';
    }
});

const messagingUnavailable = 'Aún sin canal con mensajes';
const roleUnavailable = 'Tu rol no incluye conversaciones';

/**
 * Why the conversation figures are empty: the role (no conversations.view)
 * comes first, then a platform without channel traffic yet.
 */
const conversationsUnavailable = computed(() =>
    props.overview && !props.overview.conversations_visible
        ? roleUnavailable
        : messagingUnavailable,
);

function plural(count: number, one: string, many: string): string {
    return `${formatNumber(count)} ${count === 1 ? one : many}`;
}

function count(value: number | null | undefined): string | null {
    return value === null || value === undefined ? null : formatNumber(value);
}

function share(
    part: number | null | undefined,
    total: number | null | undefined,
): string | undefined {
    if (!part || !total) {
        return undefined;
    }

    return `${formatPercent(part / total)} de las recibidas`;
}

/** Drill-down links only make sense for who can open the inbox. */
const canOpenInbox = computed(() => can('conversations.view'));
const statusFiltered = computed(() => props.filters?.status !== undefined);
const comparisonLabel = computed(() => props.period?.comparison.label ?? '');

/* ---------- Volume (one bucket per hour/day/week/month) */

const activity = computed(() => props.charts?.activity ?? null);
const granularity = computed(
    () =>
        activity.value?.granularity ??
        props.overview?.trend_granularity ??
        'day',
);
const noun = computed(() => granularityNoun(granularity.value));

const bucketLabels = computed(() =>
    (activity.value?.points ?? []).map((point) => ({
        short: point.label,
        long: formatBucket(point.key, granularity.value).long,
    })),
);

const activityEmpty = computed(() =>
    (activity.value?.points ?? []).every(
        (point) => point.received === 0 && point.attended === 0,
    ),
);

const activitySeries = computed<LineSeries[]>(() => [
    {
        key: 'received',
        name: 'Recibidas',
        values: (activity.value?.points ?? []).map((point) => point.received),
        color: chartTokens.primary,
        area: true,
    },
    {
        key: 'attended',
        name: 'Atendidas',
        values: (activity.value?.points ?? []).map((point) => point.attended),
        color: chartTokens.secondary,
    },
]);

const bucketRows = computed(() =>
    (activity.value?.points ?? []).map((point, index) => ({
        ...point,
        period: bucketLabels.value[index]?.long ?? point.label,
    })),
);

const activityColumns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    { key: 'received', label: 'Recibidas', align: 'right' },
    { key: 'attended', label: 'Atendidas', align: 'right' },
];

/** «1 245 recibidas · 12 % más que el periodo anterior» (figures of the server). */
const receivedTotal = computed(() => {
    const received = props.overview?.received;

    if (!received || received.value === null) {
        return undefined;
    }

    const text = plural(received.value, 'recibida', 'recibidas');

    if (received.previous === null || received.previous === 0) {
        return text;
    }

    const change = (received.value - received.previous) / received.previous;

    if (Math.abs(change) < 0.005) {
        return `${text} · igual que el periodo de comparación`;
    }

    return `${text} · ${formatPercent(Math.abs(change))} ${change > 0 ? 'más' : 'menos'} que el periodo de comparación`;
});

const measuredTotal = computed(() =>
    props.overview && props.overview.first_response_samples > 0
        ? `Promedio de ${plural(props.overview.first_response_samples, 'solicitud medida', 'solicitudes medidas')} (n)`
        : undefined,
);

const responseTotal = computed(() => {
    const overview = props.overview;

    if (!overview || overview.average_first_response_seconds === null) {
        return undefined;
    }

    const current = `Promedio ${formatDuration(overview.average_first_response_seconds)} (n = ${formatNumber(overview.first_response_samples)})`;

    return overview.previous_first_response_seconds === null
        ? current
        : `${current} · antes ${formatDuration(overview.previous_first_response_seconds)}`;
});

const lineLegend = computed(() =>
    activitySeries.value.map((series) => ({
        key: series.key,
        label: series.name,
        color: series.color ?? chartTokens.primary,
        shape: 'line' as const,
    })),
);

/* ---------- Delays per department (response cycles past their deadline) */

const sla = computed(() => props.charts?.sla ?? null);

const delaySeries: BarSeries[] = [
    {
        key: 'breached',
        name: 'Fuera del plazo',
        color: 'var(--destructive)',
    },
];

const delayItems = computed<BarItem[]>(() =>
    (sla.value?.by_department ?? [])
        .filter((row) => row.samples > 0)
        .map((row, index) => ({
            key: row.id === null ? `none-${index}` : String(row.id),
            label: row.name,
            values: [row.breached],
            muted: row.id === null,
        })),
);

const delayColumns: ChartTableColumn[] = [
    { key: 'name', label: 'Departamento' },
    { key: 'breached', label: 'Fuera del plazo', align: 'right' },
    { key: 'met', label: 'Dentro del plazo', align: 'right' },
    { key: 'samples', label: 'Evaluados (n)', align: 'right' },
    {
        key: 'rate',
        label: 'Cumplimiento',
        align: 'right',
        format: (value) =>
            typeof value === 'number'
                ? `${formatNumber(value)} %`
                : 'Sin datos',
    },
];

const delayEmptyText = computed(() => {
    if (!sla.value || sla.value.available_since === null) {
        return 'El seguimiento de SLA comienza con esta versión: aún no hay registros.';
    }

    return sla.value.totals.samples === 0
        ? 'Sin ciclos de respuesta evaluados en este periodo.'
        : 'Ningún departamento tiene respuestas fuera del plazo en este periodo.';
});

/* ---------- Live workload per person */

const workload = computed(() => props.charts?.workload ?? null);

const workloadSeries: BarSeries[] = [
    { key: 'open', name: 'En atención', color: chartTokens.primary },
    {
        key: 'pending',
        name: 'Pendientes de aceptar',
        color: chartTokens.secondary,
    },
];

const workloadItems = computed<BarItem[]>(() =>
    (workload.value?.rows ?? []).map((row, index) => ({
        key: row.id === null ? `other-${index}` : String(row.id),
        label: row.name,
        values: [row.open, row.pending],
        muted: row.id === null,
        href:
            canOpenInbox.value && row.id !== null
                ? inboxLink(props.filters, { agent: row.id, status: 'active' })
                : undefined,
    })),
);

/* Requests past their SLA deadline are phase two: not counted while it is off. */
const { phaseTwo } = useFeatures();

const workloadColumns = computed<ChartTableColumn[]>(() => [
    { key: 'name', label: 'Colaborador' },
    { key: 'open', label: 'En atención', align: 'right' },
    { key: 'pending', label: 'Pendientes de aceptar', align: 'right' },
    ...(phaseTwo.value
        ? [
              {
                  key: 'overdue',
                  label: 'Vencidas ahora',
                  align: 'right',
              } as ChartTableColumn,
          ]
        : []),
]);

const workloadTotal = computed(() => {
    if (!workload.value) {
        return undefined;
    }

    if (!phaseTwo.value) {
        return plural(
            workload.value.unassigned,
            'solicitud en cola sin responsable',
            'solicitudes en cola sin responsable',
        );
    }

    const overdue =
        workload.value.rows.reduce((sum, row) => sum + row.overdue, 0) +
        workload.value.unassigned_overdue;

    return `${plural(workload.value.unassigned, 'solicitud en cola sin responsable', 'solicitudes en cola sin responsable')} · ${plural(overdue, 'vencida ahora', 'vencidas ahora')}`;
});

/* ---------- Categories */

const categorySeries: BarSeries[] = [
    { key: 'received', name: 'Recibidas', color: chartTokens.primary },
    { key: 'resolved', name: 'Finalizadas', color: chartTokens.secondary },
];

const categoryItems = computed<BarItem[]>(() =>
    (props.charts?.categories ?? []).map((row) => ({
        key: row.key,
        label: row.name,
        values: [row.received, row.resolved],
        muted: row.id === null,
    })),
);

const categoryColumns: ChartTableColumn[] = [
    { key: 'name', label: 'Categoría' },
    { key: 'received', label: 'Recibidas', align: 'right' },
    { key: 'attended', label: 'Atendidas', align: 'right' },
    { key: 'resolved', label: 'Finalizadas', align: 'right' },
];

/* ---------- Team */

const agents = computed(() => props.charts?.agents ?? null);
const departments = computed(() => props.charts?.departments ?? null);

const showsTeam = computed(
    () => agents.value !== null || departments.value !== null,
);

const hasCharts = computed(
    () =>
        !!props.charts &&
        (props.charts.activity !== null ||
            props.charts.channels !== null ||
            props.charts.statuses !== null),
);

const loadingCards = [
    {
        key: 'response',
        title: '¿Estamos respondiendo más rápido?',
        wide: true,
        shape: 'line',
    },
    {
        key: 'sla',
        title: '¿Qué porcentaje cumplió el SLA?',
        wide: false,
        shape: 'bars',
    },
    {
        key: 'delays',
        title: '¿Qué departamento acumula más retrasos?',
        wide: false,
        shape: 'bars',
    },
    {
        key: 'volume',
        title: 'Volumen de solicitudes',
        wide: true,
        shape: 'line',
    },
] as const;
</script>

<template>
    <Head title="Panel principal" />

    <div class="page-shell">
        <PageHeader
            help="dashboard"
            show-title
            :title="`Hola, ${firstName}`"
            :description="scopeDescription"
        >
            <template v-if="canExport && canViewMetrics" #actions>
                <span data-tour="dashboard.export" class="inline-flex">
                    <ExportMenu
                        report="dashboard"
                        :filters="filters"
                        label="Exportar resumen"
                    />
                </span>
            </template>
        </PageHeader>

        <StatusNote v-if="!canViewMetrics" data-tour="dashboard.no-metrics">
            Tu rol no incluye los indicadores del panel principal. Si los
            necesitas, solicita el permiso «Ver el panel principal» a un
            administrador.
        </StatusNote>

        <AnalyticsFilterBar
            v-if="canViewMetrics && filters && period && options"
            data-tour="dashboard.filters"
            :query="filters"
            :period="period"
            :presets="presets"
            :options="options"
            @change="applyFilters"
        />

        <Deferred v-if="canViewMetrics" data="overview">
            <template #fallback>
                <div
                    class="auto-grid reveal-stagger [--col-min:14rem]"
                    aria-busy="true"
                    aria-label="Cargando indicadores"
                >
                    <StatCard
                        v-for="index in 8"
                        :key="index"
                        label="Cargando…"
                        :value="null"
                        loading
                    />
                </div>
            </template>

            <div
                v-if="overview"
                class="flex flex-col gap-(--section-gap) transition-opacity"
                :class="{ 'opacity-60': refreshing }"
                :aria-busy="refreshing || undefined"
            >
                <StatusNote
                    v-if="!overview.messaging_connected"
                    title="Aún no hay canales con mensajes confirmados"
                >
                    Los indicadores y las gráficas se calcularán con datos
                    reales en cuanto un canal (WhatsApp o Chat web) reciba su
                    primer mensaje.
                    <Link
                        v-if="can('connections.view')"
                        :href="connectionsIndex()"
                        class="font-medium underline underline-offset-4"
                    >
                        Revisar conexiones
                    </Link>
                </StatusNote>

                <section
                    aria-labelledby="live-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="live-heading"
                        title="Ahora"
                        :description="
                            statusFiltered
                                ? 'Estado de la bandeja en este momento. El filtro de estado no se aplica a estas cifras en vivo.'
                                : 'Estado de la bandeja en este momento (no depende del periodo).'
                        "
                    />
                    <div
                        class="grid reveal-stagger grid-cols-1 gap-3 sm:grid-cols-3"
                        data-tour="dashboard.kpis"
                    >
                        <KpiCard
                            label="Pendientes"
                            :icon="Hourglass"
                            :value="count(overview.conversations.pending)"
                            live
                            hint="Esperan que alguien las acepte"
                            :unavailable-label="conversationsUnavailable"
                            :href="
                                canOpenInbox && overview.conversations.pending
                                    ? inboxLink(filters, { status: 'pending' })
                                    : undefined
                            "
                            data-kpi="pending"
                        />
                        <KpiCard
                            label="En atención"
                            :icon="MessageCircle"
                            :value="count(overview.conversations.active)"
                            live
                            hint="Con una persona responsable"
                            :unavailable-label="conversationsUnavailable"
                            :href="
                                canOpenInbox && overview.conversations.active
                                    ? inboxLink(filters, { status: 'open' })
                                    : undefined
                            "
                            data-kpi="active"
                        />
                        <KpiCard
                            label="Finalizadas hoy"
                            :icon="CircleCheck"
                            :value="count(overview.conversations.closed_today)"
                            live
                            hint="Cerradas desde las 00:00 de hoy y que siguen cerradas"
                            :unavailable-label="conversationsUnavailable"
                            data-kpi="closed_today"
                        />
                    </div>
                    <p
                        v-if="
                            overview.scope !== 'all' &&
                            overview.conversations_visible
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Se cuentan las conversaciones que puedes ver en tu
                        bandeja.
                    </p>
                </section>

                <section
                    v-if="overview.period"
                    aria-labelledby="period-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="period-heading"
                        title="Resultado del periodo"
                        :description="`Solicitudes recibidas ${period?.label ?? ''}; cada una cuenta una sola vez. Comparado con ${period?.comparison.partial ? 'el mismo tramo transcurrido del periodo anterior' : 'el periodo anterior'} (${comparisonLabel}).`"
                    />
                    <div
                        class="auto-grid reveal-stagger [--col-min:14rem]"
                        data-tour="dashboard.period-kpis"
                    >
                        <KpiCard
                            label="Recibidas"
                            :icon="Inbox"
                            :value="count(overview.received.value)"
                            :current="overview.received.value"
                            :previous="overview.received.previous"
                            hint="Solicitudes nuevas en el periodo"
                            data-kpi="received"
                        />
                        <KpiCard
                            label="Atendidas"
                            :icon="UserRoundCheck"
                            :value="count(overview.period.attended.value)"
                            :current="overview.period.attended.value"
                            :previous="overview.period.attended.previous"
                            better="higher"
                            :hint="
                                share(
                                    overview.period.attended.value,
                                    overview.received.value,
                                ) ??
                                'De las recibidas: aceptadas, asignadas por una persona o respondidas'
                            "
                            data-kpi="attended"
                        />
                        <KpiCard
                            label="Finalizadas"
                            :icon="CheckCircle2"
                            :value="count(overview.period.resolved.value)"
                            :current="overview.period.resolved.value"
                            :previous="overview.period.resolved.previous"
                            better="higher"
                            :hint="
                                share(
                                    overview.period.resolved.value,
                                    overview.received.value,
                                ) ??
                                'De las recibidas: cerradas al menos una vez'
                            "
                            data-kpi="resolved"
                        />
                        <KpiCard
                            label="Espera hasta la primera respuesta"
                            :icon="Timer"
                            :value="
                                overview.average_first_response_seconds === null
                                    ? null
                                    : formatDuration(
                                          overview.average_first_response_seconds,
                                      )
                            "
                            :current="overview.average_first_response_seconds"
                            :previous="overview.previous_first_response_seconds"
                            :previous-label="
                                overview.previous_first_response_seconds ===
                                null
                                    ? null
                                    : formatDuration(
                                          overview.previous_first_response_seconds,
                                      )
                            "
                            better="lower"
                            :hint="measuredTotal"
                            unavailable-label="Sin datos: ninguna respuesta medida en el periodo"
                            data-kpi="first_response"
                        />
                    </div>
                </section>

                <StatusNote
                    v-if="!overview.conversations_visible"
                    title="Tu rol no incluye conversaciones"
                    data-dashboard-note="no-conversations"
                >
                    Los indicadores de atención y las gráficas del periodo se
                    calculan con las conversaciones. Si los necesitas, solicita
                    el permiso «Ver las conversaciones que le corresponden» a un
                    administrador.
                </StatusNote>
            </div>
        </Deferred>

        <Deferred v-if="canViewMetrics" data="charts">
            <template #fallback>
                <section class="flex flex-col gap-3" aria-busy="true">
                    <SectionHeading title="Tiempos y cumplimiento" />
                    <div class="grid gap-3 lg:grid-cols-3">
                        <ChartCard
                            v-for="card in loadingCards"
                            :key="card.key"
                            :class="card.wide && 'lg:col-span-2'"
                            :title="card.title"
                            :skeleton="card.shape"
                            loading
                        />
                    </div>
                </section>
            </template>

            <div
                v-if="charts && hasCharts"
                class="flex flex-col gap-(--section-gap) transition-opacity"
                :class="{ 'opacity-60': refreshing }"
            >
                <section
                    aria-labelledby="times-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="times-heading"
                        title="Tiempos y cumplimiento"
                        :description="period?.label"
                    >
                        <template v-if="canViewReports" #actions>
                            <Link
                                :href="reportsIndex({ query: filters })"
                                class="flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                                data-tour="dashboard.reports-link"
                            >
                                Ver reportes completos
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Link>
                        </template>
                    </SectionHeading>

                    <div class="grid grid-flow-dense gap-3 lg:grid-cols-3">
                        <ResponseTrendChart
                            v-if="charts.response_trend"
                            :class="sla ? 'lg:col-span-2' : 'lg:col-span-3'"
                            :trend="charts.response_trend"
                            :total="responseTotal"
                            :previous-label="period?.previous.label ?? ''"
                            storage-key="dashboard.response-trend"
                            data-tour="dashboard.response-trend"
                        />
                        <SlaComplianceChart
                            v-if="sla"
                            :sla="sla"
                            :status-filtered="statusFiltered"
                            storage-key="dashboard.sla"
                            data-tour="dashboard.sla"
                        />
                        <RankedBarsChart
                            v-if="sla"
                            class="lg:col-span-3"
                            title="¿Qué departamento acumula más retrasos?"
                            description="Respuestas fuera del plazo en el periodo, por el departamento que tenía la solicitud"
                            :items="delayItems"
                            :series="delaySeries"
                            :columns="delayColumns"
                            :rows="sla.by_department"
                            :empty-text="delayEmptyText"
                            storage-key="dashboard.delays"
                            data-chart="delays"
                        />
                    </div>
                </section>

                <section
                    aria-labelledby="load-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="load-heading"
                        title="Carga de trabajo"
                        description="Qué hay pendiente y en manos de quién."
                    />
                    <div class="grid gap-3 lg:grid-cols-2">
                        <RankedBarsChart
                            v-if="workload"
                            title="¿Quién tiene más solicitudes pendientes?"
                            :description="`Solicitudes asignadas ahora y sin cerrar, en vivo${statusFiltered ? ' (el filtro de estado no se aplica)' : ''}. El nombre abre su lista en la bandeja.`"
                            :total="workloadTotal"
                            :items="workloadItems"
                            :series="workloadSeries"
                            :columns="workloadColumns"
                            :rows="workload.rows"
                            empty-text="Nadie tiene solicitudes asignadas en este momento"
                            storage-key="dashboard.workload"
                            data-tour="dashboard.workload"
                            data-chart="workload"
                        />
                        <BacklogChart
                            v-if="charts.backlog"
                            :backlog="charts.backlog"
                            :status-filtered="statusFiltered"
                            storage-key="dashboard.backlog"
                        />
                    </div>
                </section>

                <section
                    aria-labelledby="demand-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="demand-heading"
                        title="Demanda"
                        description="Cuánto llega y cuándo."
                    />
                    <div class="grid gap-3 lg:grid-cols-2">
                        <ChartCard
                            v-if="activity"
                            title="¿Cuántas solicitudes llegan y cuántas atendemos?"
                            :description="`Solicitudes recibidas y atendidas (de las recibidas) por ${noun}`"
                            :total="receivedTotal"
                            :empty="activityEmpty"
                            empty-text="Sin solicitudes recibidas en este periodo"
                            storage-key="dashboard.activity"
                            data-tour="dashboard.activity"
                            data-chart="activity"
                        >
                            <template
                                v-if="period && granularity !== 'hour'"
                                #actions
                            >
                                <GranularityToggle
                                    :value="period.granularity"
                                    :options="period.granularities"
                                    @change="changeGranularity"
                                />
                            </template>
                            <LineChart
                                :labels="bucketLabels"
                                :series="activitySeries"
                                :description="`Solicitudes recibidas y atendidas por ${noun}. ${receivedTotal ?? ''}`"
                            />
                            <template #legend>
                                <ChartLegend :items="lineLegend" />
                            </template>
                            <template #table>
                                <ChartDataTable
                                    caption="Solicitudes recibidas y atendidas"
                                    :columns="activityColumns"
                                    :rows="bucketRows"
                                />
                            </template>
                        </ChartCard>

                        <SurfaceCard
                            v-if="charts.heatmap"
                            title="¿En qué horario llegan más solicitudes?"
                            description="Solicitudes recibidas en el periodo por día de la semana y hora"
                            body-class="p-4 sm:p-5"
                            data-chart="heatmap"
                        >
                            <HourHeatmap
                                v-if="charts.heatmap.max > 0"
                                :cells="charts.heatmap.cells"
                                :max="charts.heatmap.max"
                            />
                            <p
                                v-else
                                class="py-8 text-sm text-muted-foreground"
                            >
                                Sin solicitudes recibidas en este periodo.
                            </p>
                        </SurfaceCard>
                    </div>
                </section>

                <section
                    aria-labelledby="mix-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="mix-heading"
                        title="Distribución"
                        description="De qué tipo son las solicitudes del periodo, por dónde llegan y cómo están hoy."
                    />
                    <div class="grid gap-3 lg:grid-cols-3">
                        <RankedBarsChart
                            v-if="charts.categories"
                            title="¿Qué piden más los clientes?"
                            description="Solicitudes recibidas y finalizadas por categoría actual; las menos frecuentes se agrupan en «Otros»"
                            :items="categoryItems"
                            :series="categorySeries"
                            :columns="categoryColumns"
                            :rows="charts.categories"
                            empty-text="Sin solicitudes recibidas en este periodo"
                            storage-key="dashboard.categories"
                            data-chart="categories"
                        />
                        <ChannelChart
                            v-if="charts.channels"
                            :channels="charts.channels"
                            storage-key="dashboard.channels"
                        />
                        <StatusChart
                            v-if="charts.statuses"
                            :statuses="charts.statuses"
                            storage-key="dashboard.statuses"
                        />
                    </div>
                </section>

                <section
                    v-if="showsTeam"
                    aria-labelledby="team-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="team-heading"
                        title="Equipo"
                        description="Solicitudes del periodo según su responsable y su departamento actuales; el resto se agrupa en «Otros». La productividad de cada persona está en Reportes."
                    />
                    <div class="grid gap-3 lg:grid-cols-2">
                        <TeamBarsChart
                            v-if="agents"
                            title="Cartera por responsable actual"
                            description="Recibidas y atendidas según quién es hoy (o fue al cerrar) su responsable"
                            dimension="Responsable actual"
                            :rows="agents"
                            second="attended"
                            storage-key="dashboard.agents"
                            data-tour="dashboard.team"
                            data-chart="agents"
                        />
                        <TeamBarsChart
                            v-if="departments"
                            title="Comparativo por departamento"
                            description="Recibidas y finalizadas (de las recibidas) por departamento actual"
                            dimension="Departamento"
                            :rows="departments"
                            second="resolved"
                            storage-key="dashboard.departments"
                            data-chart="departments"
                        />
                    </div>
                </section>
            </div>
        </Deferred>

        <section
            v-if="overview && overview.departments !== null"
            aria-label="Equipo disponible"
            class="auto-grid reveal-stagger [--col-min:14rem]"
        >
            <StatCard
                label="Agentes activos"
                :icon="UserRoundCheck"
                :value="overview.agents.active"
                hint="Cuentas activas que pueden atender conversaciones"
                unavailable-label="Sin datos"
            />
            <StatCard
                label="Departamentos activos"
                :icon="Building2"
                :value="overview.departments.active"
                :hint="`${formatNumber(overview.departments.total)} en total`"
            />
        </section>
    </div>
</template>
