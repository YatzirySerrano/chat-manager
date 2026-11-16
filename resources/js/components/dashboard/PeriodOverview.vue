<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import LineChart from '@/components/charts/LineChart.vue';
import type { ChartTableColumn, LineSeries } from '@/components/charts/types';
import GranularityToggle from '@/components/reports/GranularityToggle.vue';
import '@/components/reports/insights.css';
import { NumberTicker } from '@/components/ui/number-ticker';
import {
    chartTokens,
    formatBucket,
    formatDuration,
    formatNumber,
    formatPercent,
    granularityNoun,
} from '@/composables/useChartTheme';
import type {
    AnalyticsActivity,
    AnalyticsGranularity,
    AnalyticsPeriod,
    DashboardOverview,
    ResponseTrend,
} from '@/types';

/**
 * The hero of the Panel principal: the four figures of the period as tabs
 * (Stripe-like) over one large chart of the chosen figure per bucket. Each
 * tab shows its value (counting up), the change against the comparison
 * period and, for the first response, the previous period as a dashed line.
 * Tabs follow the WAI-ARIA tabs pattern (arrows, Home, End); the panel
 * carries the exact numbers as a visually hidden table.
 */
const props = defineProps<{
    overview: DashboardOverview;
    activity: AnalyticsActivity | null;
    responseTrend: ResponseTrend | null;
    period: AnalyticsPeriod | undefined;
    /** The chart data is still on its way (deferred group «charts»). */
    loading?: boolean;
}>();

const skeletonHeights = [
    38, 52, 46, 64, 58, 72, 50, 66, 80, 62, 70, 56, 74, 68,
];

const emit = defineEmits<{ granularity: [value: AnalyticsGranularity] }>();

type MetricKey = 'received' | 'attended' | 'resolved' | 'first_response';

type Metric = {
    key: MetricKey;
    label: string;
    value: number | null;
    previous: number | null;
    better: 'higher' | 'lower' | 'neutral';
    duration?: boolean;
    hint: string;
};

const metrics = computed<Metric[]>(() => {
    const overview = props.overview;
    const received = overview.received.value;

    function share(part: number | null): string {
        return part !== null && received
            ? `${formatPercent(part / received)} de las recibidas`
            : 'De las recibidas en el periodo';
    }

    return [
        {
            key: 'received',
            label: 'Recibidas',
            value: received,
            previous: overview.received.previous,
            better: 'neutral',
            hint: 'Solicitudes nuevas en el periodo',
        },
        {
            key: 'attended',
            label: 'Atendidas',
            value: overview.period?.attended.value ?? null,
            previous: overview.period?.attended.previous ?? null,
            better: 'higher',
            hint: share(overview.period?.attended.value ?? null),
        },
        {
            key: 'resolved',
            label: 'Finalizadas',
            value: overview.period?.resolved.value ?? null,
            previous: overview.period?.resolved.previous ?? null,
            better: 'higher',
            hint: share(overview.period?.resolved.value ?? null),
        },
        {
            key: 'first_response',
            label: 'Primera respuesta',
            value: overview.average_first_response_seconds,
            previous: overview.previous_first_response_seconds,
            better: 'lower',
            duration: true,
            hint:
                overview.first_response_samples > 0
                    ? `Promedio de ${formatNumber(overview.first_response_samples)} medidas`
                    : 'Ninguna respuesta medida',
        },
    ];
});

const selected = ref<MetricKey>('received');
const current = computed(
    () =>
        metrics.value.find((metric) => metric.key === selected.value) ??
        metrics.value[0]!,
);

function change(metric: Metric): {
    text: string;
    direction: 'up' | 'down' | 'flat';
    tone: 'good' | 'bad' | 'neutral';
} | null {
    if (metric.value === null || metric.previous === null) {
        return null;
    }

    if (metric.previous === 0) {
        return metric.value === 0
            ? { text: 'Igual', direction: 'flat', tone: 'neutral' }
            : null;
    }

    const ratio = (metric.value - metric.previous) / metric.previous;

    if (Math.abs(ratio) < 0.005) {
        return { text: 'Igual', direction: 'flat', tone: 'neutral' };
    }

    const direction = ratio > 0 ? 'up' : 'down';
    const improved = (direction === 'up') === (metric.better === 'higher');

    return {
        text: `${ratio > 0 ? '+' : '−'}${formatPercent(Math.abs(ratio))}`,
        direction,
        tone:
            metric.better === 'neutral' ? 'neutral' : improved ? 'good' : 'bad',
    };
}

