<script setup lang="ts">
import { LayoutGrid, Table2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSlidingIndicator } from '@/composables/useSlidingIndicator';
import type { ViewMode } from '@/types';

/**
 * Switches a list between the table and the cards presentation. Purely
 * visual: the parent keeps its data, filters and pagination. A thumb slides
 * to the active view.
 */
const mode = defineModel<ViewMode>({ required: true });

const OPTIONS = [
    { value: 'table', label: 'Tabla', icon: Table2 },
    { value: 'cards', label: 'Tarjetas', icon: LayoutGrid },
] as const;

const group = ref<HTMLElement | null>(null);
const { style, animated, update } = useSlidingIndicator(
    group,
    '[aria-pressed=true]',
);

watch(mode, update, { flush: 'post' });
</script>

<template>
    <div
        ref="group"
        class="relative isolate inline-flex shrink-0 items-center rounded-lg border bg-surface-sunken p-0.5 shadow-[inset_0_1px_1.5px_color-mix(in_oklch,var(--shadow-ink)_7%,transparent)]"
        role="group"
        aria-label="Tipo de vista"
    >
        <span
            aria-hidden="true"
            class="pointer-events-none absolute top-0 left-0 -z-10 rounded-md border border-border-strong/60 bg-card shadow-(--shadow-control)"
            :class="
                animated &&
                'transition-[transform,width,height,opacity] duration-(--duration-panel) ease-(--ease-out-quint)'
            "
            :style="style"
        />
        <Tooltip v-for="option in OPTIONS" :key="option.value">
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="grid size-9 place-items-center rounded-md text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring aria-pressed:text-foreground sm:size-8"
                    :aria-pressed="mode === option.value"
                    :aria-label="`Vista de ${option.label.toLowerCase()}`"
                    @click="mode = option.value"
                >
                    <component
                        :is="option.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                </button>
            </TooltipTrigger>
            <TooltipContent>{{ option.label }}</TooltipContent>
        </Tooltip>
    </div>
</template>
