<script setup lang="ts">
import '@/components/chat/chat.css';

/**
 * The date of a day of the history («Hoy», «Ayer», «lunes 3 de noviembre»).
 * Sticky inside its day's section: the next day pushes it away, so two
 * separators never pile up. It sticks right under the frosted header
 * (--chat-sticky-top, set by the history) and is glass itself, so it stays
 * readable on any canvas, personal colors included.
 */
withDefaults(
    defineProps<{
        label: string;
        /** Heading element (the customer chat uses h3 for its outline). */
        as?: 'h3' | 'div';
    }>(),
    { as: 'div' },
);
</script>

<template>
    <component
        :is="as"
        class="pointer-events-none sticky top-(--chat-sticky-top,0px) z-10 flex justify-center py-2"
    >
        <span
            class="max-w-full rounded-md border bg-background/95 px-2.5 py-1 text-center text-[0.6875rem] leading-none font-medium text-balance break-words text-muted-foreground first-letter:uppercase"
            >{{ label }}</span
        >
    </component>
</template>
