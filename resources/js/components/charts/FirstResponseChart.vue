<script setup lang="ts">
import { computed } from 'vue';
import {
    chartTokens,
    formatBucket,
    formatDuration,
    granularityNoun,
} from '@/composables/useChartTheme';
import type { AnalyticsActivity } from '@/types/charts';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import LineChart from './LineChart.vue';
import type { ChartTableColumn, LineSeries } from './types';

/**
 * Average first human response per bucket. Buckets without a measured
 * response are gaps in the line (never 0 s) and «—» in the table.
 */
const props = defineProps<{
    activity: AnalyticsActivity;
    /** e.g. «Medido en 342 conversaciones». */
    total?: string;
    storageKey: string;
}>();

const noun = computed(() => granularityNoun(props.activity.granularity));

const labels = computed(() =>
    props.activity.points.map((point) => ({
        short: point.label,
        long: formatBucket(point.key, props.activity.granularity).long,
    })),
);

const series = computed<LineSeries[]>(() => [
    {
        key: 'first_response',
        name: 'Primera respuesta (promedio)',
        values: props.activity.points.map(
            (point) => point.first_response_seconds,
        ),
        color: chartTokens.primary,
    },
]);

const empty = computed(() =>
    props.activity.points.every(
        (point) => point.first_response_seconds === null,
    ),
);

const rows = computed(() =>
    props.activity.points.map((point, index) => ({
        period: labels.value[index]?.long ?? point.label,
        first_response_seconds: point.first_response_seconds,
        first_response_samples: point.first_response_samples,
    })),
);

const columns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    {
        key: 'first_response_seconds',
        label: 'Promedio',
        align: 'right',
        format: (value) =>
            typeof value === 'number' ? formatDuration(value) : '—',
    },
    {
        key: 'first_response_samples',
        label: 'Conversaciones medidas',
        align: 'right',
    },
];
</script>

<template>
    <ChartCard
        title="Tiempo de primera respuesta"
        :description="`Promedio por ${noun}. Los periodos sin respuestas medidas no se dibujan.`"
        :total="empty ? undefined : total"
        :empty="empty"
        empty-text="Aún no hay respuestas medidas en este periodo"
        :storage-key="storageKey"
        data-chart="first-response"
    >
        <LineChart
            :labels="labels"
            :series="series"
            y-format="duration"
            null-label="Sin respuestas medidas"
            :description="`Tiempo promedio de primera respuesta por ${noun}`"
        />
        <template #table>
            <ChartDataTable
                caption="Tiempo promedio de primera respuesta"
                :columns="columns"
                :rows="rows"
            />
        </template>
    </ChartCard>
</template>
