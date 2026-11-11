<script setup lang="ts">
import type { AnalyticsGranularity } from '@/types';

/**
 * Day / week / month switch of the volume charts. Only the granularities
 * the server allows for the period are offered; the choice travels in the
 * URL like every other filter.
 */
defineProps<{
    value: AnalyticsGranularity;
    options: AnalyticsGranularity[];
}>();

const emit = defineEmits<{ change: [value: AnalyticsGranularity] }>();

const names: Record<AnalyticsGranularity, string> = {
    day: 'Día',
    week: 'Semana',
    month: 'Mes',
};
</script>

<template>
    <div
        class="inline-flex rounded-md border p-0.5"
        role="group"
        aria-label="Agrupar por"
        data-granularity-toggle
    >
        <button
            v-for="option in options"
            :key="option"
            type="button"
            class="rounded-[5px] px-2 py-1 text-xs font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :class="
                option === value
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="option === value"
            :data-granularity="option"
            @click="option !== value && emit('change', option)"
        >
            {{ names[option] }}
        </button>
    </div>
</template>
