<script setup lang="ts">
import {
    BadgeCheck,
    Building2,
    CircleSlash,
    Coins,
    Percent,
    ReceiptText,
    UsersRound,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import ChartCard from '@/components/charts/ChartCard.vue';
import ChartDataTable from '@/components/charts/ChartDataTable.vue';
import ChartLegend from '@/components/charts/ChartLegend.vue';
import LineChart from '@/components/charts/LineChart.vue';
import type { ChartTableColumn, LineSeries } from '@/components/charts/types';
import GranularityToggle from '@/components/reports/GranularityToggle.vue';
import InsightHeading from '@/components/reports/InsightHeading.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import {
    chartTokens,
    formatBucket,
    formatNumber,
    granularityNoun,
} from '@/composables/useChartTheme';
import { formatMoney } from '@/lib/outcomes';
import type {
    AnalyticsGranularity,
    AnalyticsPeriod,
    RequestOutcomeRow,
    RequestOutcomesReport,
} from '@/types';

/**
 * «Resultados» of /reports: what became of the requests completed in the
 * period. «Solicitud completada» only says it was attended; approved
 * credits (and their authorized amount) are what commissions, so they are
 * shown per collaborator (who completed it) and per department.
 */
const props = defineProps<{
    outcomes: RequestOutcomesReport;
    period: AnalyticsPeriod;
    scope: 'all' | 'departments' | 'own';
}>();

const emit = defineEmits<{ granularity: [value: AnalyticsGranularity] }>();

const totals = computed(() => props.outcomes.totals);

function count(value: number): string {
    return value.toLocaleString('es-MX');
}

function rate(value: number | null): string | null {
    return value === null ? null : `${value.toLocaleString('es-MX')} %`;
}

function money(value: number | null): string | null {
    return value === null ? null : formatMoney(value);
}

const labels = computed(() =>
    props.outcomes.trend.points.map((point) => ({
        short: point.label,
        long: formatBucket(point.key, props.outcomes.trend.granularity).long,
    })),
);

