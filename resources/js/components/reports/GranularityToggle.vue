<script setup lang="ts">
import { computed } from 'vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import type { FilterTabOption } from '@/components/filters/types';
import type { AnalyticsGranularity } from '@/types';

/**
 * Day / week / month switch of the volume charts: the pill track of the
 * inbox tabs, whose thumb glides to the chosen grain. Only the
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

const tabs = computed<FilterTabOption<AnalyticsGranularity>[]>(() =>
    props.options.map((option) => ({
        value: option,
        label: names[option],
        attrs: { 'data-granularity': option },
    })),
);

function choose(option: AnalyticsGranularity): void {
    if (option !== props.value) {
        emit('change', option);
    }
}
</script>

<template>
    <FilterTabs
        :options="tabs"
        :model-value="value"
        label="Agrupar por"
        class="[&_[data-filter-tab]]:h-7 [&_[data-filter-tab]]:px-2.5 [&_[data-filter-tab]]:text-xs"
        data-granularity-toggle
        @update:model-value="choose"
    />
</template>
