<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

/**
 * The chat pane when nothing is open: a large soft icon in a tinted
 * circle, a short title, an optional one-line hint (with a leading icon)
 * and the next actions (default slot). Below it, `extra` holds anything
 * else the screen offers (shortcuts, counters).
 */
withDefaults(
    defineProps<{
        icon: LucideIcon;
        title: string;
        hint?: string;
        hintIcon?: LucideIcon;
        tone?: 'primary' | 'muted';
    }>(),
    { hint: undefined, hintIcon: undefined, tone: 'primary' },
);
</script>

<template>
    <div class="grid reveal justify-items-start gap-3">
        <span
            class="relative mb-2 grid size-16 place-items-center rounded-full ring-4"
            :class="
                tone === 'muted'
                    ? 'bg-muted text-muted-foreground ring-muted/40'
                    : 'bg-primary-soft text-primary-soft-foreground ring-primary-soft/45'
            "
            aria-hidden="true"
        >
            <component :is="icon" class="size-7" />
        </span>
        <h2
            class="font-display text-2xl font-semibold tracking-tight text-balance sm:text-3xl"
        >
            {{ title }}
        </h2>
        <p
            v-if="hint"
            class="flex max-w-prose items-center gap-2 text-sm text-muted-foreground sm:text-base"
        >
            <component
                :is="hintIcon"
                v-if="hintIcon"
                class="size-4 shrink-0"
                aria-hidden="true"
            />
            <span class="text-pretty">{{ hint }}</span>
        </p>
        <div v-if="$slots.default" class="mt-2 flex flex-wrap gap-2">
            <slot />
        </div>
        <slot name="extra" />
    </div>
</template>
