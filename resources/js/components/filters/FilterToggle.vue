<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import { cn } from '@/lib/utils';

/**
 * A quick filter that combines with the others (No leídas, Esperando
 * respuesta, Menciones, Completadas): one click turns it on, another one
 * off. On, it shows a check instead of its icon, so the state is never told
 * by color alone; its counter says how many rows it would leave.
 */
withDefaults(
    defineProps<{
        pressed: boolean;
        label: string;
        icon?: LucideIcon;
        count?: number | null;
        /** Counter color while off: the brand (unread) or attention. */
        tone?: 'primary' | 'warning' | 'success' | 'muted';
        /** What the counter means, for screen readers. */
        srLabel?: string;
        title?: string;
    }>(),
    {
        icon: undefined,
        count: null,
        tone: 'muted',
        srLabel: undefined,
        title: undefined,
    },
);

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="pressed"
        :title="title"
        :class="
            cn(
                'group/toggle inline-flex h-7 shrink-0 items-center gap-1.5 rounded-full border pr-1.5 pl-2.5 text-xs font-medium whitespace-nowrap outline-none select-none',
                'transition-[background-color,border-color,color,box-shadow,transform,translate] duration-(--duration-base) ease-(--ease-spring-soft) focus-visible:ring-[3px] focus-visible:ring-primary/30 active:scale-[0.94] pointer-coarse:h-9',
                count === null && 'pr-2.5',
                pressed
                    ? 'border-transparent accent-gradient glow'
                    : 'border-border bg-card text-muted-foreground shadow-(--shadow-control) hover:-translate-y-px hover:border-border-strong hover:text-foreground',
            )
        "
        data-filter-toggle
        @click="emit('toggle')"
    >
        <Check
            v-if="pressed"
            class="size-3.5 shrink-0 motion-safe:animate-in motion-safe:zoom-in-50 motion-safe:fade-in"
            aria-hidden="true"
        />
        <component
            :is="icon"
            v-else-if="icon"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <span>{{ label }}</span>
        <CountBadge
            v-if="count !== null"
            :value="count"
            :tone="pressed ? 'inverse' : count > 0 ? tone : 'muted'"
            :halo="tone === 'primary' || tone === 'warning'"
        />
        <span v-if="srLabel" class="sr-only">: {{ srLabel }}</span>
    </button>
</template>