const series = computed<LineSeries[]>(() => [
    {
        key: 'approved',
        name: 'Créditos aprobados',
        values: props.outcomes.trend.points.map((point) => point.approved),
        color: chartTokens.primary,
        area: true,
    },
    {
        key: 'rejected',
        name: 'Rechazadas',
        values: props.outcomes.trend.points.map((point) => point.rejected),
        color: chartTokens.secondary,
    },
    {
        key: 'without',
        name: 'Sin resultado',
        values: props.outcomes.trend.points.map((point) => point.without),
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

const trendRows = computed(() =>
    props.outcomes.trend.points.map((point, index) => ({
        ...point,
        period: labels.value[index]?.long ?? point.label,
    })),
);

const trendColumns: ChartTableColumn[] = [
    { key: 'period', label: 'Periodo' },
    { key: 'completed', label: 'Completadas', align: 'right' },
    { key: 'approved', label: 'Créditos aprobados', align: 'right' },
    { key: 'rejected', label: 'Rechazadas', align: 'right' },
    { key: 'without', label: 'Sin resultado', align: 'right' },
    {
        key: 'amount',
        label: 'Monto autorizado',
        align: 'right',
        format: (value) => formatMoney(Number(value)),
    },
];

const empty = computed(() => totals.value.completed === 0);

type Table = {
    key: string;
    title: string;
    description: string;
    dimension: string;
    icon: typeof UsersRound;
    rows: RequestOutcomeRow[];
};

const tables = computed<Table[]>(() => [
    {
        key: 'agents',
        title:
            props.scope === 'own'
                ? 'Tus resultados'
                : 'Resultados por colaborador',
        description:
            'Quién completó cada solicitud. Los créditos aprobados y su monto son la base de la comisión.',
        dimension: 'Colaborador',
        icon: UsersRound,
        rows: props.outcomes.by_agent,
    },
    {
        key: 'departments',
        title: 'Resultados por departamento',
        description: 'Departamento actual de cada solicitud.',
        dimension: 'Departamento',
        icon: Building2,
        rows: props.outcomes.by_department,
    },
]);
</script>

<template>
    <StatusNote data-outcomes-note>
        Una solicitud completada se atendió; su resultado dice si el crédito se
        aprobó (lo único que comisiona) o se rechazó. Cada solicitud cuenta en
        la fecha en que se registró su resultado, o en la que se completó si aún
        no tiene. Las que se completan sin resultado pueden registrarlo después
        desde el detalle de la conversación.
    </StatusNote>

    <section aria-labelledby="outcomes-heading" class="flex flex-col gap-3">
        <InsightHeading
            id="outcomes-heading"
            :icon="ReceiptText"
            title="Resultados del periodo"
            :description="period.label"
        />
        <div
            class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4"
            data-outcomes-kpis
        >
            <KpiCard
                label="Solicitudes completadas"
                :icon="ReceiptText"
                :value="count(totals.completed)"
                plain
                hint="Atendidas y cerradas como «Solicitud completada»"
                data-kpi="completed"
            />
            <KpiCard
                label="Créditos aprobados"
                :icon="BadgeCheck"
                tone="success"
                :value="count(totals.approved)"
                plain
                hint="Lo único que comisiona"
                data-kpi="approved"
            />
            <KpiCard
                label="Rechazadas"
                :icon="XCircle"
                tone="destructive"
                :value="count(totals.rejected)"
                plain
                hint="Atendidas, pero el crédito no se aprobó"
                data-kpi="rejected"
            />
            <KpiCard
                label="Sin resultado"
                :icon="CircleSlash"
                tone="muted"
                :value="count(totals.without)"
                plain
                hint="Completadas sin registrar si se aprobaron"
                data-kpi="without"
            />
            <KpiCard
                label="Tasa de aprobación"
                :icon="Percent"
                :value="rate(totals.approval_rate)"
                plain
                unavailable-label="Sin datos"
                :hint="
                    totals.approval_rate === null
                        ? 'Ninguna solicitud con resultado en el periodo'
                        : `Aprobadas de las que tienen resultado (n = ${count(totals.approved + totals.rejected)})`
                "
                data-kpi="approval_rate"
            />
            <KpiCard
                label="Monto autorizado"
                :icon="Coins"
                tone="success"
                :value="money(totals.amount)"
                plain
                hint="Suma de los créditos aprobados"
                data-kpi="amount"
            />
            <KpiCard
                label="Monto promedio por crédito"
                :icon="Coins"
                :value="money(totals.average_amount)"
                plain
                unavailable-label="Sin datos"
                :hint="
                    totals.average_amount === null
                        ? 'Ningún crédito aprobado con monto'
                        : `De ${count(totals.with_amount)} créditos aprobados con monto`
                "
                data-kpi="average_amount"
            />
        </div>
    </section>

    <ChartCard
        title="¿Cuántos créditos se aprueban?"
        :description="`Resultados por ${granularityNoun(outcomes.trend.granularity)}, por la fecha del resultado`"
        :total="`${formatNumber(totals.approved)} aprobados · ${money(totals.amount)}`"
        :empty="empty"
        empty-text="Sin solicitudes completadas en este periodo"
        storage-key="reports.outcomes-trend"
        data-chart="outcomes-trend"
    >
        <template #actions>
            <GranularityToggle
                :value="period.granularity"
                :options="period.granularities"
                @change="(value) => emit('granularity', value)"
            />
        </template>
        <LineChart
            :labels="labels"
            :series="series"
            :description="`Resultados de las solicitudes completadas ${period.label}`"
        />
        <template #legend>
            <ChartLegend :items="legend" />
        </template>
        <template #table>
            <ChartDataTable
                caption="Resultados por periodo"
                :columns="trendColumns"
                :rows="trendRows"
            />
        </template>
    </ChartCard>

    <SurfaceCard
        v-for="table in tables"
        :key="table.key"
        :title="table.title"
        :description="table.description"
        :data-outcomes-table="table.key"
    >
        <p
            v-if="table.rows.length === 0"
            class="px-4 py-6 text-center text-sm text-muted-foreground sm:px-5"
        >
            Sin solicitudes completadas en este periodo
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[44rem] text-sm">
                <caption class="sr-only">
                    {{
                        table.title
                    }}
                </caption>
                <thead>
                    <tr
                        class="border-b text-left text-xs text-muted-foreground"
                    >
                        <th scope="col" class="px-4 py-2.5 font-medium sm:px-5">
                            {{ table.dimension }}
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Completadas
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Aprobados
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Rechazadas
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Sin resultado
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Aprobación
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-2.5 text-right font-medium"
                        >
                            Monto autorizado
                        </th>
                        <th
                            scope="col"
                            class="px-4 py-2.5 text-right font-medium sm:px-5"
                        >
                            Promedio
                        </th>
                    </tr>
                </thead>
                <tbody class="tabular-nums">
                    <tr
                        v-for="(row, index) in table.rows"
                        :key="row.id ?? `none-${index}`"
                        class="border-b last:border-b-0"
                    >
                        <th
                            scope="row"
                            class="max-w-56 truncate px-4 py-2.5 text-left font-medium sm:px-5"
                            :title="row.name"
                        >
                            {{ row.name }}
                        </th>
                        <td class="px-3 py-2.5 text-right">
                            {{ count(row.completed) }}
                        </td>
                        <td
                            class="px-3 py-2.5 text-right font-semibold text-success-soft-foreground"
                        >
                            {{ count(row.approved) }}
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            {{ count(row.rejected) }}
                        </td>
                        <td
                            class="px-3 py-2.5 text-right text-muted-foreground"
                        >
                            {{ count(row.without) }}
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            {{ rate(row.approval_rate) ?? '—' }}
                        </td>
                        <td class="px-3 py-2.5 text-right font-semibold">
                            {{ money(row.amount) }}
                        </td>
                        <td class="px-4 py-2.5 text-right sm:px-5">
                            {{ money(row.average_amount) ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </SurfaceCard>
</template>
