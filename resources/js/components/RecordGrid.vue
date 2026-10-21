<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Responsive grid for RecordCard items: as many compact columns as fit,
 * a single column on phones.
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <ul
        role="list"
        :class="
            cn(
                'grid grid-cols-[repeat(auto-fill,minmax(min(100%,17rem),1fr))] gap-3 p-4',
                props.class,
            )
        "
    >
        <slot />
    </ul>
</template>
