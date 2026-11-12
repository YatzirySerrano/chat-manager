<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import { NumberTicker } from '@/components/ui/number-ticker';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * A single metric. `value === null` renders an explicit empty state instead
 * of a fake zero, so unavailable data is never presented as real.
 *
 * The figure is set in the display face with tabular numerals. A plain
 * number (or a string made only of digits) counts up to its value; any
 * other text ("3 min", "85 %") is shown as is. A light follows the pointer
 * over the card (`spotlight` utility).
 *
 * `compact` (default) is meant for metric rows: small padding, the icon
 * inline with the label. The label and the hint are never cut: they wrap
 * (long words break and hyphenate), so the card only grows taller in narrow
 * columns. Grids of compact cards use one column below `sm`.
 * `default` keeps the larger, roomier card for a single headline figure.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        value: string | number | null;
        icon?: LucideIcon;
        hint?: string;
        unavailableLabel?: string;
        loading?: boolean;
        size?: 'compact' | 'default';
    }>(),
    {
        icon: undefined,
        hint: undefined,
        unavailableLabel: undefined,
        loading: false,
        size: 'compact',
    },
);

/** The value as a number when it can count up, otherwise null. */
const numeric = computed<number | null>(() => {
    if (typeof props.value === 'number') {
        return Number.isFinite(props.value) ? props.value : null;
    }

    return typeof props.value === 'string' && /^\d{1,9}$/.test(props.value)
        ? Number(props.value)
        : null;
});

const decimals = computed(() => {
    const text = String(numeric.value ?? '');
    const dot = text.indexOf('.');

    return dot === -1 ? 0 : Math.min(2, text.length - dot - 1);
});
</script>

<template>
    <div
        class="spotlight flex min-w-0 flex-col rounded-xl border bg-card transition-[border-color] duration-(--duration-base)"
        :class="size === 'compact' ? 'gap-1 px-4 py-3.5' : 'gap-3 p-5'"
        data-stat-card
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
                data-stat-label
                >{{ label }}</span
            >
        </div>
        <div v-else class="flex items-center justify-between gap-2">
            <span
                class="min-w-0 text-sm font-medium break-words hyphens-auto text-muted-foreground"
                data-stat-label
                >{{ label }}</span
            >
            <span
                v-if="icon"
                class="flex size-9 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
            >
                <component :is="icon" class="size-4.5" aria-hidden="true" />
            </span>
        </div>

        <template v-if="loading">
            <Skeleton :class="size === 'compact' ? 'h-8 w-14' : 'h-9 w-20'" />
            <Skeleton class="h-3 w-28" />
        </template>
        <template v-else>
            <p
                v-if="value !== null"
                class="font-display font-semibold tabular-nums"
                :class="
                    size === 'compact'
                        ? 'text-[1.625rem] leading-8'
                        : 'text-4xl leading-none'
                "
            >
                <NumberTicker
                    v-if="numeric !== null"
                    :value="numeric"
                    :decimals="decimals"
                />
                <template v-else>{{ value }}</template>
            </p>
            <p
                v-else
                class="text-sm font-medium text-muted-foreground"
                :class="size === 'compact' && 'py-1'"
            >
                {{ unavailableLabel ?? 'Sin datos todavía' }}
            </p>
            <p
                v-if="hint"
                class="text-xs text-pretty break-words hyphens-auto text-muted-foreground"
                data-stat-hint
            >
                {{ hint }}
            </p>
        </template>
    </div>
</template>
