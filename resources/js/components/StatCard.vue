<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * A single metric. `value === null` renders an explicit empty state instead
 * of a fake zero, so unavailable data is never presented as real.
 */
defineProps<{
    label: string;
    value: string | number | null;
    icon?: LucideIcon;
    hint?: string;
    unavailableLabel?: string;
    loading?: boolean;
}>();
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border bg-card p-5 shadow-card">
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
