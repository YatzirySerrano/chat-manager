<script setup lang="ts">
import { computed } from 'vue';
import { colorPresentations } from '@/lib/colors';
import type { AppColor } from '@/types';

/**
 * Colored chip of a tag. Deleted tags stay visible where they were applied
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

<template>
    <span
        class="inline-flex max-w-48 items-center gap-1.5 rounded-full font-medium"
        :class="[
            size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs',
            deleted
                ? 'border border-dashed bg-muted text-muted-foreground'
                : presentation.soft.class,
        ]"
        :style="deleted ? undefined : presentation.soft.style"
        :title="label"
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :class="deleted ? 'bg-muted-foreground/50' : presentation.dot.class"
            :style="deleted ? undefined : presentation.dot.style"
            aria-hidden="true"
        />
        <span class="truncate">{{ name }}</span>
        <span v-if="deleted" class="sr-only">(eliminada)</span>
    </span>
</template>
