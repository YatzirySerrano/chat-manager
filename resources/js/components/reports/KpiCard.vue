<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import { computed } from 'vue';
import { formatPercent } from '@/composables/useChartTheme';

/**
 * A headline figure with its comparison against the previous period.
 * `value === null` renders "Sin datos suficientes", never a fake zero, and
 * the comparison is only shown when both periods have data. The label and
 * the hint are never cut (they wrap; long words break and hyphenate), as in
 * StatCard: grids of these cards use one column below `sm`.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        /** Already formatted value, or null when there is not enough data. */
        value: string | null;
        current?: number | null;
        previous?: number | null;
        /** Which direction is an improvement (decides the tone of the change). */
        better?: 'higher' | 'lower' | 'neutral';
        /** How the previous value is shown, e.g. "12 min" (defaults to the number). */
        previousLabel?: string | null;
        hint?: string;
        icon?: LucideIcon;
        unavailableLabel?: string;
        /** `compact` (default) for metric rows; `default` for a roomier card. */
        size?: 'compact' | 'default';
    }>(),
    {
        current: null,
        previous: null,
        better: 'neutral',
        previousLabel: null,
        hint: undefined,
        icon: undefined,
        unavailableLabel: 'Sin datos suficientes',
        size: 'compact',
    },
);

const change = computed(() => {
    if (props.current === null || props.previous === null) {
        return null;
    }

    if (props.previous === 0) {
        return props.current === 0
            ? { percent: 0, direction: 'flat' as const }
            : null;
    }

    const percent = ((props.current - props.previous) / props.previous) * 100;

    return {
        percent: Math.abs(percent),
        direction:
            Math.abs(percent) < 0.5
                ? ('flat' as const)
                : percent > 0
                  ? ('up' as const)
                  : ('down' as const),
    };
});

const tone = computed(() => {
    if (!change.value || change.value.direction === 'flat') {
        return 'text-muted-foreground';
    }

    if (props.better === 'neutral') {
        return 'text-muted-foreground';
    }

    const improved =
        (change.value.direction === 'up') === (props.better === 'higher');

    return improved ? 'text-success' : 'text-destructive';
});

const previousText = computed(
    () =>
        props.previousLabel ??
        (props.previous === null
            ? null
            : props.previous.toLocaleString('es-MX')),
);
</script>

<template>
    <div
        :class="[
            'flex min-w-0 flex-col rounded-xl border bg-surface-raised shadow-card',
            size === 'compact' ? 'gap-1 px-3.5 py-3' : 'gap-2 p-4',
        ]"
    >
        <div
            v-if="size === 'compact'"
            class="flex min-w-0 items-start gap-1.5 text-muted-foreground"
        >
            <component
                :is="icon"
                v-if="icon"
                class="mt-px size-4 shrink-0"
                aria-hidden="true"
            />
            <span
                class="min-w-0 text-xs font-medium text-pretty break-words hyphens-auto"
                data-kpi-label
                >{{ label }}</span
            >
        </div>
        <div v-else class="flex items-start justify-between gap-2">
            <span
                class="min-w-0 text-sm break-words hyphens-auto text-muted-foreground"
                data-kpi-label
                >{{ label }}</span
            >
            <component
                :is="icon"
                v-if="icon"
                class="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </div>
        <p
            v-if="value !== null"
            :class="[
                'font-semibold tracking-tight tabular-nums',
                size === 'compact' ? 'text-xl' : 'text-2xl',
            ]"
        >
            {{ value }}
        </p>
        <p v-else class="text-sm font-medium text-muted-foreground">
            {{ unavailableLabel }}
        </p>
        <p
            v-if="change"
            class="flex flex-wrap items-center gap-x-1 text-xs tabular-nums"
            :class="tone"
        >
            <ArrowUpRight
                v-if="change.direction === 'up'"
                class="size-3.5"
                aria-hidden="true"
            />
            <ArrowDownRight
                v-else-if="change.direction === 'down'"
                class="size-3.5"
                aria-hidden="true"
            />
            <Minus v-else class="size-3.5" aria-hidden="true" />
            <span>
                {{
                    change.direction === 'flat'
                        ? 'Igual que el periodo anterior'
                        : `${formatPercent(change.percent / 100)} ${change.direction === 'up' ? 'más' : 'menos'} que el periodo anterior`
                }}
            </span>
            <span class="text-muted-foreground">
                (antes: {{ previousText }})
            </span>
        </p>
        <p
            v-else-if="previous === null && value !== null"
            class="text-xs text-muted-foreground"
        >
            Sin datos del periodo anterior
        </p>
        <p
            v-else-if="previous === 0 && value !== null"
            class="text-xs text-muted-foreground"
        >
            Antes: {{ previousText }}
        </p>
        <p
            v-if="hint"
            class="text-xs text-pretty break-words hyphens-auto text-muted-foreground"
            data-kpi-hint
        >
            {{ hint }}
        </p>
    </div>
</template>
