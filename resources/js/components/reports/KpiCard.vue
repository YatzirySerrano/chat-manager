<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import { computed } from 'vue';

/**
 * A headline figure with its comparison against the previous period.
 * `value === null` renders "Sin datos suficientes", never a fake zero, and
 * the comparison is only shown when both periods have data.
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
    }>(),
    {
        current: null,
        previous: null,
        better: 'neutral',
        previousLabel: null,
        hint: undefined,
        icon: undefined,
        unavailableLabel: 'Sin datos suficientes',
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
    <div class="flex flex-col gap-2 rounded-xl border bg-card p-4 shadow-card">
        <div class="flex items-start justify-between gap-2">
            <span class="text-sm text-muted-foreground">{{ label }}</span>
            <component
                :is="icon"
                v-if="icon"
                class="size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </div>
        <p
            v-if="value !== null"
            class="text-2xl font-semibold tracking-tight tabular-nums"
        >
            {{ value }}
        </p>
        <p v-else class="text-sm font-medium text-muted-foreground">
            {{ unavailableLabel }}
        </p>
        <p
            v-if="change"
            class="flex items-center gap-1 text-xs tabular-nums"
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
                        ? 'Sin cambio'
                        : `${change.direction === 'up' ? '+' : '−'}${change.percent.toFixed(1)} %`
                }}
            </span>
            <span class="text-muted-foreground">
                vs. {{ previousText }} antes
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
        <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
    </div>
</template>
