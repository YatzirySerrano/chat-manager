<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { MessagesSquare } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage();
const name = computed(() => page.props.platform?.name ?? page.props.name);
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-[inset_0_1px_0_rgb(255_255_255/0.3),0_4px_14px_-4px_var(--primary)] transition-transform duration-(--duration-panel) ease-(--ease-spring) group-hover/logo:scale-105 group-hover/logo:-rotate-6"
    >
        <MessagesSquare class="size-4.5" aria-hidden="true" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span
            class="truncate font-display text-base leading-tight font-semibold text-foreground"
            >{{ name }}</span
        >
        <span class="truncate text-xs text-muted-foreground">
            Centro de atención
        </span>
    </div>
</template>
