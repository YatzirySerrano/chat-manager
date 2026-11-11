<script setup lang="ts">
import { computed } from 'vue';
import {
    formatBucket,
    formatDuration,
    formatNumber,
} from '@/composables/useChartTheme';
import type { SlaCompliance } from '@/types';
import BarChart from './BarChart.vue';
import ChartCard from './ChartCard.vue';
import ChartDataTable from './ChartDataTable.vue';
import ChartLegend from './ChartLegend.vue';
import type { BarItem, BarSeries, ChartTableColumn } from './types';

/**
 * «¿Qué porcentaje cumplió el SLA?»: the compliance rate with its base (n)
 * and one 100 % bar splitting every response cycle of the period into
 * within the deadline, past it, still in progress and not judged. Without
 * judged cycles there is no percentage («Sin datos»), never 100 %.
 */
const props = withDefaults(
    defineProps<{
        sla: SlaCompliance;
        storageKey: string;
        /** A status filter is active: it cannot apply to response cycles. */
        statusFiltered?: boolean;
        title?: string;
    }>(),
    { statusFiltered: false, title: '¿Qué porcentaje cumplió el SLA?' },
);

const totals = computed(() => props.sla.totals);
const notJudged = computed(
    () => totals.value.closed_unanswered + totals.value.unmeasured,
);

const groups = computed(() => [
    {
        key: 'met',
        label: 'Dentro del plazo',
        count: totals.value.met,
        color: 'var(--success)',
    },
    {
        key: 'breached',
        label: 'Fuera del plazo',
        count: totals.value.breached,
        color: 'var(--destructive)',
    },
    {
        key: 'in_progress',
        label: 'En curso, dentro del plazo',
        count: totals.value.in_progress,
        color: 'var(--warning)',
    },
    {
        key: 'not_judged',
        label: 'Sin evaluar (sin objetivo o cerradas sin respuesta)',
        count: notJudged.value,
        color: 'var(--muted-foreground)',
    },
]);

const series = computed<BarSeries[]>(() =>
    groups.value.map((group) => ({
        key: group.key,
        name: group.label,
        color: group.color,
    })),
);

const items = computed<BarItem[]>(() => [
    {
        key: 'cycles',
        label: '',
        values: groups.value.map((group) => group.count),
    },
]);

const legend = computed(() =>
    groups.value.map((group) => ({
        key: group.key,
        label: group.label,
        color: group.color,
        shape: 'square' as const,
        value: formatNumber(group.count),
    })),
);

function day(date: string): string {
    return formatBucket(date, 'day').long;
}

const since = computed(() =>
    props.sla.available_since ? day(props.sla.available_since) : null,
);

const rateText = computed(() =>
    totals.value.rate === null
        ? 'Sin datos'
        : `${formatNumber(totals.value.rate)} %`,
);

const headline = computed(() =>
    totals.value.samples === 0
        ? 'Sin datos: ningún ciclo con objetivo se ha evaluado todavía'
        : `${rateText.value} de cumplimiento · ${formatNumber(totals.value.met)} de ${formatNumber(totals.value.samples)} ciclos evaluados (n = ${formatNumber(totals.value.samples)})`,
);

const comparison = computed(() => {
    if (totals.value.rate === null || props.sla.previous.rate === null) {
        return null;
    }

    const delta =
        Math.round((totals.value.rate - props.sla.previous.rate) * 10) / 10;

    return delta === 0
        ? `Igual que el periodo de comparación (n = ${formatNumber(props.sla.previous.samples)})`
        : `${formatNumber(Math.abs(delta))} puntos ${delta > 0 ? 'más' : 'menos'} que el periodo de comparación (${formatNumber(props.sla.previous.rate)} %, n = ${formatNumber(props.sla.previous.samples)})`;
});

const emptyText = computed(() => {
    if (since.value === null) {
        return 'El seguimiento de SLA comienza con esta versión: aún no hay ciclos de respuesta registrados.';
    }

    return props.sla.covers_period
        ? 'Sin mensajes de clientes que esperaran respuesta en este periodo.'
        : `Sin datos en este periodo. Disponible desde ${since.value}.`;
});

const footnote = computed(() => {
    const notes: string[] = [];

    if (since.value !== null && !props.sla.covers_period) {
        notes.push(
            `Disponible desde ${since.value}: los días anteriores del periodo no tienen registro.`,
        );
    }

    if (props.sla.response_time.avg !== null) {
        notes.push(
            `Tiempo entre un mensaje del cliente y su respuesta: promedio ${formatDuration(props.sla.response_time.avg)}, mínimo ${formatDuration(props.sla.response_time.min ?? 0)}, máximo ${formatDuration(props.sla.response_time.max ?? 0)} (n = ${formatNumber(props.sla.response_time.samples)}).`,
        );
    }

    if (props.statusFiltered) {
        notes.push(
            'El filtro de estado no se aplica a los ciclos de respuesta.',
        );
    }

    return notes.join(' ') || undefined;
});

const rows = computed(() =>
    props.sla.kinds.map((kind) => ({
        label: kind.label,
        met: kind.met,
        breached: kind.breached,
        in_progress: kind.in_progress,
        not_judged: kind.closed_unanswered + kind.unmeasured,
        samples: kind.samples,
        rate: kind.rate,
    })),
);

const columns: ChartTableColumn[] = [
    { key: 'label', label: 'Tipo de respuesta' },
    { key: 'met', label: 'Dentro del plazo', align: 'right' },
    { key: 'breached', label: 'Fuera del plazo', align: 'right' },
    { key: 'in_progress', label: 'En curso', align: 'right' },
    { key: 'not_judged', label: 'Sin evaluar', align: 'right' },
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
</script>

<template>
    <ChartCard
        :title="title"
        description="Ciclos de respuesta iniciados en el periodo: cada vez que un cliente esperó la respuesta de una persona, contra el plazo vigente en ese momento"
        :total="headline"
        :empty="totals.cycles === 0"
        :empty-text="emptyText"
        skeleton="bars"
        :storage-key="storageKey"
        :footnote="footnote"
        data-chart="sla"
    >
        <div class="flex flex-col gap-2">
            <BarChart
                :items="items"
                :series="series"
                stacked
                :description="`Cumplimiento de SLA: ${legend.map((item) => `${item.label} ${item.value}`).join(', ')}`"
            />
            <p
                v-if="comparison"
                class="text-xs text-muted-foreground tabular-nums"
                data-sla-comparison
            >
                {{ comparison }}
            </p>
        </div>
        <template #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable
                caption="Cumplimiento de SLA por tipo de respuesta"
                :columns="columns"
                :rows="rows"
            />
        </template>
    </ChartCard>
</template>
