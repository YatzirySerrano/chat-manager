<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

/**
 * Heading of a section of the insight pages (Panel principal, Reportes,
 * Seguimiento de atención): a compact h2, an optional one-line description
 * and an `actions` slot at the right edge. Typography carries the
 * hierarchy; `icon` and `tone` are accepted for compatibility but no longer
 * drawn (no decorative tile on every heading).
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        id?: string;
        icon?: LucideIcon;
        tone?: 'primary' | 'warning' | 'success' | 'destructive' | 'muted';
    }>(),
    {
        description: undefined,
        id: undefined,
        icon: undefined,
        tone: 'primary',
    },
);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-1.5">
        <div class="min-w-0 space-y-0.5">
            <h2
                :id="id"
                class="text-[0.9375rem] leading-6 font-semibold tracking-tight text-balance"
            >
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="max-w-prose text-[0.8125rem] text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
