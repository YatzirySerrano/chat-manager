<script setup lang="ts">
import { Clock3, MessageSquareOff, TimerReset } from '@lucide/vue';
import { computed } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import { useLiveCustomerWindow } from '@/composables/useLiveCustomerWindow';
import { formatDateTime, formatDuration } from '@/lib/format';
import type { CustomerWindow } from '@/types';

/**
 * The customer service window (WhatsApp, Messenger, Instagram). Its length
 * is decided by the server (24 hours, or 7 days for human replies on
 * Messenger/Instagram with the HUMAN_AGENT tag), so the copy stays neutral.
 * Channels without one (web chat, status 'not_applicable') render nothing.
 * It counts down in the browser and turns "vencida" at expiry without a
 * reload (useLiveCustomerWindow()).
 */
const props = defineProps<{ window: CustomerWindow }>();
const current = useLiveCustomerWindow(() => props.window);

const tone = computed(
    () =>
        ({
            open: 'border-success/30 bg-success/5 text-success',
            expiring: 'border-warning/40 bg-warning/10 text-warning',
            expired: 'border-destructive/30 bg-destructive/5 text-destructive',
            none: 'border-border bg-muted/40 text-muted-foreground',
            not_applicable: 'border-border bg-muted/40 text-muted-foreground',
        })[current.value.status],
);

const icon = computed(() =>
    current.value.allows_free_form
        ? current.value.status === 'expiring'
            ? TimerReset
            : Clock3
        : MessageSquareOff,
);
</script>

<template>
    <div
        v-if="current.status !== 'not_applicable'"
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
                {{ current.label }}
                <FieldHelp
                    title="Ventana de atención"
                    :help="{
                        description:
                            'WhatsApp, Messenger e Instagram permiten responder con mensajes normales solo durante un tiempo limitado después del último mensaje del cliente (normalmente 24 horas; en Messenger e Instagram puede ampliarse a 7 días para respuestas de agentes cuando está habilitada la etiqueta de agente humano). El tiempo restante se muestra aquí.',
                        howTo: 'Se calcula únicamente con los mensajes del cliente. Responder, agregar notas, transferir o reabrir la conversación no la extienden.',
                        warning:
                            'Que la ventana esté abierta no significa que el mensaje sea gratuito: los precios dependen de las reglas vigentes de Meta.',
                    }"
                />
            </p>
            <p
                v-if="current.allows_free_form"
                class="text-xs text-foreground/80"
            >
                Quedan {{ formatDuration(current.remaining_seconds) }}; vence el
                {{ formatDateTime(current.expires_at) }}
            </p>
            <p v-else class="text-xs text-foreground/80">
                {{ current.block_reason }}
            </p>
        </div>
    </div>
</template>
