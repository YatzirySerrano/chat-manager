<script setup lang="ts">
import type { AnalyticsGranularity } from '@/types';

/**
 * Day / week / month switch of the volume charts: a small segmented
 * control in the language of the period filter. Only the
 * granularities the server allows for the period are offered; the choice
 * travels in the URL like every other filter.
 */
const props = defineProps<{
    value: AnalyticsGranularity;
    options: AnalyticsGranularity[];
}>();

const emit = defineEmits<{ change: [value: AnalyticsGranularity] }>();

const names: Record<AnalyticsGranularity, string> = {
    day: 'Día',
    week: 'Semana',
    month: 'Mes',
};

function choose(option: AnalyticsGranularity): void {
    if (option !== props.value) {
        emit('change', option);
    }
}
</script>

<template>
    <div
        class="inline-flex h-7 items-center gap-0.5 rounded-md border bg-card p-0.5"
        role="group"
        aria-label="Agrupar por"
        data-granularity-toggle
    >
        <button
            v-for="option in options"
            :key="option"
            type="button"
            class="inline-flex h-full items-center rounded-[5px] px-2 text-xs font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-colors"
            :class="
                option === value
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground hover:text-foreground'
            "
            :aria-pressed="option === value"
            :data-granularity="option"
            @click="choose(option)"
        >
            {{ names[option] }}
        </button>
    </div>
</template>
