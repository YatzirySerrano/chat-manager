<script setup lang="ts">
import { computed } from 'vue';
import { channelColor, formatNumber } from '@/composables/useChartTheme';
import type { ChannelChartRow } from '@/types/charts';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import type { BarItem, ChartTableColumn } from './types';

/**
 * Conversations received per channel (only channels with data; each
 * channel keeps its own color whatever else is shown).
 */
const props = defineProps<{
    channels: ChannelChartRow[];
    storageKey: string;
}>();

const items = computed<BarItem[]>(() =>
    props.channels.map((row) => ({
        key: row.channel ?? 'unknown',
        label: row.label,
        values: [row.received],
        color: channelColor(row.channel),
        muted: row.channel === null,
    })),
);

const columns: ChartTableColumn[] = [
    { key: 'label', label: 'Canal' },
    { key: 'received', label: 'Recibidas', align: 'right' },
    { key: 'attended', label: 'Atendidas', align: 'right' },
];
</script>

<template>
    <ChartCard
        title="Por canal"
        description="Conversaciones recibidas en el periodo por canal"
        :empty="channels.length === 0"
        skeleton="bars"
        :storage-key="storageKey"
        data-chart="channels"
    >
        <BarChart
            :items="items"
            :series="[{ key: 'received', name: 'Recibidas' }]"
            :description="`Conversaciones recibidas por canal: ${channels.map((row) => `${row.label} ${formatNumber(row.received)}`).join(', ')}`"
        />
        <template #table>
            <ChartDataTable
                caption="Conversaciones por canal"
                :columns="columns"
                :rows="channels"
            />
        </template>
    </ChartCard>
</template>
