<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';

/**
 * What a person must know before connecting a number through WhatsApp Web
 * (QR): it is not an official integration. Shown where the way of connecting
 * is chosen and on the connection itself, always in the same words.
 */
defineProps<{
    /** One short paragraph (the choice card) instead of the full list. */
    compact?: boolean;
}>();
</script>

<template>
    <!-- A span: the compact notice also sits inside links and labels. -->
    <span
        v-if="compact"
        class="flex gap-1.5 text-xs text-warning-soft-foreground"
        role="note"
    >
        <TriangleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
        <span>
            No es una integración oficial: WhatsApp puede desconectar o
            suspender el número. Solo para un número de prueba autorizado; sin
            respuestas automáticas, campañas ni envíos masivos.
        </span>
    </span>
    <div
        v-else
        class="flex gap-3 rounded-xl bg-warning-soft px-4 py-3.5 text-sm text-warning-soft-foreground"
        role="note"
    >
        <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <div class="min-w-0 space-y-1.5">
            <p class="font-semibold">
                WhatsApp Web (QR) no es una integración oficial
            </p>
            <ul class="max-w-prose list-disc space-y-1 pl-4 text-pretty">
                <li>
                    Usa el mismo mecanismo que «Dispositivos vinculados» de
                    WhatsApp, sin contrato con Meta. WhatsApp puede cerrar la
                    sesión o suspender el número sin aviso.
                </li>
                <li>
                    Úsala solo con un número de prueba autorizado, para
                    conversaciones con personas que ya esperan tus mensajes.
                </li>
                <li>
                    Solo envía lo que escribe una persona autorizada: sin
                    respuestas automáticas, menús, campañas ni envíos masivos.
                </li>
                <li>
                    Un cambio de WhatsApp puede dejarla sin funcionar hasta
                    actualizar el servicio. Para operación formal usa WhatsApp
                    Cloud API.
                </li>
            </ul>
        </div>
    </div>
</template>
