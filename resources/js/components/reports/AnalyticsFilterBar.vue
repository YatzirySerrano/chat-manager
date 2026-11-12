<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { AppSelect } from '@/components/ui/app-select';
import type { AppSelectOption } from '@/components/ui/app-select';
import { DateRangePicker } from '@/components/ui/date-picker';
import { zoneLabel } from '@/composables/useChartTheme';
import type {
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
} from '@/types';

/**
 * Period and dimension filters shared by the reports page and the
 * dashboard. It only emits the new query; the page navigates, so filters
 * stay in the URL. Options come from the server already limited to the
 * viewer's reach (an empty list hides the filter).
 */
const props = defineProps<{
    query: AnalyticsQuery;
    period: AnalyticsPeriod;
    presets: { value: AnalyticsPreset; label: string }[];
    options: AnalyticsOptions;
    maxDays?: number;
}>();

const emit = defineEmits<{ change: [query: AnalyticsQuery] }>();

const state = reactive({
    period: props.period.preset,
    from: props.period.from,
    to: props.period.to,
    department: props.query.department ?? '',
    agent: props.query.agent ?? '',
    connection: props.query.connection ?? '',
    category: props.query.category ?? '',
    status: (props.query.status ?? '') as AnalyticsQuery['status'] | '',
    channel: props.query.channel ?? '',
});

watch(
    () => [props.period, props.query] as const,
    ([period, query]) => {
        state.period = period.preset;
        state.from = period.from;
        state.to = period.to;
        state.department = query.department ?? '';
        state.agent = query.agent ?? '';
        state.connection = query.connection ?? '';
        state.category = query.category ?? '';
        state.status = query.status ?? '';
        state.channel = query.channel ?? '';
    },
);

function build(): AnalyticsQuery {
    const query: AnalyticsQuery = {};

    if (state.period !== '30d') {
        query.period = state.period;
    }

    if (state.period === 'custom') {
        query.from = state.from;
        query.to = state.to;
    }

    if (state.department !== '') {
        query.department = Number(state.department);
    }

    if (state.agent !== '') {
        query.agent = Number(state.agent);
    }

    if (state.connection !== '') {
        query.connection = Number(state.connection);
    }

    if (state.category !== '') {
        query.category = Number(state.category);
    }

    if (state.status !== '') {
        query.status = state.status;
    }

    if (state.channel !== '') {
        query.channel = state.channel;
    }

    // The chart granularity is not a control of this bar: it is kept as is.
    if (props.query.granularity) {
        query.granularity = props.query.granularity;
    }

    return query;
}

function apply(): void {
    emit('change', build());
}

function changePeriod(): void {
    if (state.period !== 'custom') {
        apply();
    }
}

/** A custom range is applied as soon as both of its dates are chosen. */
function applyRange(): void {
    if (state.from !== '' && state.to !== '' && state.to >= state.from) {
        apply();
    }
}

type DimensionKey =
    | 'department'
    | 'agent'
    | 'connection'
    | 'channel'
    | 'category'
    | 'status';

type Dimension = {
    key: DimensionKey;
    label: string;
    options: AppSelectOption<string | number>[];
};

function withAll(
    all: string,
    items: { id: string | number; name: string }[] | undefined,
): AppSelectOption<string | number>[] {
    return [
        { value: '', label: all },
        ...(items ?? []).map((item) => ({ value: item.id, label: item.name })),
    ];
}

/** The dimension filters the viewer can use (an empty list hides one). */
const dimensions = computed<Dimension[]>(() => {
    const { options } = props;
    const list: (Dimension & { show: boolean })[] = [
        {
            key: 'department',
            label: 'Departamento',
            options: withAll('Todos los que puedes ver', options.departments),
            show: options.departments.length > 0,
        },
        {
            key: 'agent',
            label: 'Agente responsable',
            options: withAll('Todos', options.agents),
            show: options.agents.length > 0,
        },
        {
            key: 'connection',
            label: 'Conexión',
            options: withAll('Todas', options.connections),
            show: options.connections.length > 1,
        },
        {
            key: 'channel',
            label: 'Canal',
            options: withAll('Todos', options.channels),
            show: (options.channels?.length ?? 0) > 1,
        },
        {
            key: 'category',
            label: 'Categoría',
            options: withAll('Todas', options.categories),
            show: (options.categories?.length ?? 0) > 0,
        },
        {
            key: 'status',
            label: 'Estado actual',
            options: withAll('Todos', options.statuses),
            show: (options.statuses?.length ?? 0) > 0,
        },
    ];

    return list.filter((dimension) => dimension.show);
});

const periodOptions = computed(() =>
    props.presets.map((preset) => ({
        value: preset.value,
        label: preset.label,
    })),
);
</script>

<template>
    <!--
      Full-width filter bar: every filter is a column of the same grid, so
      the bar fills the workspace on wide screens and stacks on phones.
    -->
    <div class="flex flex-col gap-3 rounded-xl border bg-card p-4">
        <div class="auto-grid [--col-min:13rem]">
            <div class="grid gap-1.5">
                <span class="text-xs font-medium text-muted-foreground"
                    >Periodo</span
                >
                <AppSelect
                    v-model="state.period"
                    :options="periodOptions"
                    aria-label="Periodo"
                    data-filter="period"
                    @change="changePeriod"
                />
            </div>
            <div v-if="state.period === 'custom'" class="grid gap-1.5">
                <span class="text-xs font-medium text-muted-foreground"
                    >Rango de fechas<template v-if="maxDays">
                        (hasta {{ maxDays }} días)</template
                    ></span
                >
                <DateRangePicker
                    v-model:from="state.from"
                    v-model:to="state.to"
                    required
                    aria-label="Rango de fechas"
                    @change="applyRange"
                />
            </div>
            <div
                v-for="dimension in dimensions"
                :key="dimension.key"
                class="grid gap-1.5"
            >
                <span class="text-xs font-medium text-muted-foreground">{{
                    dimension.label
                }}</span>
                <AppSelect
                    v-model="state[dimension.key]"
                    :options="dimension.options"
                    :aria-label="dimension.label"
                    :data-filter="dimension.key"
                    @change="apply"
                />
            </div>
        </div>

        <p class="text-xs text-muted-foreground">
            {{ period.label }}, comparado con
            <template v-if="period.comparison.partial"
                >el mismo tramo transcurrido del periodo anterior ({{
                    period.comparison.label
                }})</template
            ><template v-else>{{ period.comparison.label }}</template
            >, {{ zoneLabel(period.timezone) }}
        </p>
    </div>
</template>