function previousText(metric: Metric): string {
    if (metric.previous === null) {
        return '';
    }

    return metric.duration
        ? formatDuration(metric.previous)
        : formatNumber(metric.previous);
}

/* ---------- Tabs keyboard (roving focus) */

const tabsId = useId();
const tabRefs = ref<HTMLButtonElement[]>([]);

function onKeydown(event: KeyboardEvent, index: number): void {
    const last = metrics.value.length - 1;
    const next = {
        ArrowRight: index === last ? 0 : index + 1,
        ArrowLeft: index === 0 ? last : index - 1,
        Home: 0,
        End: last,
    }[event.key];

    if (next === undefined) {
        return;
    }

    event.preventDefault();
    selected.value = metrics.value[next]!.key;
    tabRefs.value[next]?.focus();
}

/* ---------- Chart of the chosen figure */

const points = computed(() => props.activity?.points ?? []);
const granularity = computed(
    () => props.activity?.granularity ?? props.overview.trend_granularity,
);
const noun = computed(() => granularityNoun(granularity.value));

const labels = computed(() =>
    points.value.map((point) => ({
        short: point.label,
        long: formatBucket(point.key, granularity.value).long,
    })),
);

const series = computed<LineSeries[]>(() => {
    switch (selected.value) {
        case 'attended':
            return [
                {
                    key: 'attended',
                    name: 'Atendidas',
                    values: points.value.map((point) => point.attended),
                    color: chartTokens.primary,
                    area: true,
                },
            ];
        case 'resolved':
            return [
                {
                    key: 'resolved',
                    name: 'Finalizadas',
                    values: points.value.map((point) => point.resolved ?? 0),
                    color: chartTokens.primary,
                    area: true,
                },
            ];
        case 'first_response': {
            const trend = props.responseTrend?.points ?? [];
            const hasPrevious = trend.some(
                (point) => point.previous_seconds !== null,
            );

            return [
                {
                    key: 'current',
                    name: 'Este periodo',
                    values: points.value.map(
                        (point) => point.first_response_seconds,
                    ),
                    color: chartTokens.primary,
                    area: true,
                },
                ...(hasPrevious
                    ? [
                          {
                              key: 'previous',
                              name: 'Periodo anterior',
                              values: points.value.map(
                                  (_, index) =>
                                      trend[index]?.previous_seconds ?? null,
                              ),
                              color: chartTokens.comparison,
                              dashed: true,
                          },
                      ]
                    : []),
            ];
        }
        default:
            return [
                {
                    key: 'received',
                    name: 'Recibidas',
                    values: points.value.map((point) => point.received),
                    color: chartTokens.primary,
                    area: true,
                },
            ];
    }
});

const empty = computed(() =>
    series.value[0]!.values.every((value) => value === null || value === 0),
);

const legend = computed(() =>
    series.value.length > 1
        ? series.value.map((item) => ({
              key: item.key,
              label: item.name,
              color: item.color ?? chartTokens.primary,
              shape: item.dashed ? ('dashed' as const) : ('line' as const),
          }))
        : [],
);

const tableColumns = computed<ChartTableColumn[]>(() => [
    { key: 'period', label: 'Periodo' },
    ...series.value.map((item) => ({
        key: item.key,
        label: item.name,
        align: 'right' as const,
        format: current.value.duration
            ? (value: unknown) =>
                  typeof value === 'number' ? formatDuration(value) : '—'
            : undefined,
    })),
]);

const tableRows = computed(() =>
    labels.value.map((label, index) => ({
        period: label.long,
        ...Object.fromEntries(
            series.value.map((item) => [item.key, item.values[index] ?? null]),
        ),
    })),
);

