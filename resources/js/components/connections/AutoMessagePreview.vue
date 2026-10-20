<script setup lang="ts">
import { Bot, MessageCircleDashed } from '@lucide/vue';
import WhatsAppText from '@/components/WhatsAppText.vue';

/**
 * Live preview of the automatic messages of a connection, drawn with the
 * platform's own chat styling (not an imitation of the WhatsApp app). It
 * shows how the formatting will look; it never sends anything.
 */
defineProps<{
    businessName: string;
    messages: {
        key: string;
        label: string;
        trigger: string;
        text: string;
        enabled: boolean;
    }[];
}>();
</script>

<template>
    <div
        class="overflow-hidden rounded-2xl border bg-muted/40"
        aria-label="Vista previa de los mensajes automáticos"
    >
        <div class="flex items-center gap-2 border-b bg-card px-4 py-3">
            <span
                class="flex size-8 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
            >
                <Bot class="size-4" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">
                    {{ businessName || 'Tu conexión' }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Vista previa · no se envía nada
                </p>
            </div>
        </div>

        <div class="flex flex-col gap-4 p-4">
            <div class="flex justify-start">
                <p
                    class="max-w-[80%] rounded-2xl rounded-bl-md border bg-card px-3 py-2 text-sm text-muted-foreground"
                >
                    Hola, quisiera información.
                </p>
            </div>

            <div
                v-for="message in messages"
                :key="message.key"
                class="flex flex-col items-end gap-1"
            >
                <span
                    class="flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ message.label }}
                    <span
                        v-if="!message.enabled"
                        class="rounded-full bg-muted px-1.5 py-px text-[10px] normal-case"
                        >Desactivado</span
                    >
                </span>
                <div
                    v-if="message.text.trim() !== ''"
                    class="max-w-[85%] rounded-2xl rounded-br-md bg-primary px-3 py-2 text-sm text-primary-foreground shadow-sm transition-opacity"
                    :class="!message.enabled && 'opacity-50'"
                >
                    <WhatsAppText :text="message.text" />
                </div>
                <div
                    v-else
                    class="flex max-w-[85%] items-center gap-2 rounded-2xl rounded-br-md border border-dashed px-3 py-2 text-xs text-muted-foreground"
                >
                    <MessageCircleDashed class="size-3.5" aria-hidden="true" />
                    Sin texto
                </div>
                <span class="text-[11px] text-muted-foreground">{{
                    message.trigger
                }}</span>
            </div>
        </div>
    </div>
</template>
