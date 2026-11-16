<script setup lang="ts">
import '@/components/chat/chat.css';

/**
 * The date of a day of the history («Hoy», «Ayer», «lunes 3 de noviembre»).
 * Sticky inside its day's section: the next day pushes it away, so two
 * separators never pile up. With personal chat colors it keeps the theme's
 * own background so it stays readable on any canvas.
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
        class="pointer-events-none sticky top-0 z-10 flex justify-center py-1.5"
    >
        <span
            class="max-w-full rounded-full bg-background/85 px-3 py-1 text-center text-[0.6875rem] leading-none font-semibold text-balance break-words text-muted-foreground shadow-(--shadow-control) ring-1 ring-border/80 backdrop-blur-md first-letter:uppercase"
            >{{ label }}</span
        >
    </component>
</template>
