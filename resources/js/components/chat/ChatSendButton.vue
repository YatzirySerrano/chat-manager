<script setup lang="ts">
import { SendHorizontal } from '@lucide/vue';
import { Spinner } from '@/components/ui/spinner';
import '@/components/chat/chat.css';

/**
 * The send button of a composer, the same in both chats. It rests flat and
 * muted while there is nothing to send, fills with the palette gradient
 * once there is (its arrow springs upright, a halo follows the pointer), and shows a spinner (keeping its size) while the message goes
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
        class="chat-send inline-flex h-10 shrink-0 items-center justify-center gap-1.5 rounded-full text-sm font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/45 focus-visible:ring-offset-1 focus-visible:ring-offset-background disabled:cursor-not-allowed pointer-coarse:h-10"
        :class="[
            showLabel ? 'min-w-10 px-3 sm:px-4.5' : 'w-10',
            sending ? 'cursor-progress' : '',
            ready || sending
                ? tone === 'note'
                    ? 'bg-warning text-background shadow-[inset_0_1px_0_rgb(255_255_255/0.22),var(--shadow-control)]'
                    : 'accent-gradient shadow-[inset_0_1px_0_rgb(255_255_255/0.22),inset_0_-1px_0_rgb(0_0_0/0.1),var(--shadow-control)]'
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
