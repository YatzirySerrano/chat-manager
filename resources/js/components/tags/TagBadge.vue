<script setup lang="ts">
import { computed } from 'vue';
import { colorPresentations } from '@/lib/colors';
import type { AppColor } from '@/types';

/**
 * Solid colored chip of a tag. Deleted tags stay visible where they were applied
 * (as history) but are shown muted and labelled as such.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        color: AppColor;
        deleted?: boolean;
        size?: 'sm' | 'md';
    }>(),
    {
        deleted: false,
        size: 'sm',
    },
);

const presentation = computed(() => colorPresentations(props.color));

const label = computed(() =>
    props.deleted ? `${props.name} (eliminada)` : props.name,
);
</script>

<!--
    Solid chip: the tag's color as background with white (or black, when it
    reads better) text, rounded, like the labels of the conversation list.
-->
<template>
    <span
        class="inline-flex max-w-48 items-center rounded-md font-semibold shadow-xs"
        :class="[
            size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs',
            deleted
                ? 'border border-dashed bg-muted text-muted-foreground shadow-none'
                : presentation.solid.class,
        ]"
        :style="deleted ? undefined : presentation.solid.style"
        :title="label"
    >
        <span class="truncate">{{ name }}</span>
        <span v-if="deleted" class="sr-only">(eliminada)</span>
    </span>
</template>
