<script setup lang="ts">
import { CalendarRange, Globe2, SlidersHorizontal } from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import type {
    ActiveFilterPill,
    FilterTabOption,
} from '@/components/filters/types';
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
import './insights.css';

/**
 * Period and dimension filters shared by the reports page and the
 * dashboard, in the language of the inbox filters: the period as a pill
 * track (the thumb glides to the chosen one), the dimensions as selects in
 * one grid, and every applied dimension as a removable pill with «Limpiar
 * filtros». It only emits the new query; the page navigates, so filters
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

const DEFAULT_PERIOD: AnalyticsPreset = '30d';

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

    if (state.period !== DEFAULT_PERIOD) {
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

function changePeriod(preset: AnalyticsPreset): void {
    if (preset === state.period) {
        return;
    }

    state.period = preset;

    if (preset !== 'custom') {
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

const periodTabs = computed<FilterTabOption<AnalyticsPreset>[]>(() =>
    props.presets.map((preset) => ({
        value: preset.value,
        label: preset.label,
        icon: preset.value === 'custom' ? CalendarRange : undefined,
        attrs: { 'data-period': preset.value },
    })),
);

/** Every applied dimension as a removable pill («Canal: WhatsApp»). */
const pills = computed<ActiveFilterPill[]>(() =>
    dimensions.value.flatMap((dimension) => {
        const value = state[dimension.key];

        if (value === '' || value === undefined) {
            return [];
        }

        const option = dimension.options.find(
            (item) => String(item.value) === String(value),
        );

        return [
            {
                key: dimension.key,
                label: `${dimension.label}: ${option?.label ?? value}`,
                remove: () => {
                    state[dimension.key] = '';
                    apply();
                },
            },
        ];
    }),
);

const narrowed = computed(
    () => pills.value.length > 0 || state.period !== DEFAULT_PERIOD,
);

function clearAll(): void {
    state.period = DEFAULT_PERIOD;
    state.department = '';
    state.agent = '';
    state.connection = '';
    state.category = '';
    state.status = '';
    state.channel = '';
    apply();
}
</script>

<template>
    <!--
      Full-width filter bar: the period on one pill track, every dimension a
      column of the same grid (fills the workspace on wide screens, stacks on
      phones) and the applied filters as pills under them.
    -->
    <div
        class="flex flex-col gap-3.5 rounded-2xl border border-[color-mix(in_oklch,var(--border)_85%,transparent)] glass-card p-3 shadow-float sm:p-4"
        data-analytics-filters
    >
        <div class="flex flex-col gap-2">
            <span
                class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
            >
                <CalendarRange class="size-3.5" aria-hidden="true" />
                Periodo
            </span>
            <FilterTabs
                :options="periodTabs"
                :model-value="state.period"
                label="Periodo"
                data-filter="period"
                @update:model-value="changePeriod"
            />
        </div>

        <div
            v-if="state.period === 'custom'"
            class="grid enter-spring gap-1.5 sm:max-w-md"
        >
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

        <div v-if="dimensions.length > 0" class="flex flex-col gap-2">
            <span
                class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
            >
                <SlidersHorizontal class="size-3.5" aria-hidden="true" />
                Filtrar por
            </span>
            <div class="auto-grid [--col-min:13rem] [--grid-gap:0.625rem]">
                <label
                    v-for="dimension in dimensions"
                    :key="dimension.key"
                    class="grid gap-1"
                >
                    <span
                        class="text-[0.6875rem] font-medium text-muted-foreground"
                        >{{ dimension.label }}</span
                    >
                    <AppSelect
                        v-model="state[dimension.key]"
                        :options="dimension.options"
                        :aria-label="dimension.label"
                        :data-filter="dimension.key"
                        @change="apply"
                    />
                </label>
            </div>
        </div>

        <div
            class="flex flex-col gap-2 border-t border-dashed pt-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <p
                class="flex min-w-0 items-start gap-1.5 text-xs text-pretty text-muted-foreground"
                data-analytics-period
            >
                <Globe2 class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                <span>
                    <span class="font-medium text-foreground">{{
                        period.label
                    }}</span
                    >, comparado con
                    <template v-if="period.comparison.partial"
                        >el mismo tramo transcurrido del periodo anterior ({{
                            period.comparison.label
                        }})</template
                    ><template v-else>{{ period.comparison.label }}</template
                    >, {{ zoneLabel(period.timezone) }}
                </span>
            </p>
            <ActiveFilters
                v-if="narrowed"
                class="sm:justify-end"
                :pills="pills"
                :clearable="narrowed"
                @clear="clearAll"
            />
        </div>
    </div>
</template>
