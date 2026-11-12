<script setup lang="ts">
import { SearchX } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Empty list or section: says what is missing and offers the next action
 * (default slot). It reads from the left edge like the rest of the page:
 * the icon, then the text, then the action.
 *
 * `tone="filtered"` is for lists that are empty only because of the applied
 * filters: it defaults to a "no results" icon and the default slot is meant
 * for the "Quitar filtros" action.
 */
const props = withDefaults(
    defineProps<{
        icon?: LucideIcon;
        title: string;
        description?: string;
        tone?: 'neutral' | 'filtered';
        class?: HTMLAttributes['class'];
    }>(),
    {
        icon: undefined,
        description: undefined,
        tone: 'neutral',
        class: undefined,
    },
);

const displayedIcon = computed<LucideIcon | undefined>(
    () => props.icon ?? (props.tone === 'filtered' ? SearchX : undefined),
);
</script>

<template>
    <div
        data-slot="empty-state"
        :class="
            cn(
                'flex reveal flex-col items-start gap-4 rounded-xl border border-dashed px-5 py-7 text-left sm:flex-row sm:px-7 sm:py-9',
                tone === 'filtered' ? 'bg-surface-sunken/60' : 'bg-card/60',
                props.class,
            )
        "
    >
        <div
            v-if="displayedIcon"
            class="flex size-12 shrink-0 items-center justify-center rounded-xl"
            :class="
                tone === 'filtered'
                    ? 'bg-muted text-muted-foreground'
                    : 'bg-primary-soft text-primary-soft-foreground'
            "
        >
            <component :is="displayedIcon" class="size-6" aria-hidden="true" />
        </div>
        <div class="flex min-w-0 flex-col items-start gap-3">
            <div class="max-w-prose space-y-1">
                <p class="font-display text-xl leading-snug font-semibold">
                    {{ title }}
                </p>
                <p
                    v-if="description"
                    class="text-sm text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <slot />
        </div>
    </div>
</template>