const emptyText = computed(() =>
    selected.value === 'first_response'
        ? 'Sin respuestas medidas en este periodo'
        : 'Sin solicitudes en este periodo',
);
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border bg-card shadow-(--shadow-control)"
        aria-label="Resultado del periodo"
        data-period-overview
        data-tour="dashboard.activity"
    >
        <div
            class="grid grid-cols-2 border-b lg:grid-cols-4"
            role="tablist"
            aria-label="Cifra del periodo"
        >
            <button
                v-for="(metric, index) in metrics"
                :id="`${tabsId}-tab-${metric.key}`"
                :key="metric.key"
                ref="tabRefs"
                type="button"
                role="tab"
                :aria-selected="selected === metric.key"
                :aria-controls="`${tabsId}-panel`"
                :tabindex="selected === metric.key ? 0 : -1"
                class="overview-tab group relative flex min-w-0 flex-col items-start gap-1 px-4 py-3.5 text-left outline-none focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset motion-safe:transition-colors sm:px-5"
                :class="[
                    selected === metric.key
                        ? 'bg-card'
                        : 'bg-surface-sunken/40 hover:bg-card',
                    index % 2 === 1 && 'border-l',
                    index > 1 && 'border-t lg:border-t-0',
                    index === 2 && 'lg:border-l',
                ]"
                :title="metric.hint"
                :data-kpi="metric.key"
                @click="selected = metric.key"
                @keydown="onKeydown($event, index)"
            >
                <span
                    class="overview-tab-bar absolute inset-x-0 top-0 h-0.5 bg-primary"
                    :data-active="selected === metric.key || undefined"
                    aria-hidden="true"
                />
                <span
                    class="text-[0.8125rem] font-medium"
                    :class="
                        selected === metric.key
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                    >{{ metric.label }}</span
                >
                <span
                    class="font-display text-2xl leading-8 font-semibold tracking-tight tabular-nums sm:text-[1.75rem]"
                    data-kpi-value
                >
                    <template v-if="metric.value === null">
                        <span class="text-base text-muted-foreground"
                            >Sin datos</span
                        >
                    </template>
                    <template v-else-if="metric.duration">{{
                        formatDuration(metric.value)
                    }}</template>
                    <NumberTicker v-else :value="metric.value" />
                </span>
                <span
                    class="flex min-h-4 w-full flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                >
                    <template v-if="change(metric)">
                        <span
                            class="kpi-delta"
                            :data-tone="change(metric)!.tone"
                        >
                            <ArrowUpRight
                                v-if="change(metric)!.direction === 'up'"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <ArrowDownRight
                                v-else-if="change(metric)!.direction === 'down'"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <Minus v-else class="size-3.5" aria-hidden="true" />
                            {{ change(metric)!.text }}
                        </span>
                        <span class="tabular-nums"
                            >vs. {{ previousText(metric) }}</span
                        >
                    </template>
                    <template v-else-if="metric.value !== null">
                        Sin comparación
                    </template>
                </span>
            </button>
        </div>

        <div
            :id="`${tabsId}-panel`"
            role="tabpanel"
            :aria-labelledby="`${tabsId}-tab-${selected}`"
            class="flex flex-col gap-3 p-4 sm:p-5"
            data-chart="activity"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-muted-foreground">
                    {{ current.hint }} · por {{ noun }}
                </p>
                <GranularityToggle
                    v-if="period && granularity !== 'hour'"
                    :value="period.granularity"
                    :options="period.granularities"
                    @change="emit('granularity', $event)"
                />
            </div>

            <div
                v-if="loading"
                class="flex h-[200px] items-end gap-1.5 border-b pb-px sm:h-[280px]"
                aria-hidden="true"
            >
                <span
                    v-for="(height, index) in skeletonHeights"
                    :key="index"
                    class="flex-1 shimmer rounded-t-[4px]"
                    :style="{ height: `${height}%`, opacity: 0.5 }"
                />
            </div>
            <div
                v-else-if="empty"
                class="flex h-[200px] items-center justify-center rounded-lg bg-surface-sunken/50 text-sm text-muted-foreground sm:h-[280px]"
            >
                {{ emptyText }}
            </div>
            <LineChart
                v-else
                :key="selected"
                :labels="labels"
                :series="series"
                :y-format="current.duration ? 'duration' : 'number'"
                size="tall"
                :description="`${current.label} por ${noun}, ${period?.label ?? ''}`"
            />
            <ChartLegend v-if="legend.length > 0 && !empty" :items="legend" />
            <div class="sr-only">
                <ChartDataTable
                    :caption="`${current.label} por ${noun}`"
                    :columns="tableColumns"
                    :rows="tableRows"
                />
            </div>
        </div>
    </section>
</template>

<style scoped>
/* The accent bar of the chosen tab grows from the center. */
.overview-tab-bar {
    transform: scaleX(0);
    transition: transform var(--duration-base, 200ms)
        var(--ease-out-quint, ease-out);
}

.overview-tab-bar[data-active] {
    transform: scaleX(1);
}
</style>
