<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CheckCircle2,
    Clock3,
    Inbox,
    MessageCircleReply,
    MoonStar,
    RotateCcw,
    Send,
    Target,
    Timer,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import ChannelChart from '@/components/charts/ChannelChart.vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import FirstResponseChart from '@/components/charts/FirstResponseChart.vue';
import LineChart from '@/components/charts/LineChart.vue';
import StatusChart from '@/components/charts/StatusChart.vue';
import type {
    BarItem,
    BarSeries,
    ChartTableColumn,
    LineSeries,
} from '@/components/charts/types';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import BreakdownTable from '@/components/reports/BreakdownTable.vue';
import HourHeatmap from '@/components/reports/HourHeatmap.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
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
import { formatDuration } from '@/lib/format';
import { index as reportsIndex } from '@/routes/reports';
import type {
    AnalyticsBreakdownRow,
    AnalyticsDuration,
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
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
}>();

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

const scopeDescription = computed(
    () =>
        ({
            all: 'Indicadores de toda la operación.',
            departments:
                'Indicadores de tus departamentos y de las conversaciones que atiendes.',
            own: 'Tus propios indicadores de atención.',
        })[props.scope],
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
        ? 'Aún no hay conversaciones suficientes para calcularlo'
        : `Promedio de ${figure.samples.toLocaleString('es-MX')} ${figure.samples === 1 ? 'conversación' : 'conversaciones'}`;
}

function previousDuration(figure: AnalyticsDuration): string | null {
    return figure.previous === null ? null : formatDuration(figure.previous);
}

function slaHint(sla: AnalyticsSla): string {
    const target = formatDuration(sla.target_seconds);

    return sla.samples === 0
        ? `Objetivo: ${target} en horario de atención. Ninguna conversación medida aún`
        : `${sla.met.toLocaleString('es-MX')} de ${sla.samples.toLocaleString('es-MX')} dentro de ${target} en horario de atención`;
}

/* ---------- Trend: received vs. previous period, and resolved */

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
            name: 'Resueltas',
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

/**
 * Resolved within the period, by the date they were closed: the same events
 * as the «Resueltas» line (not the cohort figure of the «Resueltas» card,
 * which only counts the conversations received in the period).
 */
const trendClosedTotal = computed(() =>
    (trend.value?.points ?? []).reduce((sum, point) => sum + point.resolved, 0),
);

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
    { key: 'resolved', label: 'Resueltas', align: 'right' },
    {
        key: 'previous_received',
        label: 'Recibidas (periodo anterior)',
        align: 'right',
    },
];

const measuredTotal = computed(() => {
    const measured = props.analytics?.summary.first_response_seconds.samples;

    return measured
        ? `Medido en ${formatNumber(measured)} ${measured === 1 ? 'conversación' : 'conversaciones'}`
        : undefined;
});

/* ---------- Breakdowns: top 10 bars above the complete table */

const BAR_LIMIT = 10;

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
        ? `Los ${BAR_LIMIT} con más conversaciones recibidas; la tabla muestra los ${formatNumber(rows.length)}.`
        : 'Recibidas y atendidas en el periodo.';
}

