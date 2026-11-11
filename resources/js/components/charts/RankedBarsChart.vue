<script setup lang="ts">
import { computed } from 'vue';
import { chartTokens } from '@/composables/useChartTheme';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import ChartLegend from './ChartLegend.vue';
import type {
    BarItem,
    BarSeries,
    ChartTableColumn,
    ValueFormat,
} from './types';

/**
 * A ranked comparison (largest first) as horizontal bars inside a chart
 * card: the question it answers as description, a legend when it has two
 * or more series, the table view and an explicit empty state. Items with
 * `href` drill down to the list behind the bar.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        total?: string;
        items: BarItem[];
        series: BarSeries[];
        /** Table view: the same rows with their exact values. */
        columns: ChartTableColumn[];
        rows: Record<string, unknown>[];
        emptyText?: string;
        storageKey: string;
        footnote?: string;
        valueFormat?: ValueFormat;
    }>(),
    {
        description: undefined,
        total: undefined,
        emptyText: 'Sin datos en este periodo',
        footnote: undefined,
        valueFormat: 'number',
    },
);

const empty = computed(() =>
    props.items.every((item) => item.values.every((value) => value === 0)),
);

const legend = computed(() =>
    props.series.map((item, index) => ({
        key: item.key,
        label: item.name,
        color:
            item.color ??
            (index === 0 ? chartTokens.primary : chartTokens.secondary),
        shape: 'square' as const,
    })),
);
</script>

<template>
    <ChartCard
        :title="title"
        :description="description"
        :total="total"
        :empty="empty"
        :empty-text="emptyText"
        skeleton="bars"
        :storage-key="storageKey"
        :footnote="footnote"
    >
        <BarChart
            :items="items"
            :series="series"
            :value-format="valueFormat"
            :description="`${title}: ${items.map((item) => `${item.label} ${item.values.join(' / ')}`).join(', ')}`"
        />
        <template v-if="series.length > 1" #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable :caption="title" :columns="columns" :rows="rows" />
        </template>
    </ChartCard>
</template>
