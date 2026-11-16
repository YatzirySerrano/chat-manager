<script setup lang="ts">
import { SendHorizontal } from '@lucide/vue';
import { Spinner } from '@/components/ui/spinner';
import '@/components/chat/chat.css';

/**
 * The send button of a composer, the same in both chats. It rests flat and
 * muted while there is nothing to send, fills with the palette colour once
 * there is, and shows a spinner (keeping its size) while the message goes
 * out. `label` is always its accessible name; with `showLabel` it is also
 * written next to the icon from small screens up.
 */
withDefaults(
    defineProps<{
        ready: boolean;
        sending?: boolean;
        label: string;
        /** Text while sending («Enviando…», «Guardando…»). */
        sendingLabel?: string;
        /** «note»: an internal note (warning tone instead of the palette). */
        tone?: 'primary' | 'note';
        showLabel?: boolean;
        type?: 'submit' | 'button';
        title?: string;
    }>(),
    {
        sending: false,
        sendingLabel: 'Enviando…',
        tone: 'primary',
        showLabel: false,
        type: 'button',
        title: undefined,
    },
);

defineEmits<{ click: [event: MouseEvent] }>();
</script>

<template>
    <button
        :type="type"
        class="chat-send inline-flex h-9 shrink-0 items-center justify-center gap-1.5 rounded-lg text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-offset-1 focus-visible:ring-offset-background disabled:cursor-not-allowed pointer-coarse:h-10"
        :class="[
            showLabel ? 'min-w-9 px-3 sm:px-4' : 'w-9 pointer-coarse:w-10',
            sending ? 'cursor-progress' : '',
            ready || sending
                ? tone === 'note'
                    ? 'bg-warning text-background'
                    : 'bg-primary text-primary-foreground'
                : 'bg-muted text-muted-foreground',
        ]"
        :disabled="!ready || sending"
        :aria-label="sending ? sendingLabel : label"
        :aria-busy="sending"
        :title="title ?? label"
        :data-ready="ready && !sending ? 'true' : 'false'"
        data-chat-send
        @click="$emit('click', $event)"
    >
        <Spinner v-if="sending" class="size-4" />
        <span v-else data-send-icon class="grid place-items-center">
            <slot name="icon">
                <SendHorizontal class="size-4" aria-hidden="true" />
            </slot>
        </span>
        <span v-if="showLabel" class="hidden sm:inline" aria-hidden="true">{{
            sending ? sendingLabel : label
        }}</span>
    </button>
</template>