const breakdowns = computed(() => {
    const analytics = props.analytics;

    if (!analytics) {
        return [];
    }

    return [
        {
            key: 'agents',
            dimension: 'Agente',
            title:
                props.scope === 'own'
                    ? 'Tu desempeño'
                    : 'Desempeño y carga por agente',
            description:
                analytics.current.agents === null
                    ? 'Agente responsable actual (o último, si ya se cerró)'
                    : `${analytics.current.agents} ${analytics.current.agents === 1 ? 'agente activo' : 'agentes activos'} · agente responsable actual o último`,
            rows: analytics.by_agent,
            showLoad: true,
            visible: true,
        },
        {
            key: 'departments',
            dimension: 'Departamento',
            title: 'Por departamento',
            description: 'Departamento actual de cada conversación',
            rows: analytics.by_department,
            showLoad: true,
            visible: props.scope !== 'own',
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
    ].filter((breakdown) => breakdown.visible);
});

const loadingCards = [
    { key: 'trend', title: 'Tendencia', shape: 'line' },
    { key: 'statuses', title: 'Estado de lo recibido', shape: 'bars' },
    {
        key: 'first-response',
        title: 'Tiempo de primera respuesta',
        shape: 'line',
    },
    { key: 'channels', title: 'Por canal', shape: 'bars' },
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
            <template v-if="canExport" #actions>
                <span data-tour="reports.export" class="inline-flex">
                    <ExportMenu
                        report="analytics"
                        :filters="filters"
                        label="Exportar reporte"
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

        <Deferred data="analytics">
            <template #fallback>
                <div class="flex flex-col gap-(--section-gap)" aria-busy="true">
                    <div
                        class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                        aria-label="Cargando reportes"
                    >
                        <StatCard
                            v-for="index in 4"
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
                class="flex flex-col gap-(--section-gap) transition-opacity"
                :class="{ 'opacity-60': loading }"
                :aria-busy="loading"
            >
                <section
                    aria-label="Indicadores clave"
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    data-tour="reports.kpis"
                >
                    <KpiCard
                        label="Recibidas"
                        :icon="Inbox"
                        :value="count(analytics.summary.received.value)"
                        :current="analytics.summary.received.value"
                        :previous="analytics.summary.received.previous"
                        hint="Conversaciones nuevas en el periodo"
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
                            'Aceptadas, asignadas por una persona o respondidas'
                        "
                        data-kpi="attended"
                    />
                    <KpiCard
                        label="Resueltas"
                        :icon="CheckCircle2"
                        :value="count(analytics.summary.resolved.value)"
                        :current="analytics.summary.resolved.value"
                        :previous="analytics.summary.resolved.previous"
                        better="higher"
                        :hint="
                            share(
                                analytics.summary.resolved.value,
                                analytics.summary.received.value,
                            ) ?? 'Cerradas al menos una vez'
                        "
                        data-kpi="resolved"
                    />
                    <KpiCard
                        label="Pendientes ahora"
                        :icon="Clock3"
                        :value="count(analytics.current.pending)"
                        :hint="`${analytics.current.open.toLocaleString('es-MX')} en atención`"
                        data-kpi="pending"
                    />
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
                            title="Tendencia"
                            :description="`Recibidas y resueltas (por fecha de cierre) por ${granularityNoun(analytics.trend.granularity)}, comparadas con el periodo anterior`"
                            :total="
                                analytics.summary.received.value === null
                                    ? undefined
                                    : `${formatNumber(analytics.summary.received.value)} recibidas · ${formatNumber(trendClosedTotal)} resueltas en el periodo`
                            "
                            :empty="trendEmpty"
                            empty-text="Sin conversaciones en este periodo ni en el anterior"
                            storage-key="reports.trend"
                            toggle-tour="reports.table-toggle"
                            data-tour="reports.trend"
                            data-chart="trend"
                        >
                            <LineChart
                                :labels="trendLabels"
                                :series="trendSeries"
                                :description="`Conversaciones recibidas ${period.label}: ${formatNumber(analytics.summary.received.value ?? 0)} en total, con las resueltas y las recibidas del periodo anterior`"
                            />
                            <template #legend>
                                <ChartLegend :items="trendLegend" />
                            </template>
                            <template #table>
                                <ChartDataTable
                                    caption="Tendencia de conversaciones"
                                    :columns="trendColumns"
                                    :rows="trendRows"
                                />
                            </template>
                        </ChartCard>

                        <StatusChart
                            :statuses="analytics.statuses"
                            storage-key="reports.statuses"
                        />

                        <FirstResponseChart
                            :activity="analytics.activity"
                            :total="measuredTotal"
                            storage-key="reports.first-response"
                        />

                        <ChannelChart
                            :channels="analytics.by_channel"
                            storage-key="reports.channels"
                        />
                    </div>
                </section>

                <SurfaceCard
                    title="Tiempos de atención"
                    description="Promedios del periodo comparados con el periodo anterior"
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
                                label="Primera atención"
                                :icon="Timer"
                                :value="
                                    duration(
                                        analytics.summary
                                            .first_attention_seconds,
                                    )
                                "
                                :current="
                                    analytics.summary.first_attention_seconds
                                        .value
                                "
                                :previous="
                                    analytics.summary.first_attention_seconds
                                        .previous
                                "
                                :previous-label="
                                    previousDuration(
                                        analytics.summary
                                            .first_attention_seconds,
                                    )
                                "
                                better="lower"
                                :hint="`Desde que llega hasta que una persona la acepta, la asigna o responde (la asignación automática no cuenta). ${samples(analytics.summary.first_attention_seconds)}`"
                            />
                            <KpiCard
                                label="Primera respuesta"
                                :icon="MessageCircleReply"
                                :value="
                                    duration(
                                        analytics.summary
                                            .first_response_seconds,
                                    )
                                "
                                :current="
                                    analytics.summary.first_response_seconds
                                        .value
                                "
                                :previous="
                                    analytics.summary.first_response_seconds
                                        .previous
                                "
                                :previous-label="
                                    previousDuration(
                                        analytics.summary
                                            .first_response_seconds,
                                    )
                                "
                                better="lower"
                                :hint="`Primer mensaje escrito por una persona; sin contar mensajes automáticos ni fallidos. ${samples(analytics.summary.first_response_seconds)}`"
                            />
                            <KpiCard
                                label="Resolución"
                                :icon="CheckCircle2"
                                :value="
                                    duration(
                                        analytics.summary.resolution_seconds,
                                    )
                                "
                                :current="
                                    analytics.summary.resolution_seconds.value
                                "
                                :previous="
                                    analytics.summary.resolution_seconds
                                        .previous
                                "
                                :previous-label="
                                    previousDuration(
                                        analytics.summary.resolution_seconds,
                                    )
                                "
                                better="lower"
                                :hint="`Desde que llega hasta su primer cierre. ${samples(analytics.summary.resolution_seconds)}`"
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
                                departamento que recibió la conversación; sin
                                horario configurado, la atención es continua y
                                coincide con el tiempo real.
                            </p>
                        </div>
                        <div
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <KpiCard
                                label="Primera respuesta"
                                :icon="MessageCircleReply"
                                :value="
                                    duration(
                                        analytics.summary
                                            .first_response_business_seconds,
                                    )
                                "
                                :current="
                                    analytics.summary
                                        .first_response_business_seconds.value
                                "
                                :previous="
                                    analytics.summary
                                        .first_response_business_seconds
                                        .previous
                                "
                                :previous-label="
                                    previousDuration(
                                        analytics.summary
                                            .first_response_business_seconds,
                                    )
                                "
                                better="lower"
                                :hint="
                                    samples(
                                        analytics.summary
                                            .first_response_business_seconds,
                                    )
                                "
                            />
                            <KpiCard
                                label="Resolución"
                                :icon="CheckCircle2"
                                :value="
                                    duration(
                                        analytics.summary
                                            .resolution_business_seconds,
                                    )
                                "
                                :current="
                                    analytics.summary
                                        .resolution_business_seconds.value
                                "
                                :previous="
                                    analytics.summary
                                        .resolution_business_seconds.previous
                                "
                                :previous-label="
                                    previousDuration(
                                        analytics.summary
                                            .resolution_business_seconds,
                                    )
                                "
                                better="lower"
                                :hint="
                                    samples(
                                        analytics.summary
                                            .resolution_business_seconds,
                                    )
                                "
                            />
                        </div>
                    </div>

                    <div
                        v-if="
                            analytics.summary.first_response_sla ||
                            analytics.summary.resolution_sla
                        "
                        class="space-y-3"
                    >
                        <h3 class="text-sm font-semibold">Objetivos</h3>
                        <div
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <KpiCard
                                v-if="analytics.summary.first_response_sla"
                                label="Objetivo de primera respuesta"
                                :icon="Target"
                                :value="
                                    analytics.summary.first_response_sla
                                        .rate === null
                                        ? null
                                        : `${analytics.summary.first_response_sla.rate} %`
                                "
                                :current="
                                    analytics.summary.first_response_sla.rate
                                "
                                :previous="
                                    analytics.summary.first_response_sla
                                        .previous_rate
                                "
                                :previous-label="
                                    analytics.summary.first_response_sla
                                        .previous_rate === null
                                        ? null
                                        : `${analytics.summary.first_response_sla.previous_rate} %`
                                "
                                better="higher"
                                :hint="
                                    slaHint(
                                        analytics.summary.first_response_sla,
                                    )
                                "
                            />
                            <KpiCard
                                v-if="analytics.summary.resolution_sla"
                                label="Objetivo de resolución"
                                :icon="Target"
                                :value="
                                    analytics.summary.resolution_sla.rate ===
                                    null
                                        ? null
                                        : `${analytics.summary.resolution_sla.rate} %`
                                "
                                :current="analytics.summary.resolution_sla.rate"
                                :previous="
                                    analytics.summary.resolution_sla
                                        .previous_rate
                                "
                                :previous-label="
                                    analytics.summary.resolution_sla
                                        .previous_rate === null
                                        ? null
                                        : `${analytics.summary.resolution_sla.previous_rate} %`
                                "
                                better="higher"
                                :hint="
                                    slaHint(analytics.summary.resolution_sla)
                                "
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
                            hint="Conversaciones reabiertas al menos una vez"
                        />
                        <KpiCard
                            label="Transferencias"
                            :icon="ArrowLeftRight"
                            :value="count(analytics.summary.transfers.value)"
                            :current="analytics.summary.transfers.value"
                            :previous="analytics.summary.transfers.previous"
                            hint="Total de transferencias realizadas"
                        />
                        <KpiCard
                            label="Fuera de horario"
                            :icon="MoonStar"
                            :value="count(analytics.summary.out_of_hours.value)"
                            :current="analytics.summary.out_of_hours.value"
                            :previous="analytics.summary.out_of_hours.previous"
                            unavailable-label="Sin horario laboral configurado"
                            :hint="
                                analytics.summary.out_of_hours.value === null
                                    ? 'Activa el horario en Configuración o en el departamento'
                                    : `De ${analytics.summary.out_of_hours_measurable.toLocaleString('es-MX')} con horario aplicable`
                            "
                        />
                        <KpiCard
                            label="Errores de envío"
                            :icon="Send"
                            :value="count(analytics.summary.failed_out.value)"
                            :current="analytics.summary.failed_out.value"
                            :previous="analytics.summary.failed_out.previous"
                            better="lower"
                            :hint="
                                analytics.summary.failure_rate.value === null
                                    ? 'Sin mensajes enviados en el periodo'
                                    : `${analytics.summary.failure_rate.value} % de ${(analytics.summary.messages_out.value ?? 0).toLocaleString('es-MX')} enviados · ${(analytics.summary.messages_in.value ?? 0).toLocaleString('es-MX')} recibidos`
                            "
                        />
                    </div>
                </section>

                <SurfaceCard
                    title="Horarios de mayor demanda"
                    description="Conversaciones recibidas por día de la semana y hora"
                    body-class="p-4 sm:p-5"
                    data-tour="reports.heatmap"
                >
                    <HourHeatmap
                        :cells="analytics.heatmap.cells"
                        :max="analytics.heatmap.max"
                    />
                </SurfaceCard>

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
                            :description="`${breakdown.title}: recibidas y atendidas de los ${Math.min(BAR_LIMIT, breakdown.rows.length)} con más conversaciones`"
                        />
                        <ChartLegend :items="breakdownLegend" />
                    </div>
                    <BreakdownTable
                        :rows="breakdown.rows"
                        :dimension="breakdown.dimension"
                        :show-load="breakdown.showLoad"
                    />
                </SurfaceCard>
            </div>
        </Deferred>
    </div>
</template>
