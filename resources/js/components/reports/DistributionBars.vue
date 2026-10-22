<script setup lang="ts">
import { computed } from 'vue';

/**
 * Horizontal bars of parts of a whole (e.g. conversations by status), each
 * labelled with its count and share, so no value depends on color alone.
 */
const props = defineProps<{
    items: { key: string; label: string; count: number }[];
    /** Accessible name of the list. */
    label: string;
}>();

const total = computed(() =>
    props.items.reduce((sum, item) => sum + item.count, 0),
);
const largest = computed(() =>
    Math.max(1, ...props.items.map((item) => item.count)),
);

function share(count: number): string {
    return total.value === 0
        ? '0 %'
        : `${((count / total.value) * 100).toFixed(1)} %`;
}
</script>

<template>
    <ul class="space-y-3" :aria-label="label">
        <li v-for="item in items" :key="item.key" class="space-y-1">
            <div class="flex items-baseline justify-between gap-2 text-sm">
                <span>{{ item.label }}</span>
                <span class="text-muted-foreground tabular-nums">
                    {{ item.count.toLocaleString('es-MX') }} ·
                    {{ share(item.count) }}
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary"
                    :style="{ width: `${(item.count / largest) * 100}%` }"
                />
            </div>
        </li>
    </ul>
</template>
