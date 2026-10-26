<script setup lang="ts">
import { computed } from 'vue';
import { chartTokens, formatDuration } from '@/composables/useChartTheme';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import ChartLegend from './ChartLegend.vue';
import type { BarItem, BarSeries, ChartTableColumn } from './types';

/**
 * Received vs. a second figure (attended or resolved) per agent or
 * department, as grouped horizontal bars on one scale, plus the table with
 * the first response average and how many conversations it measures.
 */
type TeamRow = {
    key: string;
    id: number | null;
    name: string;
    received: number;
    first_response_seconds: number | null;
    first_response_samples: number;
    attended?: number;
    resolved?: number;
};

const props = defineProps<{
    title: string;
    description: string;
    dimension: string;
    rows: TeamRow[];
    second: 'attended' | 'resolved';
    storageKey: string;
}>();

const secondLabel = computed(() =>
    props.second === 'attended' ? 'Atendidas' : 'Resueltas',
);

const series = computed<BarSeries[]>(() => [
    { key: 'received', name: 'Recibidas', color: chartTokens.primary },
    {
        key: props.second,
        name: secondLabel.value,
        color: chartTokens.secondary,
    },
]);

const items = computed<BarItem[]>(() =>
    props.rows.map((row) => ({
        key: row.key,
        label: row.name,
        values: [row.received, row[props.second] ?? 0],
        muted: row.id === null,
    })),
);

const empty = computed(() =>
    items.value.every((item) => item.values.every((value) => value === 0)),
);

const legend = computed(() =>
    series.value.map((item) => ({
        key: item.key,
        label: item.name,
        color: item.color ?? chartTokens.primary,
        shape: 'square' as const,
    })),
);

const columns = computed<ChartTableColumn[]>(() => [
    { key: 'name', label: props.dimension },
    { key: 'received', label: 'Recibidas', align: 'right' },
    { key: props.second, label: secondLabel.value, align: 'right' },
    {
        key: 'first_response_seconds',
        label: 'Primera respuesta (promedio)',
        align: 'right',
        format: (value) =>
            typeof value === 'number' ? formatDuration(value) : '—',
    },
    {
        key: 'first_response_samples',
        label: 'Conversaciones medidas',
        align: 'right',
    },
]);
</script>

<template>
    <ChartCard
        :title="title"
        :description="description"
        :empty="empty"
        skeleton="bars"
        :storage-key="storageKey"
    >
        <BarChart
            :items="items"
            :series="series"
            :description="`${title}: ${rows.map((row) => `${row.name} ${row.received} recibidas`).join(', ')}`"
        />
        <template #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable :caption="title" :columns="columns" :rows="rows" />
        </template>
    </ChartCard>
</template>
