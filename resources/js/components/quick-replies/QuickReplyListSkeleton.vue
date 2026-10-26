<script setup lang="ts">
import RecordGrid from '@/components/RecordGrid.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { ViewMode } from '@/types';

/**
 * Placeholder of the quick replies list while another tab loads, shaped like
 * the rows (table) or cards of the list so the page keeps its height.
 */
defineProps<{
    rows: number;
    layout: ViewMode;
}>();
</script>

<template>
    <div role="status">
        <ul v-if="layout === 'table'" class="divide-y" aria-hidden="true">
            <li
                v-for="row in rows"
                :key="row"
                class="flex items-center gap-4 py-3 pr-5 pl-5"
            >
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex items-center gap-2">
                        <Skeleton class="h-5 w-20" />
                        <Skeleton class="h-4 w-40 max-w-full" />
                    </div>
                    <Skeleton class="h-3 w-3/4" />
                </div>
                <Skeleton class="hidden h-4 w-12 sm:block" />
                <Skeleton class="size-8 rounded-md" />
            </li>
        </ul>
        <RecordGrid v-else aria-hidden="true">
            <li
                v-for="row in rows"
                :key="row"
                class="space-y-3 rounded-xl border p-4"
            >
                <div class="flex items-center gap-2">
                    <Skeleton class="h-5 w-20" />
                    <Skeleton class="h-4 w-28" />
                </div>
                <Skeleton class="h-3 w-full" />
                <Skeleton class="h-3 w-2/3" />
                <Skeleton class="h-4 w-24" />
            </li>
        </RecordGrid>
        <span class="sr-only">Cargando respuestas…</span>
    </div>
</template>
