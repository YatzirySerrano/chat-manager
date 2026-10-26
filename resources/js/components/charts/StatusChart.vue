<script setup lang="ts">
import { computed } from 'vue';
import {
    formatNumber,
    formatPercent,
    statusColor,
} from '@/composables/useChartTheme';
import type { StatusChartRow } from '@/types/charts';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import ChartLegend from './ChartLegend.vue';
import type { BarItem, BarSeries, ChartTableColumn } from './types';

/**
 * Current status of the conversations received in the period: one 100 %
 * bar, its legend with count and share of each status and the table.
 */
const props = defineProps<{
    statuses: StatusChartRow[];
    storageKey: string;
}>();

const total = computed(() =>
    props.statuses.reduce((sum, row) => sum + row.count, 0),
);

function share(count: number): string {
    return formatPercent(total.value === 0 ? 0 : count / total.value);
}

const series = computed<BarSeries[]>(() =>
    props.statuses.map((row) => ({
        key: row.status,
        name: row.label,
        color: statusColor(row.status),
    })),
);

const items = computed<BarItem[]>(() => [
    {
        key: 'received',
        label: '',
        values: props.statuses.map((row) => row.count),
    },
]);

const legend = computed(() =>
    props.statuses.map((row) => ({
        key: row.status,
        label: row.label,
        color: statusColor(row.status),
        shape: 'square' as const,
        value: `${formatNumber(row.count)} · ${share(row.count)}`,
    })),
);

const rows = computed(() =>
    props.statuses.map((row) => ({
        label: row.label,
        count: row.count,
        share: share(row.count),
    })),
);

const columns: ChartTableColumn[] = [
    { key: 'label', label: 'Estado' },
    { key: 'count', label: 'Conversaciones', align: 'right' },
    { key: 'share', label: 'Porcentaje', align: 'right' },
];
</script>

<template>
    <ChartCard
        title="Estado de lo recibido"
        description="Cómo están hoy las conversaciones que llegaron en el periodo"
        :total="
            total > 0
                ? `${formatNumber(total)} ${total === 1 ? 'conversación' : 'conversaciones'}`
                : undefined
        "
        :empty="total === 0"
        skeleton="bars"
        :storage-key="storageKey"
        data-chart="statuses"
    >
        <BarChart
            :items="items"
            :series="series"
            stacked
            :description="`Estado actual de lo recibido: ${legend.map((item) => `${item.label} ${item.value}`).join(', ')}`"
        />
        <template #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable
                caption="Estado de las conversaciones recibidas"
                :columns="columns"
                :rows="rows"
            />
        </template>
    </ChartCard>
</template>
