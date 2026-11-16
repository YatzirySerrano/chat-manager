<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import './insights.css';

/**
 * Heading of a section of the insight pages (Panel principal, Reportes):
 * the section's icon on a gradient tile, an h2 in the display face, an
 * optional description and an `actions` slot at the right edge. Same role
 * as SectionHeading, with the look of the redesigned modules.
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
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
        <div class="flex min-w-0 items-start gap-3">
            <span
                v-if="icon"
                class="insight-tile mt-0.5"
                :data-tone="tone === 'primary' ? undefined : tone"
                aria-hidden="true"
            >
                <component :is="icon" class="size-4" />
            </span>
            <div class="min-w-0 space-y-0.5">
                <h2
                    :id="id"
                    class="font-display text-xl leading-snug font-semibold tracking-tight text-balance"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="max-w-prose text-sm text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
