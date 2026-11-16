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
                'inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border px-2 text-xs font-medium whitespace-nowrap outline-none select-none',
                'transition-colors duration-(--duration-fast) ease-(--ease-standard) focus-visible:ring-2 focus-visible:ring-ring/50 pointer-coarse:h-9',
                pressed
                    ? 'border-primary/35 bg-primary-soft text-primary-soft-foreground'
                    : 'border-border bg-card text-foreground/80 shadow-(--shadow-control) hover:bg-muted hover:text-foreground',
            )
        "
        data-filter-toggle
        @click="emit('toggle')"
    >
        <Check v-if="pressed" class="size-3.5 shrink-0" aria-hidden="true" />
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
            :tone="
                pressed || count === 0
                    ? 'plain'
                    : tone === 'primary'
                      ? 'soft'
                      : tone
            "
        />
        <span v-if="srLabel" class="sr-only">: {{ srLabel }}</span>
    </button>
</template>
