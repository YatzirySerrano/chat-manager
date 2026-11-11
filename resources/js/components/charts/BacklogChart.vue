<script setup lang="ts">
import { computed } from 'vue';
import {
    chartTokens,
    formatBucket,
    formatNumber,
    granularityNoun,
} from '@/composables/useChartTheme';
import type { AnalyticsBacklog } from '@/types';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import LineChart from './LineChart.vue';
import type { ChartTableColumn, LineSeries } from './types';

/**
 * «¿Se nos están acumulando solicitudes?»: conversations received and still
 * unattended (no human attention, not closed) at the end of each bucket.
 * Buckets that have not finished are gaps.
 */
const props = withDefaults(
    defineProps<{
        backlog: AnalyticsBacklog;
        storageKey: string;
        statusFiltered?: boolean;
    }>(),
    { statusFiltered: false },
);

const noun = computed(() => granularityNoun(props.backlog.granularity));

const labels = computed(() =>
    props.backlog.points.map((point) => ({
        short: point.label,
        long: formatBucket(point.key, props.backlog.granularity).long,
    })),
);

const series = computed<LineSeries[]>(() => [
    {
        key: 'backlog',
        name: 'Sin atender al cierre',
        values: props.backlog.points.map((point) => point.backlog),
        color: chartTokens.primary,
        area: true,
    },
]);

const empty = computed(
    () =>
        props.backlog.now === 0 &&
        props.backlog.start === 0 &&
        props.backlog.points.every(
            (point) => point.received === 0 && point.left === 0,
        ),
);

const total = computed(() => {
    const now = `${formatNumber(props.backlog.now)} sin atender ahora`;

    return props.backlog.end === null
        ? now
        : `${now} · ${formatNumber(props.backlog.start)} al inicio del periodo`;
});

const rows = computed(() =>
    props.backlog.points.map((point, index) => ({
        ...point,
        period: labels.value[index]?.long ?? point.label,
    })),
);

const columns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    { key: 'received', label: 'Recibidas', align: 'right' },
    { key: 'left', label: 'Atendidas o cerradas', align: 'right' },
    {
        key: 'backlog',
        label: 'Sin atender al cierre',
        align: 'right',
        format: (value) =>
            typeof value === 'number' ? formatNumber(value) : 'En curso',
    },
];
</script>

<template>
    <ChartCard
        title="¿Se nos están acumulando solicitudes?"
        :description="`Solicitudes recibidas que seguían sin atención de una persona ni cierre al terminar cada ${noun}`"
        :total="total"
        :empty="empty"
        empty-text="Sin solicitudes acumuladas ni recibidas en este periodo"
        :storage-key="storageKey"
        :footnote="
            statusFiltered
                ? 'El filtro de estado no se aplica: el acumulado describe el pasado de cada solicitud, no su estado de hoy.'
                : undefined
        "
        data-chart="backlog"
    >
        <LineChart
            :labels="labels"
            :series="series"
            :description="`Solicitudes sin atender al cierre de cada ${noun}. ${total}`"
            null-label="En curso"
        />
        <template #table>
            <ChartDataTable
                caption="Solicitudes acumuladas sin atender"
                :columns="columns"
                :rows="rows"
            />
        </template>
    </ChartCard>
</template>
