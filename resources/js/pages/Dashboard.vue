<script setup lang="ts">
import {
    Deferred,
    Head,
    Link,
    router,
    usePage,
    usePoll,
} from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import BacklogChart from '@/components/charts/BacklogChart.vue';
import ChannelChart from '@/components/charts/ChannelChart.vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import RankedBarsChart from '@/components/charts/RankedBarsChart.vue';
import SlaComplianceChart from '@/components/charts/SlaComplianceChart.vue';
import StatusChart from '@/components/charts/StatusChart.vue';
import TeamBarsChart from '@/components/charts/TeamBarsChart.vue';
import type {
    BarItem,
    BarSeries,
    ChartTableColumn,
} from '@/components/charts/types';
import LiveStrip from '@/components/dashboard/LiveStrip.vue';
import type { LiveFigure } from '@/components/dashboard/LiveStrip.vue';
import PeriodOverview from '@/components/dashboard/PeriodOverview.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import HourHeatmap from '@/components/reports/HourHeatmap.vue';
import InsightHeading from '@/components/reports/InsightHeading.vue';
import StatusNote from '@/components/StatusNote.vue';
import { chartTokens, formatNumber } from '@/composables/useChartTheme';
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

/*
 * The live figures and the charts refresh in the background every minute
 * (the server caches them for 30 s): numbers count and lines morph to the
 * new values instead of jumping. Paused while the tab is hidden.
 */
usePoll(
    60_000,
    { only: ['overview', 'charts'] },
    { autoStart: props.canViewMetrics },
);

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

/** Drill-down links only make sense for who can open the inbox. */
const canOpenInbox = computed(() => can('conversations.view'));
const statusFiltered = computed(() => props.filters?.status !== undefined);

/* ---------- Now: the inbox in real time, as one strip */

const liveFigures = computed<LiveFigure[]>(() => {
    const overview = props.overview;

    if (!overview) {
        return [];
    }

    const {
        pending,
        active,
        closed_today: closedToday,
    } = overview.conversations;
    const figures: LiveFigure[] = [
        {
            key: 'pending',
            label: 'Pendientes',
            value: pending,
            hint: 'Esperan que alguien las acepte',
            tone: 'attention',
            href:
                canOpenInbox.value && pending
                    ? inboxLink(props.filters, { status: 'pending' })
                    : undefined,
        },
        {
            key: 'active',
            label: 'En atención',
            value: active,
            hint: 'Con una persona responsable',
            href:
                canOpenInbox.value && active
                    ? inboxLink(props.filters, { status: 'open' })
                    : undefined,
        },
        {
            key: 'closed_today',
            label: 'Finalizadas hoy',
            value: closedToday,
            hint: 'Cerradas desde las 00:00 de hoy y que siguen cerradas',
        },
    ];

    if (overview.departments !== null) {
        figures.push(
            {
                key: 'agents',
                label: 'Agentes activos',
                value: overview.agents.active,
                hint: 'Cuentas activas que pueden atender conversaciones',
            },
            {
                key: 'departments',
                label: 'Departamentos',
                value: overview.departments.active,
                hint: `${formatNumber(overview.departments.active)} activos de ${formatNumber(overview.departments.total)}`,
            },
        );
    }

    return figures;
});

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
        color: 'var(--warning)',
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

/* ---------- Categories: received with the finished part inside */

