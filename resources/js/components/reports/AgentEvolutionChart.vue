<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import LineChart from '@/components/charts/LineChart.vue';
import type { ChartTableColumn, LineSeries } from '@/components/charts/types';
import {
    chartTokens,
    formatBucket,
    formatNumber,
    granularityNoun,
} from '@/composables/useChartTheme';
import type { AgentProductivity } from '@/types';

/**
 * Evolution of one collaborator along the period: conversations attended
 * (by the date they arrived) and closings made. The person is chosen among
 * the ones with most attended conversations.
 */
const props = defineProps<{
    evolution: AgentProductivity['evolution'];
    storageKey: string;
}>();

const selected = ref(0);

watch(
    () => props.evolution.agents.length,
    (length) => {
        if (selected.value >= length) {
            selected.value = 0;
        }
    },
);

const agent = computed(() => props.evolution.agents[selected.value] ?? null);
const noun = computed(() => granularityNoun(props.evolution.granularity));

const labels = computed(() =>
    props.evolution.buckets.map((bucket) => ({
        short: bucket.label,
        long: formatBucket(bucket.key, props.evolution.granularity).long,
    })),
);

const series = computed<LineSeries[]>(() => [
    {
        key: 'attended',
        name: 'Atendidas',
        values: agent.value?.attended ?? [],
        color: chartTokens.primary,
        area: true,
    },
    {
        key: 'closes',
        name: 'Finalizadas (cierres)',
        values: agent.value?.closes ?? [],
        color: chartTokens.secondary,
    },
]);

const legend = computed(() =>
    series.value.map((item) => ({
        key: item.key,
        label: item.name,
        color: item.color ?? chartTokens.primary,
        shape: 'line' as const,
    })),
);

const empty = computed(
    () =>
        agent.value === null ||
        (agent.value.attended.every((value) => value === 0) &&
            agent.value.closes.every((value) => value === 0)),
);

const total = computed(() => {
    if (agent.value === null) {
        return undefined;
    }

    const attended = agent.value.attended.reduce(
        (sum, value) => sum + value,
        0,
    );

    return `${agent.value.name}: ${formatNumber(attended)} ${attended === 1 ? 'atendida' : 'atendidas'}`;
});

const rows = computed(() =>
    props.evolution.buckets.map((bucket, index) => ({
        period: labels.value[index]?.long ?? bucket.label,
        attended: agent.value?.attended[index] ?? 0,
        closes: agent.value?.closes[index] ?? 0,
    })),
);

const columns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    { key: 'attended', label: 'Atendidas', align: 'right' },
    { key: 'closes', label: 'Finalizadas (cierres)', align: 'right' },
];
</script>

<template>
    <ChartCard
        title="Evolución individual"
        :description="`Solicitudes atendidas y finalizadas por ${noun}, según la fecha en que llegaron`"
        :total="total"
        :empty="empty"
        empty-text="Sin solicitudes atendidas en este periodo"
        :storage-key="storageKey"
        data-chart="agent-evolution"
    >
        <template v-if="evolution.agents.length > 1" #actions>
            <label
                class="flex items-center gap-2 text-xs text-muted-foreground"
            >
                <span class="sr-only sm:not-sr-only">Colaborador</span>
                <select
                    v-model.number="selected"
                    class="h-8 max-w-44 rounded-md border border-input bg-transparent px-2 text-sm text-foreground shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    data-evolution-agent
                >
                    <option
                        v-for="(option, index) in evolution.agents"
                        :key="index"
                        :value="index"
                    >
                        {{ option.name }}
                    </option>
                </select>
            </label>
        </template>
        <LineChart
            :labels="labels"
            :series="series"
            :description="`Evolución de ${agent?.name ?? ''}: solicitudes atendidas y finalizadas por ${noun}`"
        />
        <template #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable
                :caption="`Evolución de ${agent?.name ?? ''}`"
                :columns="columns"
                :rows="rows"
            />
        </template>
    </ChartCard>
</template>
