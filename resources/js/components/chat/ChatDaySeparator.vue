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
            class="max-w-full rounded-full glass px-3 py-1.5 text-center text-[0.6875rem] leading-none font-semibold text-balance break-words text-foreground/75 shadow-(--shadow-control) ring-1 ring-border/70 first-letter:uppercase motion-safe:animate-in motion-safe:zoom-in-95 motion-safe:fade-in"
            >{{ label }}</span
        >
    </component>
</template>
