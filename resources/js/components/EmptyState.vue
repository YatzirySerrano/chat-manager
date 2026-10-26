<script setup lang="ts">
import { SearchX } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Empty list or section. `tone="filtered"` is for lists that are empty only
 * because of the applied filters: it defaults to a "no results" icon and the
 * default slot is meant for the "Quitar filtros" action.
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
        :class="
            cn(
                'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center',
                tone === 'filtered' ? 'bg-surface-sunken/60' : 'bg-card/60',
                props.class,
            )
        "
    >
        <div
            v-if="displayedIcon"
            class="flex size-10 items-center justify-center rounded-lg"
            :class="
                tone === 'filtered'
                    ? 'bg-muted text-muted-foreground'
                    : 'bg-primary-soft text-primary-soft-foreground'
            "
        >
            <component :is="displayedIcon" class="size-5" aria-hidden="true" />
        </div>
        <div class="max-w-sm space-y-1">
            <p class="text-base font-semibold">{{ title }}</p>
            <p
                v-if="description"
                class="text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <slot />
    </div>
</template>
