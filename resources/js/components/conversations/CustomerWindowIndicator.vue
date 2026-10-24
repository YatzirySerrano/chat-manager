<script setup lang="ts">
import { Clock3, MessageSquareOff, TimerReset } from '@lucide/vue';
import { computed } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import { formatDateTime, formatDuration } from '@/lib/format';
import type { CustomerWindow } from '@/types';

/**
 * WhatsApp's 24 hour customer service window. Channels without one (web
 * chat, status 'not_applicable') render nothing.
 */
const props = defineProps<{ window: CustomerWindow }>();

const tone = computed(
    () =>
        ({
            open: 'border-success/30 bg-success/5 text-success',
            expiring: 'border-warning/40 bg-warning/10 text-warning',
            expired: 'border-destructive/30 bg-destructive/5 text-destructive',
            none: 'border-border bg-muted/40 text-muted-foreground',
            not_applicable: 'border-border bg-muted/40 text-muted-foreground',
        })[props.window.status],
);

const icon = computed(() =>
    props.window.allows_free_form
        ? props.window.status === 'expiring'
            ? TimerReset
            : Clock3
        : MessageSquareOff,
);
</script>

<template>
    <div
        v-if="window.status !== 'not_applicable'"
        class="flex items-start gap-3 rounded-lg border px-3 py-2.5 text-sm"
        :class="tone"
        role="status"
    >
        <component
            :is="icon"
            class="mt-0.5 size-4 shrink-0"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1 space-y-0.5">
            <p class="flex items-center gap-1.5 font-medium">
                {{ window.label }}
                <FieldHelp
                    title="Ventana de atención de WhatsApp"
                    :help="{
                        description:
                            'WhatsApp permite responder con mensajes normales solo durante las 24 horas siguientes al último mensaje del cliente.',
                        howTo: 'Se calcula únicamente con los mensajes del cliente. Responder, agregar notas, transferir o reabrir la conversación no la extienden.',
                        warning:
                            'Que la ventana esté abierta no significa que el mensaje sea gratuito: los precios dependen de las reglas vigentes de Meta.',
                    }"
                />
            </p>
            <p
                v-if="window.allows_free_form"
                class="text-xs text-foreground/80"
            >
                Quedan {{ formatDuration(window.remaining_seconds) }}; vence el
                {{ formatDateTime(window.expires_at) }}
            </p>
            <p v-else class="text-xs text-foreground/80">
                {{ window.block_reason }}
            </p>
        </div>
    </div>
</template>
