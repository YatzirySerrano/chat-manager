<script setup lang="ts">
import { swatchClasses } from '@/lib/swatches';
import type { ColorSwatch } from '@/types';

defineProps<{
    name: string;
    color: ColorSwatch;
    muted?: boolean;
}>();
</script>

<template>
    <span
        class="inline-flex max-w-40 items-center gap-1.5 rounded-md border px-1.5 py-0.5 text-xs"
        :class="muted ? 'text-muted-foreground line-through' : ''"
        :title="muted ? `${name} (inactivo)` : name"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :class="swatchClasses[color].dot"
            aria-hidden="true"
        />
        <span class="truncate">{{ name }}</span>
    </span>
</template>
