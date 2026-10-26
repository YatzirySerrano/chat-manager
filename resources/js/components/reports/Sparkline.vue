<script setup lang="ts">
import { computed } from 'vue';

/**
 * Tiny trend line without axes, for a stat card. The exact values belong
 * to the accessible label; the line only shows the shape.
 */
const props = defineProps<{
    values: number[];
    label: string;
}>();

const width = 120;
const height = 32;

const points = computed(() => {
    const max = Math.max(1, ...props.values);
    const last = Math.max(1, props.values.length - 1);

    return props.values
        .map(
            (value, index) =>
                `${((index / last) * width).toFixed(1)},${(height - 2 - (value / max) * (height - 4)).toFixed(1)}`,
        )
        .join(' ');
});
</script>

<template>
    <svg
        :viewBox="`0 0 ${width} ${height}`"
        class="h-8 w-full"
        role="img"
        :aria-label="label"
        preserveAspectRatio="none"
    >
        <polyline
            :points="points"
            fill="none"
            :style="{ stroke: 'var(--chart-primary)' }"
            stroke-width="2"
            stroke-linejoin="round"
            stroke-linecap="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>
