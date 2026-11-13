<script setup lang="ts">
import { CHAT_CANVAS } from '@/components/conversations/chatCanvas';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Placeholder of a chat while its conversation loads: the header, a few
 * bubbles on both sides and the composer, in the places the real ones take,
 * so the pane does not jump when the conversation arrives.
 */
const ROWS = [
    { side: 'in', width: 'w-56', height: 'h-10' },
    { side: 'in', width: 'w-72', height: 'h-16' },
    { side: 'out', width: 'w-64', height: 'h-10' },
    { side: 'in', width: 'w-44', height: 'h-10' },
    { side: 'out', width: 'w-80', height: 'h-20' },
    { side: 'out', width: 'w-40', height: 'h-10' },
] as const;
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col bg-background"
        role="status"
        aria-label="Cargando la conversación"
        data-chat-skeleton
    >
        <div class="flex items-center gap-3 border-b px-4 py-2.5">
            <Skeleton class="size-10 shrink-0 rounded-full" />
            <div class="grid flex-1 gap-2">
                <Skeleton class="h-4 w-44 max-w-full" />
                <Skeleton class="h-3 w-64 max-w-full" />
            </div>
        </div>
        <div
            class="min-h-0 flex-1 overflow-hidden px-3 pt-6 sm:px-6"
            :class="CHAT_CANVAS"
            aria-hidden="true"
        >
            <div
                v-for="(row, index) in ROWS"
                :key="index"
                class="mb-2.5 flex"
                :class="row.side === 'out' ? 'justify-end' : 'justify-start'"
            >
                <Skeleton
                    class="max-w-[80%] rounded-bubble"
                    :class="[row.width, row.height]"
                />
            </div>
        </div>
        <div class="border-t px-3 py-3 sm:px-5" aria-hidden="true">
            <Skeleton class="h-20 w-full rounded-xl" />
        </div>
    </div>
</template>
