<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * A single metric. `value === null` renders an explicit empty state instead
 * of a fake zero, so unavailable data is never presented as real.
 *
 * `compact` (default) is meant for metric rows: small padding, the icon
 * inline with the label and the value at text-xl. The label and the hint
 * are never cut: they wrap (long words break and hyphenate), so the card
 * only grows taller in narrow columns. Grids of compact cards use one
 * column below `sm`.
 * `default` keeps the larger, roomier card for a single headline figure.
 */
withDefaults(
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
</script>

<template>
    <div
        v-if="size === 'compact'"
        class="flex min-w-0 flex-col gap-1 rounded-xl border bg-surface-raised px-3.5 py-3 shadow-card"
        data-stat-card
    >
        <div class="flex min-w-0 items-start gap-1.5 text-muted-foreground">
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

        <template v-if="loading">
            <Skeleton class="h-7 w-14" />
            <Skeleton class="h-3 w-24" />
        </template>
        <template v-else>
            <p
                v-if="value !== null"
                class="text-xl font-semibold tracking-tight tabular-nums"
            >
                {{ value }}
            </p>
            <p v-else class="py-1 text-sm font-medium text-muted-foreground">
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

    <div
        v-else
        class="flex min-w-0 flex-col gap-3 rounded-xl border bg-surface-raised p-5 shadow-card"
        data-stat-card
    >
        <div class="flex items-center justify-between gap-2">
            <span
                class="min-w-0 text-sm font-medium break-words hyphens-auto text-muted-foreground"
                data-stat-label
                >{{ label }}</span
            >
            <span
                v-if="icon"
                class="flex size-8 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
            >
                <component :is="icon" class="size-4" aria-hidden="true" />
            </span>
        </div>

        <template v-if="loading">
            <Skeleton class="h-7 w-16" />
            <Skeleton class="h-3 w-32" />
        </template>
        <template v-else>
            <p
                v-if="value !== null"
                class="text-2xl font-semibold tracking-tight tabular-nums"
            >
                {{ value }}
            </p>
            <p v-else class="text-sm font-medium text-muted-foreground">
                {{ unavailableLabel ?? 'Sin datos todavía' }}
            </p>
            <p
                v-if="hint"
                class="text-xs break-words hyphens-auto text-muted-foreground"
                data-stat-hint
            >
                {{ hint }}
            </p>
        </template>
    </div>
</template>