const categorySeries: BarSeries[] = [
    { key: 'received', name: 'Recibidas', color: chartTokens.primary },
    { key: 'resolved', name: 'Finalizadas', color: chartTokens.primary },
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

const hasCharts = computed(
    () =>
        !!props.charts &&
        (props.charts.activity !== null ||
            props.charts.channels !== null ||
            props.charts.statuses !== null),
);

/** Cards of the charts group while it loads (the hero has its own skeleton). */
const loadingCards = [
    { key: 'heatmap', title: '¿A qué hora llegan?', wide: true, shape: 'line' },
    { key: 'channels', title: 'Por canal', wide: false, shape: 'bars' },
    {
        key: 'workload',
        title: '¿Quién tiene más pendientes?',
        wide: false,
        shape: 'bars',
    },
    {
        key: 'backlog',
        title: '¿Se acumulan solicitudes?',
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
            <template v-if="canViewMetrics" #actions>
                <Link
                    v-if="canViewReports"
                    :href="reportsIndex({ query: filters })"
                    class="group/reports inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-muted-foreground outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-colors"
                    data-tour="dashboard.reports-link"
                >
                    Ver reportes
                    <ArrowRight
                        class="size-4 transition-transform duration-(--duration-base) group-hover/reports:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
                <span
                    v-if="canExport"
                    data-tour="dashboard.export"
                    class="inline-flex"
                >
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
                    class="flex flex-col gap-3"
                    role="status"
                    aria-busy="true"
                    aria-label="Cargando indicadores"
                >
                    <div class="h-[74px] shimmer rounded-xl border" />
                    <div class="h-[420px] shimmer rounded-xl border" />
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

                <LiveStrip
                    :figures="liveFigures"
                    :unavailable-label="conversationsUnavailable"
                />
                <p
                    v-if="
                        statusFiltered ||
                        (overview.scope !== 'all' &&
                            overview.conversations_visible)
                    "
                    class="-mt-1 text-xs text-muted-foreground"
                >
                    <template v-if="statusFiltered"
                        >El filtro de estado no se aplica a las cifras en
                        vivo.</template
                    >
                    <template
                        v-if="
                            overview.scope !== 'all' &&
                            overview.conversations_visible
                        "
                    >
                        Se cuentan las conversaciones que puedes ver en tu
                        bandeja.</template
                    >
                </p>

                <PeriodOverview
                    v-if="overview.period"
                    :overview="overview"
                    :activity="charts?.activity ?? null"
                    :response-trend="charts?.response_trend ?? null"
                    :period="period"
                    :loading="charts === undefined"
                    @granularity="changeGranularity"
                />

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
                <div class="grid gap-3 lg:grid-cols-3" aria-busy="true">
                    <ChartCard
                        v-for="card in loadingCards"
                        :key="card.key"
                        :class="card.wide && 'lg:col-span-2'"
                        :title="card.title"
                        :skeleton="card.shape"
                        loading
                    />
                </div>
            </template>

            <div
                v-if="charts && hasCharts"
                class="flex flex-col gap-(--section-gap) transition-opacity"
                :class="{ 'opacity-60': refreshing }"
            >
                <section
                    aria-labelledby="demand-heading"
                    class="flex flex-col gap-3"
                >
                    <InsightHeading
                        id="demand-heading"
                        title="Demanda"
                        description="Cuándo llegan las solicitudes y por dónde."
                    />
                    <div class="grid gap-3 lg:grid-cols-3">
                        <ChartCard
                            v-if="charts.heatmap"
                            class="lg:col-span-2"
                            title="¿En qué horario llegan más solicitudes?"
                            description="Recibidas en el periodo por día de la semana y hora"
                            :empty="charts.heatmap.max === 0"
                            empty-text="Sin solicitudes recibidas en este periodo"
                            data-chart="heatmap"
                        >
                            <HourHeatmap
                                :cells="charts.heatmap.cells"
                                :max="charts.heatmap.max"
                            />
                        </ChartCard>
                        <div class="flex min-w-0 flex-col gap-3">
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
                    </div>
                </section>

                <section
                    aria-labelledby="load-heading"
                    class="flex flex-col gap-3"
                >
                    <InsightHeading
                        id="load-heading"
                        title="Carga de trabajo"
                        description="Qué hay pendiente y en manos de quién."
                    />
                    <div class="grid gap-3 lg:grid-cols-2">
                        <RankedBarsChart
                            v-if="workload"
                            title="¿Quién tiene más solicitudes pendientes?"
                            :description="`Asignadas ahora y sin cerrar, en vivo${statusFiltered ? ' (el filtro de estado no se aplica)' : ''}. El nombre abre su lista en la bandeja.`"
                            :total="workloadTotal"
                            :items="workloadItems"
                            :series="workloadSeries"
                            :columns="workloadColumns"
                            :rows="workload.rows"
                            empty-text="Nadie tiene solicitudes asignadas en este momento"
                            summed
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
                    v-if="sla"
                    aria-labelledby="sla-heading"
                    class="flex flex-col gap-3"
                >
                    <InsightHeading
                        id="sla-heading"
                        title="Cumplimiento del SLA"
                        :description="period?.label"
                    />
                    <div class="grid gap-3 lg:grid-cols-3">
                        <SlaComplianceChart
                            :sla="sla"
                            :status-filtered="statusFiltered"
                            storage-key="dashboard.sla"
                            data-tour="dashboard.sla"
                        />
                        <RankedBarsChart
                            class="lg:col-span-2"
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
                    aria-labelledby="team-heading"
                    class="flex flex-col gap-3"
                >
                    <InsightHeading
                        id="team-heading"
                        title="Equipo y temas"
                        description="Solicitudes del periodo por responsable, departamento y categoría actuales; el resto se agrupa en «Otros»."
                    />
                    <div class="grid gap-3 lg:grid-cols-3">
                        <TeamBarsChart
                            v-if="agents"
                            title="Por responsable"
                            description="Atendidas de las recibidas, según quién es hoy (o fue al cerrar) su responsable"
                            dimension="Responsable actual"
                            :rows="agents"
                            second="attended"
                            storage-key="dashboard.agents"
                            data-tour="dashboard.team"
                            data-chart="agents"
                        />
                        <TeamBarsChart
                            v-if="departments"
                            title="Por departamento"
                            description="Finalizadas de las recibidas, por departamento actual"
                            dimension="Departamento"
                            :rows="departments"
                            second="resolved"
                            storage-key="dashboard.departments"
                            data-chart="departments"
                        />
                        <RankedBarsChart
                            v-if="charts.categories"
                            title="¿Qué piden más los clientes?"
                            description="Finalizadas de las recibidas, por categoría actual"
                            :items="categoryItems"
                            :series="categorySeries"
                            :columns="categoryColumns"
                            :rows="charts.categories"
                            overlay
                            empty-text="Sin solicitudes recibidas en este periodo"
                            storage-key="dashboard.categories"
                            data-chart="categories"
                        />
                    </div>
                </section>
            </div>
        </Deferred>
    </div>
</template>
