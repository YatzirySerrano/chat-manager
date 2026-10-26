<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, useTemplateRef } from 'vue';
import type { ChartTooltipRow } from './types';

/**
 * Floating readout of a chart point, positioned inside the chart container
 * (never outside it, so it cannot cause horizontal scroll). It is visual
 * only: the same text is announced by the chart's live region and the
 * values are always in the «Ver como tabla» view.
 */
const props = defineProps<{
    title: string;
    rows: ChartTooltipRow[];
    /** Anchor in px relative to the container. */
    x: number;
    y: number;
    containerWidth: number;
    /** Where the readout sits relative to the anchor (beside a crosshair or centered over a mark). */
    side?: 'left' | 'right' | 'center';
}>();

const tooltip = useTemplateRef<HTMLElement>('tooltip');
const { width } = useElementSize(tooltip, undefined, { box: 'border-box' });

const gap = 12;

const left = computed(() => {
    const preferred =
        props.side === 'right'
            ? props.x + gap
            : props.side === 'left'
              ? props.x - gap - width.value
              : props.x - width.value / 2;

    return Math.min(
        Math.max(preferred, 0),
        Math.max(0, props.containerWidth - width.value),
    );
});
</script>

<template>
    <div
        ref="tooltip"
        role="tooltip"
        data-chart-tooltip
        class="pointer-events-none absolute z-20 max-w-[16rem] min-w-[9rem] rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-popover"
        :style="{
            left: `${left}px`,
            top: `${Math.max(0, y)}px`,
            visibility: width > 0 ? 'visible' : 'hidden',
        }"
    >
        <p class="mb-1 font-medium text-pretty">{{ title }}</p>
        <ul class="space-y-0.5">
            <li
                v-for="row in rows"
                :key="row.key"
                class="flex items-center gap-2 tabular-nums"
            >
                <span
                    v-if="row.shape === 'square'"
                    class="size-2.5 shrink-0 rounded-[3px]"
                    :style="{ backgroundColor: row.color }"
                    aria-hidden="true"
                />
                <span
                    v-else
                    class="w-3 shrink-0 border-t-2"
                    :class="row.shape === 'dashed' && 'border-dashed'"
                    :style="{ borderColor: row.color }"
                    aria-hidden="true"
                />
                <span class="font-semibold text-foreground">{{
                    row.value
                }}</span>
                <span class="min-w-0 truncate text-muted-foreground">{{
                    row.name
                }}</span>
            </li>
        </ul>
    </div>
</template>
