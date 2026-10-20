<script setup lang="ts">
import { computed } from 'vue';
import { swatchClasses } from '@/lib/swatches';
import type { ColorSwatch } from '@/types';

/**
 * Colored chip of a tag. Inactive tags stay visible where they were applied
 * but are shown muted and labelled as such.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        color: ColorSwatch;
        inactive?: boolean;
        size?: 'sm' | 'md';
    }>(),
    {
        inactive: false,
        size: 'sm',
    },
);

const label = computed(() =>
    props.inactive ? `${props.name} (inactiva)` : props.name,
);
</script>

<template>
    <span
        class="inline-flex max-w-48 items-center gap-1.5 rounded-full font-medium"
        :class="[
            size === 'md' ? 'px-2.5 py-1 text-sm' : 'px-2 py-0.5 text-xs',
            inactive
                ? 'border border-dashed bg-muted text-muted-foreground'
                : swatchClasses[color].soft,
        ]"
        :title="label"
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :class="
                inactive ? 'bg-muted-foreground/50' : swatchClasses[color].dot
            "
            aria-hidden="true"
        />
        <span class="truncate">{{ name }}</span>
        <span v-if="inactive" class="sr-only">(inactiva)</span>
    </span>
</template>
