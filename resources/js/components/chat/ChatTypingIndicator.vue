<script setup lang="ts">
import '@/components/chat/chat.css';

/**
 * Someone is writing: a received-style bubble with three rising dots, in
 * the place where their message will land, and who it is beside it.
 */
withDefaults(
    defineProps<{
        label: string;
        /** Personal chat colors: the label keeps the theme's background. */
        custom?: boolean;
    }>(),
    { custom: false },
);
</script>

<template>
    <div
        class="flex items-center gap-2 motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-1"
    >
        <span
            class="flex h-8 items-center gap-1 rounded-bubble rounded-bl-[0.2rem] bg-bubble-in px-3.5 ring-1 ring-border-strong/55 ring-inset"
            aria-hidden="true"
        >
            <span
                v-for="dot in 3"
                :key="dot"
                class="chat-typing-dot size-1.5 rounded-full bg-muted-foreground"
            />
        </span>
        <span
            class="truncate text-xs text-muted-foreground"
            :class="custom && 'rounded-full bg-background px-2 py-0.5'"
            >{{ label }}</span
        >
    </div>
</template>
