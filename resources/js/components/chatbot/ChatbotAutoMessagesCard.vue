<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check, Mail, Minus } from '@lucide/vue';
import { computed } from 'vue';
import { show as showConnection } from '@/routes/connections';
import type { ChatbotConnection } from '@/types';

/**
 * Shortcut to the messages that are NOT configured here: welcome, away and
 * farewell live in the connection form («Mensajes automáticos»). Says which
 * ones are on and links straight to that section.
 */
const props = defineProps<{ connection: ChatbotConnection }>();

const items = computed(() => [
    {
        key: 'welcome',
        label: 'Bienvenida',
        on: props.connection.automatic_messages.welcome,
    },
    {
        key: 'away',
        label: 'Fuera de horario',
        on: props.connection.automatic_messages.away,
    },
    {
        key: 'farewell',
        label: 'Despedida',
        on: props.connection.automatic_messages.farewell,
    },
]);

const href = computed(
    () => `${showConnection.url(props.connection.id)}#mensajes`,
);
</script>

<template>
    <section
        class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-xl border border-dashed bg-card px-4 py-3"
        aria-labelledby="chatbot-auto-messages-title"
        data-test="chatbot-auto-messages"
    >
        <div class="flex min-w-64 flex-1 items-start gap-3">
            <span
                class="grid size-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"
                aria-hidden="true"
            >
                <Mail class="size-4.5" />
            </span>
            <div class="grid gap-0.5">
                <h3
                    id="chatbot-auto-messages-title"
                    class="text-sm font-semibold"
                >
                    ¿Buscas la bienvenida, fuera de horario o despedida?
                </h3>
                <p class="text-xs text-muted-foreground">
                    Esos mensajes se configuran en la conexión, en «Mensajes
                    automáticos».
                </p>
            </div>
        </div>

        <ul class="flex flex-wrap gap-1.5" aria-label="Mensajes automáticos">
            <li
                v-for="item in items"
                :key="item.key"
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                    item.on
                        ? 'bg-success-soft text-success-soft-foreground'
                        : 'bg-muted text-muted-foreground'
                "
            >
                <component
                    :is="item.on ? Check : Minus"
                    class="size-3"
                    aria-hidden="true"
                />
                {{ item.label }}
                <span class="sr-only">{{
                    item.on ? '(encendido)' : '(apagado)'
                }}</span>
            </li>
        </ul>

        <Link
            v-if="connection.can.view"
            :href="href"
            class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
        >
            Configurarlos en la conexión
            <ArrowRight class="size-4" aria-hidden="true" />
        </Link>
        <p v-else class="text-xs text-muted-foreground sm:max-w-48">
            Pide a quien administra las conexiones que los revise.
        </p>
    </section>
</template>
