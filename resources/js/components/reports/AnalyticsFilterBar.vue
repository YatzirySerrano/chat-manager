<script setup lang="ts">
import { CalendarRange } from '@lucide/vue';
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
import './insights.css';

/**
 * Period and dimension filters shared by the reports page and the
 * dashboard, as one dense toolbar row that wraps on narrow screens: the
 * period as a segmented control, every dimension as a small select whose
 * empty option names it («Todos los agentes»); an applied dimension turns
 * into a tinted pill with its own ✕ (removable in place). «Limpiar» and the
 * compared range close the row. It only emits the new query; the page navigates, so filters
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

/**
 * The values of a dimension. There is no «Todos» option: an unset select
 * shows the dimension's name (placeholder) and an applied one is removed
 * with its ✕.
 */
function toOptions(
    items: { id: string | number; name: string }[] | undefined,
): AppSelectOption<string | number>[] {
    return (items ?? []).map((item) => ({ value: item.id, label: item.name }));
}

/** The dimension filters the viewer can use (an empty list hides one). */
const dimensions = computed<Dimension[]>(() => {
    const { options } = props;
    const list: (Dimension & { show: boolean })[] = [
        {
            key: 'department',
            label: 'Departamento',
            options: toOptions(options.departments),
            show: options.departments.length > 0,
        },
        {
            key: 'agent',
            label: 'Agente',
            options: toOptions(options.agents),
            show: options.agents.length > 0,
        },
        {
            key: 'connection',
            label: 'Conexión',
            options: toOptions(options.connections),
            show: options.connections.length > 1,
        },
        {
            key: 'channel',
            label: 'Canal',
            options: toOptions(options.channels),
            show: (options.channels?.length ?? 0) > 1,
        },
        {
            key: 'category',
            label: 'Categoría',
            options: toOptions(options.categories),
            show: (options.categories?.length ?? 0) > 0,
        },
        {
            key: 'status',
            label: 'Estado',
            options: toOptions(options.statuses),
            show: (options.statuses?.length ?? 0) > 0,
        },
    ];

    return list.filter((dimension) => dimension.show);
});

/** Short labels of the segmented control («7 días» for «Últimos 7 días»). */
const shortLabels: Record<string, string> = {
    today: 'Hoy',
    yesterday: 'Ayer',
    '7d': '7 días',
    '30d': '30 días',
    '90d': '90 días',
    custom: 'Rango',
};

const periodOptions = computed(() =>
    props.presets.map((preset) => ({
        ...preset,
        short: shortLabels[preset.value] ?? preset.label,
    })),
);

/** An applied dimension: its select becomes a pill with a ✕. */
function isSet(key: DimensionKey): boolean {
    return state[key] !== '' && state[key] !== undefined;
}

const appliedCount = computed(
    () => dimensions.value.filter((dimension) => isSet(dimension.key)).length,
);

const narrowed = computed(
    () => appliedCount.value > 0 || state.period !== DEFAULT_PERIOD,
);

/** The compared range and the time zone, in words (tooltip and readers). */
const comparisonDetail = computed(
    () =>
        `${props.period.label}, comparado con ${
            props.period.comparison.partial
                ? `el mismo tramo transcurrido del periodo anterior (${props.period.comparison.label})`
                : props.period.comparison.label
        }, ${zoneLabel(props.period.timezone)}`,
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
    <div
        class="flex flex-wrap items-center gap-2"
        role="toolbar"
        aria-label="Filtros del periodo"
        data-analytics-filters
    >
        <div
            class="inline-flex h-9 max-w-full shrink-0 [scrollbar-width:none] items-center gap-0.5 overflow-x-auto rounded-lg border bg-card p-0.5 shadow-(--shadow-control)"
            role="group"
            aria-label="Periodo"
            data-filter="period"
        >
            <button
                v-for="option in periodOptions"
                :key="option.value"
                type="button"
                class="inline-flex h-full shrink-0 items-center gap-1 rounded-md px-2.5 text-[0.8125rem] font-medium whitespace-nowrap outline-none focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-colors"
                :class="
                    state.period === option.value
                        ? 'bg-foreground text-background'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                "
                :aria-pressed="state.period === option.value"
                :title="option.label"
                :data-period="option.value"
                @click="changePeriod(option.value)"
            >
                <CalendarRange
                    v-if="option.value === 'custom'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ option.short }}
            </button>
        </div>

        <div
            v-if="state.period === 'custom'"
            class="w-full sm:w-64"
            :title="maxDays ? `Hasta ${maxDays} días` : undefined"
        >
            <DateRangePicker
                v-model:from="state.from"
                v-model:to="state.to"
                size="sm"
                required
                :aria-label="
                    maxDays
                        ? `Rango de fechas (hasta ${maxDays} días)`
                        : 'Rango de fechas'
                "
                @change="applyRange"
            />
        </div>

        <span
            v-if="dimensions.length > 0"
            class="mx-0.5 hidden h-5 w-px bg-border sm:block"
            aria-hidden="true"
        />

        <div
            v-for="dimension in dimensions"
            :key="dimension.key"
            class="analytics-filter min-w-0 basis-[calc(50%-0.25rem)] sm:max-w-56 sm:basis-auto"
            :data-applied="isSet(dimension.key) || undefined"
        >
            <AppSelect
                v-model="state[dimension.key]"
                :options="dimension.options"
                :placeholder="dimension.label"
                size="sm"
                clearable
                clear-value=""
                :aria-label="dimension.label"
                :data-filter="dimension.key"
                @change="apply"
            />
        </div>

        <div
            class="flex min-w-0 items-center gap-2 text-xs text-muted-foreground sm:ml-auto"
        >
            <button
                v-if="narrowed"
                type="button"
                class="inline-flex h-7 items-center rounded-md px-2 font-medium text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                data-filter-clear
                @click="clearAll"
            >
                Limpiar
            </button>
            <p
                class="min-w-0 truncate tabular-nums"
                :title="comparisonDetail"
                data-analytics-period
            >
                <span class="font-medium text-foreground">{{
                    period.label
                }}</span>
                <span class="hidden 2xl:inline">
                    · vs. {{ period.comparison.label }}</span
                >
                <span class="sr-only">. {{ comparisonDetail }}</span>
            </p>
        </div>
    </div>
</template>

<style scoped>
/* An applied dimension reads as a pill: tinted, with its ✕ in place. */
.analytics-filter[data-applied] :deep(button[data-slot='select-trigger']),
.analytics-filter[data-applied] :deep(button.group\/select-trigger) {
    border-color: color-mix(in oklch, var(--primary) 45%, var(--border));
    background: var(--primary-soft);
    color: var(--primary-soft-foreground);
    font-weight: 500;
}
</style>
