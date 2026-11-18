<script setup lang="ts">
import { Bot, Eye } from '@lucide/vue';
import WhatsAppText from '@/components/WhatsAppText.vue';

/**
 * Live preview of what the chatbot sends, drawn as a chat with the
 * platform's own bubbles: the customer's messages on the left, the
 * automatic ones on the right with their kind above. It never sends
 * anything; variables arrive already filled with sample values.
 */
export type ChatbotPreviewMessage = {
    key: string;
    from: 'customer' | 'bot';
    text: string;
    /** Kind of automatic message («Menú», «Respuesta»…), above the bubble. */
    label?: string;
    /** Dimmed: configured but off. */
    muted?: boolean;
};

defineProps<{
    title: string;
    subtitle?: string;
    messages: ChatbotPreviewMessage[];
}>();
</script>

<template>
    <figure
        class="overflow-hidden rounded-xl border bg-surface-sunken"
        aria-label="Vista previa del chatbot"
    >
        <figcaption
            class="flex items-center gap-2.5 border-b bg-card px-4 py-3"
        >
            <span
                class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground"
                aria-hidden="true"
            >
                <Bot class="size-4.5" />
            </span>
            <span class="grid min-w-0">
                <span class="truncate text-sm font-semibold">{{ title }}</span>
                <span class="text-xs text-muted-foreground">{{
                    subtitle ?? 'Vista previa · no se envía nada'
                }}</span>
            </span>
        </figcaption>

        <ol
            class="flex max-h-[36rem] min-h-72 flex-col gap-3 overflow-y-auto p-4"
            aria-live="polite"
        >
            <li
                v-for="message in messages"
                :key="message.key"
                class="flex flex-col gap-1"
                :class="message.from === 'bot' ? 'items-end' : 'items-start'"
                data-preview-message
            >
                <span
                    v-if="message.label"
                    class="flex items-center gap-1 text-[0.6875rem] font-medium text-muted-foreground"
                >
                    <Bot class="size-3" aria-hidden="true" />
                    {{ message.label }}
                </span>
                <div
                    class="max-w-[85%] rounded-bubble px-3 py-2 text-sm shadow-(--shadow-control) transition-opacity duration-(--duration-base)"
                    :class="[
                        message.from === 'bot'
                            ? 'rounded-br-sm bg-primary-soft text-primary-soft-foreground'
                            : 'rounded-bl-sm border bg-card',
                        message.muted && 'opacity-50',
                    ]"
                >
                    <WhatsAppText
                        v-if="message.text.trim() !== ''"
                        :text="message.text"
                    />
                    <span v-else class="text-muted-foreground italic"
                        >Sin texto</span
                    >
                </div>
            </li>
        </ol>

        <p
            class="flex items-center gap-1.5 border-t bg-card/60 px-4 py-2 text-xs text-muted-foreground"
        >
            <Eye class="size-3.5 shrink-0" aria-hidden="true" />
            Las variables se ven con datos de ejemplo; al enviar se usan los
            reales.
        </p>
    </figure>
</template>
