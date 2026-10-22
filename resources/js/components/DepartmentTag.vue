<script setup lang="ts">
import { colorPresentations } from '@/lib/colors';
import type { AppColor } from '@/types';

defineProps<{
    name: string;
    color: AppColor;
    muted?: boolean;
}>();
</script>

<template>
    <span
        class="inline-flex max-w-40 items-center gap-1.5 rounded-md border px-1.5 py-0.5 text-xs"
        :class="muted ? 'text-muted-foreground line-through' : ''"
        :title="muted ? `${name} (eliminado)` : name"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            v-bind="colorPresentations(color).dot"
            aria-hidden="true"
        />
        <span class="truncate">{{ name }}</span>
    </span>
</template>
