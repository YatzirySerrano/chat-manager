<script setup lang="ts">
import { Shapes } from '@lucide/vue';
import { computed } from 'vue';
import { colorPresentations } from '@/lib/colors';
import type { AppColor } from '@/types';

/**
 * The request category (type) of a conversation: an icon and its name on
 * the category's color, told apart from tags by its shape. A deleted
 * category stays visible as history, muted and labelled.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        color: AppColor;
        deleted?: boolean;
    }>(),
    { deleted: false },
);

const presentation = computed(() => colorPresentations(props.color));
const label = computed(() =>
    props.deleted
        ? `Categoría: ${props.name} (eliminada)`
        : `Categoría: ${props.name}`,
);
</script>

<template>
    <span
        class="inline-flex max-w-44 items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium"
        :class="
            deleted
                ? 'border border-dashed bg-muted text-muted-foreground'
                : presentation.soft.class
        "
        :style="deleted ? undefined : presentation.soft.style"
        :title="label"
        data-category
    >
        <Shapes class="size-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">Categoría:</span>
        <span class="truncate">{{ name }}</span>
        <span v-if="deleted" class="sr-only">(eliminada)</span>
    </span>
</template>
