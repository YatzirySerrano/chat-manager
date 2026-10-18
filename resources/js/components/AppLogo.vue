<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { MessagesSquare } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage();
const name = computed(() => page.props.platform?.name ?? page.props.name);
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-card"
    >
        <MessagesSquare class="size-4.5" aria-hidden="true" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="truncate leading-tight font-semibold">{{ name }}</span>
        <span class="truncate text-xs text-muted-foreground">
            Centro de atención
        </span>
    </div>
</template>
