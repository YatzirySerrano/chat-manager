<script setup lang="ts">
import { computed } from 'vue';
import {
    chartTokens,
    formatBucket,
    formatDuration,
    granularityNoun,
} from '@/composables/useChartTheme';
import type { ResponseTrend } from '@/types';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import ChartLegend from './ChartLegend.vue';
import LineChart from './LineChart.vue';
import type { ChartTableColumn, LineSeries } from './types';

/**
 * «¿Estamos respondiendo más rápido?»: average wait until the first human
 * response per bucket, against the aligned bucket of the previous period.
 * A bucket without measured responses is a gap (never 0 s).
 */
const props = defineProps<{
    trend: ResponseTrend;
    /** e.g. «Promedio 4 min 10 s · antes 6 min (n = 342)». */
    total?: string;
    previousLabel: string;
    storageKey: string;
}>();

const noun = computed(() => granularityNoun(props.trend.granularity));

const labels = computed(() =>
    props.trend.points.map((point) => ({
        short: point.label,
        long: formatBucket(point.key, props.trend.granularity).long,
    })),
);

const series = computed<LineSeries[]>(() => [
    {
        key: 'current',
        name: 'Este periodo',
        values: props.trend.points.map((point) => point.seconds),
        color: chartTokens.primary,
    },
    {
        key: 'previous',
        name: `Periodo anterior (${props.previousLabel})`,
        values: props.trend.points.map((point) => point.previous_seconds),
        color: chartTokens.comparison,
        dashed: true,
    },
]);

const legend = computed(() =>
    series.value.map((item) => ({
        key: item.key,
        label: item.name,
        color: item.color ?? chartTokens.primary,
        shape: item.dashed ? ('dashed' as const) : ('line' as const),
    })),
);

const empty = computed(() =>
    props.trend.points.every(
        (point) => point.seconds === null && point.previous_seconds === null,
    ),
);

const rows = computed(() =>
    props.trend.points.map((point, index) => ({
        ...point,
        period: labels.value[index]?.long ?? point.label,
    })),
);

const seconds = (value: unknown): string =>
    typeof value === 'number' ? formatDuration(value) : 'Sin datos';

const columns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    {
        key: 'seconds',
        label: 'Espera promedio',
        align: 'right',
        format: seconds,
    },
    { key: 'samples', label: 'Solicitudes medidas (n)', align: 'right' },
    {
        key: 'previous_seconds',
        label: 'Periodo anterior',
        align: 'right',
        format: seconds,
    },
    {
        key: 'previous_samples',
        label: 'Medidas antes (n)',
        align: 'right',
    },
];
</script>

<template>
    <ChartCard
        title="¿Estamos respondiendo más rápido?"
        :description="`Espera promedio hasta la primera respuesta de una persona, por ${noun}, contra el periodo anterior (más bajo es mejor)`"
        :total="total"
        :empty="empty"
        empty-text="Sin primeras respuestas medidas en este periodo ni en el anterior"
        :storage-key="storageKey"
        footnote="Los huecos son periodos sin respuestas medidas."
        data-chart="response-trend"
    >
        <LineChart
            :labels="labels"
            :series="series"
            y-format="duration"
            :description="`Espera promedio hasta la primera respuesta por ${noun}, este periodo y el anterior`"
        />
        <template #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable
                caption="Espera hasta la primera respuesta"
                :columns="columns"
                :rows="rows"
            />
        </template>
    </ChartCard>
</template>
