<script setup lang="ts">
import './insights.css';

/**
 * The shape of a KpiCard while its figures load: tile, label, figure and
 * change pill with a light sweeping across. Decorative (the grid that holds
 * it carries aria-busy and the loading label).
 */
withDefaults(defineProps<{ index?: number }>(), { index: 0 });

const labelWidths = ['w-24', 'w-32', 'w-20', 'w-28'];
const figureWidths = ['w-16', 'w-20', 'w-14', 'w-24'];
</script>

<template>
    <div
        class="flex min-w-0 enter-fade flex-col gap-2.5 rounded-xl border bg-card p-4 shadow-(--shadow-control)"
        :style="{ '--i': index }"
        aria-hidden="true"
        data-kpi-skeleton
    >
        <div class="flex items-center gap-2.5">
            <span class="size-7 shimmer rounded-[0.6rem]" />
            <span
                class="h-2.5 shimmer rounded-full"
                :class="labelWidths[index % labelWidths.length]"
            />
        </div>
        <span
            class="h-7 shimmer rounded-lg"
            :class="figureWidths[index % figureWidths.length]"
        />
        <div class="flex items-center gap-2">
            <span class="h-5 w-14 shimmer rounded-full" />
            <span class="h-2.5 w-24 shimmer rounded-full" />
        </div>
    </div>
</template>
