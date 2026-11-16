<script setup lang="ts">
import '@/components/directory/directory.css';
import '@/components/filters/filters.css';
import type { ViewMode } from '@/types';

/**
 * Placeholder of the quick replies list while another tab loads, shaped like
 * the rows (list) or cards of the list so the page keeps its height. A light
 * sweeps across, staggered row by row.
 */
defineProps<{
    rows: number;
    layout: ViewMode;
}>();

const titleWidths = ['w-40', 'w-28', 'w-48', 'w-32'];
const bodyWidths = ['w-3/4', 'w-2/3', 'w-5/6', 'w-1/2'];
</script>

<template>
    <div role="status">
        <ul v-if="layout === 'table'" class="dir-rows" aria-hidden="true">
            <li
                v-for="row in rows"
                :key="row"
                class="flex items-center gap-3 rounded-xl py-2.5 pr-3 pl-3.5 sm:gap-4"
                :style="{ '--i': row, opacity: 1 - (row - 1) * 0.08 }"
            >
                <span class="list-shimmer size-10 shrink-0 rounded-xl" />
                <div class="grid min-w-0 flex-1 gap-2">
                    <div class="flex items-center gap-2">
                        <span class="list-shimmer h-5 w-16 rounded-md" />
                        <span
                            class="list-shimmer h-3.5 max-w-full rounded-full"
                            :class="titleWidths[row % titleWidths.length]"
                        />
                    </div>
                    <span
                        class="list-shimmer h-3 rounded-full"
                        :class="bodyWidths[row % bodyWidths.length]"
                    />
                    <span class="list-shimmer h-4 w-24 rounded-full" />
                </div>
            </li>
        </ul>
        <ul v-else class="dir-grid" aria-hidden="true">
            <li
                v-for="row in rows"
                :key="row"
                class="grid gap-3 rounded-xl border bg-card p-4"
                :style="{ '--i': row }"
            >
                <div class="flex items-center gap-2">
                    <span class="list-shimmer size-8 rounded-xl" />
                    <span class="list-shimmer h-5 w-16 rounded-md" />
                    <span class="list-shimmer h-3.5 w-24 rounded-full" />
                </div>
                <span class="list-shimmer h-3 w-full rounded-full" />
                <span class="list-shimmer h-3 w-2/3 rounded-full" />
                <span class="list-shimmer h-4 w-24 rounded-full" />
            </li>
        </ul>
        <span class="sr-only">Cargando respuestas…</span>
    </div>
</template>
