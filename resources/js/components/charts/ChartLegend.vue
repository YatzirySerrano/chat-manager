<script setup lang="ts">
import type { ChartLegendItem } from './types';

/**
 * Series keys of a chart: the mark (line, dashed line or square) beside the
 * label, which keeps the text color (identity never relies on color alone).
 */
defineProps<{
    items: ChartLegendItem[];
}>();
</script>

<template>
    <ul class="flex flex-wrap gap-x-4 gap-y-1.5 text-xs text-muted-foreground">
        <li
            v-for="item in items"
            :key="item.key"
            class="flex min-w-0 items-center gap-1.5"
        >
            <span
                v-if="item.shape === 'square'"
                class="size-2.5 shrink-0 rounded-[3px]"
                :style="{ backgroundColor: item.color }"
                aria-hidden="true"
            />
            <span
                v-else
                class="w-4 shrink-0 border-t-2"
                :class="item.shape === 'dashed' && 'border-dashed'"
                :style="{ borderColor: item.color }"
                aria-hidden="true"
            />
            <span class="text-foreground">{{ item.label }}</span>
            <span v-if="item.value" class="tabular-nums">{{ item.value }}</span>
        </li>
    </ul>
</template>
