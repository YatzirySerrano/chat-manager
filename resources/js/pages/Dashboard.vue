<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CircleCheck,
    Hourglass,
    MessageCircle,
    Timer,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import ChannelChart from '@/components/charts/ChannelChart.vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import FirstResponseChart from '@/components/charts/FirstResponseChart.vue';
import LineChart from '@/components/charts/LineChart.vue';
import StatusChart from '@/components/charts/StatusChart.vue';
import TeamBarsChart from '@/components/charts/TeamBarsChart.vue';
import type { ChartTableColumn, LineSeries } from '@/components/charts/types';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import SectionHeading from '@/components/SectionHeading.vue';
import StatCard from '@/components/StatCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import {
    chartTokens,
    formatBucket,
    formatDuration,
    formatNumber,
    formatPercent,
    granularityNoun,
} from '@/composables/useChartTheme';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import { index as connectionsIndex } from '@/routes/connections';
import { index as reportsIndex } from '@/routes/reports';
import type {
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

/* ---------- Activity and first response (one bucket per hour/day/month) */

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
        return `${text} · igual que el periodo anterior`;
    }

    return `${text} · ${formatPercent(Math.abs(change))} ${change > 0 ? 'más' : 'menos'} que el periodo anterior`;
});

const measuredTotal = computed(() =>
    props.overview && props.overview.first_response_samples > 0
        ? `Medido en ${plural(props.overview.first_response_samples, 'conversación', 'conversaciones')}`
        : undefined,
);

/* ---------- Team */

const agents = computed(() => props.charts?.agents ?? null);
const departments = computed(() => props.charts?.departments ?? null);

const lineLegend = computed(() =>
    activitySeries.value.map((series) => ({
        key: series.key,
        label: series.name,
        color: series.color ?? chartTokens.primary,
        shape: 'line' as const,
    })),
);

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
    { key: 'activity', title: 'Actividad', wide: true, shape: 'line' },
    { key: 'channels', title: 'Por canal', wide: false, shape: 'bars' },
    {
        key: 'statuses',
        title: 'Estado de lo recibido',
        wide: false,
        shape: 'bars',
    },
    {
        key: 'first-response',
        title: 'Tiempo de primera respuesta',
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
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    aria-busy="true"
                    aria-label="Cargando indicadores"
                >
                    <StatCard
                        v-for="index in 4"
                        :key="index"
                        label="Cargando…"
                        :value="null"
                        loading
                    />
                </div>
            </template>

            <div
                v-if="overview"
                class="flex flex-col gap-3 transition-opacity"
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
                    aria-label="Cómo va la atención"
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                    data-tour="dashboard.kpis"
                >
                    <StatCard
                        label="Pendientes"
                        :icon="Hourglass"
                        :value="overview.conversations.pending"
                        hint="Esperan que alguien las acepte"
                        :unavailable-label="conversationsUnavailable"
                        data-kpi="pending"
                    />
                    <StatCard
                        label="En atención"
                        :icon="MessageCircle"
                        :value="overview.conversations.active"
                        hint="Con una persona responsable"
                        :unavailable-label="conversationsUnavailable"
                        data-kpi="active"
                    />
                    <StatCard
                        label="Finalizadas hoy"
                        :icon="CircleCheck"
                        :value="overview.conversations.closed_today"
                        hint="Desde las 00:00 de hoy"
                        :unavailable-label="conversationsUnavailable"
                        data-kpi="closed_today"
                    />
                    <KpiCard
                        label="Primera respuesta (promedio)"
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
                            overview.previous_first_response_seconds === null
                                ? null
                                : formatDuration(
                                      overview.previous_first_response_seconds,
                                  )
                        "
                        better="lower"
                        :hint="measuredTotal"
                        :unavailable-label="
                            overview.messaging_connected &&
                            overview.conversations_visible
                                ? 'Sin respuestas medidas en el periodo'
                                : conversationsUnavailable
                        "
                        data-kpi="first_response"
                    />
                </section>
                <p
                    v-if="
                        overview.scope !== 'all' &&
                        overview.conversations_visible
                    "
                    class="text-xs text-muted-foreground"
                >
                    Se cuentan las conversaciones que puedes ver en tu bandeja.
                </p>
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
                    <SectionHeading title="Actividad del periodo" />
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
                class="flex flex-col gap-(--section-gap) transition-opacity"
                :class="{ 'opacity-60': refreshing }"
            >
                <section
                    v-if="hasCharts"
                    aria-labelledby="activity-heading"
                    class="flex flex-col gap-3"
                >
                    <SectionHeading
                        id="activity-heading"
                        title="Actividad del periodo"
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
                        <ChartCard
                            v-if="activity"
                            class="lg:col-span-2"
                            title="Actividad"
                            :description="`Conversaciones recibidas y atendidas (de las recibidas) por ${noun}`"
                            :total="receivedTotal"
                            :empty="activityEmpty"
                            empty-text="Sin conversaciones recibidas en este periodo"
                            storage-key="dashboard.activity"
                            data-tour="dashboard.activity"
                            data-chart="activity"
                        >
                            <LineChart
                                :labels="bucketLabels"
                                :series="activitySeries"
                                :description="`Conversaciones recibidas y atendidas por ${noun}. ${receivedTotal ?? ''}`"
                            />
                            <template #legend>
                                <ChartLegend :items="lineLegend" />
                            </template>
                            <template #table>
                                <ChartDataTable
                                    caption="Conversaciones recibidas y atendidas"
                                    :columns="activityColumns"
                                    :rows="bucketRows"
                                />
                            </template>
                        </ChartCard>

                        <ChannelChart
                            v-if="charts?.channels"
                            :channels="charts.channels"
                            storage-key="dashboard.channels"
                        />

                        <StatusChart
                            v-if="charts?.statuses"
                            :statuses="charts.statuses"
                            storage-key="dashboard.statuses"
                        />

                        <FirstResponseChart
                            v-if="activity"
                            class="lg:col-span-2"
                            :activity="activity"
                            :total="measuredTotal"
                            storage-key="dashboard.first-response"
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
                        description="Los que más conversaciones recibieron; el resto se agrupa en «Otros»."
                    />
                    <div class="grid gap-3 lg:grid-cols-2">
                        <TeamBarsChart
                            v-if="agents"
                            title="Atención por agente"
                            description="Recibidas y atendidas por agente responsable (actual o último)"
                            dimension="Agente"
                            :rows="agents"
                            second="attended"
                            storage-key="dashboard.agents"
                            data-tour="dashboard.team"
                            data-chart="agents"
                        />
                        <TeamBarsChart
                            v-if="departments"
                            title="Por departamento"
                            description="Recibidas y resueltas por departamento actual"
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
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
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
