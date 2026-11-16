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
            class="chat-bubble-in flex h-9 items-center gap-1 rounded-[1.25rem] rounded-bl-[0.25rem] bg-bubble-in px-4 motion-safe:animate-in motion-safe:duration-300 motion-safe:zoom-in-75"
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
            :class="
                custom && 'rounded-full glass px-2 py-0.5 ring-1 ring-border/70'
            "
            >{{ label }}</span
        >
    </div>
</template>
