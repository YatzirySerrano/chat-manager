<script setup lang="ts">
import './admin.css';

/**
 * The shape of an administration list while it loads: rows with an avatar,
 * two lines of text and a pill, with a light sweeping across, staggered row
 * by row. Decorative (the list says it is busy with aria-busy).
 */
withDefaults(
    defineProps<{
        rows?: number;
        /** Rows start with a round avatar. */
        avatar?: boolean;
    }>(),
    { rows: 6, avatar: true },
);

const titleWidths = ['w-2/5', 'w-1/2', 'w-1/3', 'w-3/5'];
const lineWidths = ['w-3/5', 'w-2/5', 'w-1/2', 'w-1/3'];
</script>

<template>
    <div class="grid divide-y divide-border/60" aria-hidden="true">
        <div
            v-for="index in rows"
            :key="index"
            class="flex items-center gap-3 px-5 py-3.5"
            :style="{ '--i': index, opacity: 1 - (index - 1) * 0.1 }"
            data-admin-skeleton
        >
            <span
                v-if="avatar"
                class="admin-shimmer size-9 shrink-0 rounded-full"
            />
            <div class="grid min-w-0 flex-1 gap-2">
                <span
                    class="admin-shimmer h-3.5"
                    :class="titleWidths[index % titleWidths.length]"
                />
                <span
                    class="admin-shimmer h-2.5"
                    :class="lineWidths[index % lineWidths.length]"
                />
            </div>
            <span class="admin-shimmer hidden h-5 w-20 sm:block" />
            <span class="admin-shimmer hidden h-5 w-14 md:block" />
        </div>
    </div>
</template>
