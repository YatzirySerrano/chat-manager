<script setup lang="ts">
import './filters.css';

/**
 * The shape of a list while its first rows load (inbox and internal chat):
 * the same anatomy as a row (avatar, name and time, preview, chips) with a
 * light sweeping across, staggered row by row. Decorative.
 */
withDefaults(
    defineProps<{
        rows?: number;
        /** Inbox rows also show their chips line. */
        chips?: boolean;
    }>(),
    { rows: 7, chips: false },
);

const nameWidths = ['w-2/5', 'w-1/2', 'w-1/3', 'w-3/5'];
const previewWidths = ['w-4/5', 'w-3/5', 'w-11/12', 'w-2/3'];
</script>

<template>
    <div class="grid gap-px p-1.5" aria-hidden="true">
        <div
            v-for="index in rows"
            :key="index"
            class="flex items-start gap-2.5 rounded-lg px-3 py-2"
            :style="{ '--i': index, opacity: 1 - (index - 1) * 0.08 }"
            data-inbox-skeleton
        >
            <span class="list-shimmer size-9 shrink-0 rounded-full" />
            <div class="grid flex-1 gap-1.5 pt-0.5">
                <div class="flex justify-between gap-3">
                    <span
                        class="list-shimmer h-3.5 rounded-full"
                        :class="nameWidths[index % nameWidths.length]"
                    />
                    <span class="list-shimmer h-2.5 w-9 rounded-full" />
                </div>
                <span
                    class="list-shimmer h-3 rounded-full"
                    :class="previewWidths[index % previewWidths.length]"
                />
                <div v-if="chips" class="flex gap-1.5">
                    <span class="list-shimmer h-4 w-16 rounded-full" />
                    <span class="list-shimmer h-4 w-12 rounded-full" />
                </div>
            </div>
        </div>
    </div>
</template>
