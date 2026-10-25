<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * A single metric. `value === null` renders an explicit empty state instead
 * of a fake zero, so unavailable data is never presented as real.
 *
 * `compact` (default) is meant for metric rows: small padding, the icon
 * inline with the label, the value at text-xl and the hint clamped to two
 * lines (the full text stays available as a tooltip and to screen readers).
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
        class="flex min-w-0 flex-col gap-1 rounded-xl border bg-card px-3.5 py-3 shadow-card"
    >
        <div class="flex min-w-0 items-center gap-1.5 text-muted-foreground">
            <component
                :is="icon"
                v-if="icon"
                class="size-3.5 shrink-0"
                aria-hidden="true"
            />
            <span class="line-clamp-2 text-xs font-medium">{{ label }}</span>
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
                class="line-clamp-2 text-xs text-muted-foreground"
                :title="hint"
            >
                {{ hint }}
            </p>
        </template>
    </div>

    <div
        v-else
        class="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-5 shadow-card"
    >
        <div class="flex items-center justify-between gap-2">
            <span class="text-sm text-muted-foreground">{{ label }}</span>
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
            <p v-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
        </template>
    </div>
</template>
