<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Clock, RefreshCw } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/format';
import { webhook as reconnectWebhook } from '@/routes/connections/telegram';
import type { TelegramState } from '@/types';

/**
 * «Estado» of a Telegram bot connection: the bot (@username) and what
 * Telegram says about its webhook (getWebhookInfo: registered here, updates
 * waiting, last delivery error), with «Volver a conectar webhook».
 */
const props = defineProps<{
    connectionId: number;
    telegram: TelegramState;
    canManage: boolean;
    deleted: boolean;
}>();

const reconnecting = ref(false);

const webhook = computed(() => props.telegram.webhook);

const state = computed<{
    tone: 'ok' | 'warning' | 'error';
    label: string;
}>(() => {
    const current = webhook.value;

    if (current === null) {
        return { tone: 'warning', label: 'Falta el token del bot.' };
    }

    if (!current.checked) {
        return {
            tone: 'warning',
            label: current.error ?? 'No se pudo consultar a Telegram.',
        };
    }

    if (!current.registered || !current.url_matches) {
        return {
            tone: 'error',
            label: current.registered
                ? 'El webhook apunta a otro servidor.'
                : 'Webhook sin conectar.',
        };
    }

    return current.last_error_message
        ? { tone: 'warning', label: 'Conectado, con errores recientes.' }
        : { tone: 'ok', label: 'Webhook conectado.' };
});

function reconnect(): void {
    router.post(
        reconnectWebhook(props.connectionId).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (reconnecting.value = true),
            onFinish: () => (reconnecting.value = false),
        },
    );
}
</script>

<template>
    <div class="grid gap-3" data-test="telegram-webhook">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <p
                class="flex min-w-0 items-center gap-2 text-sm font-medium"
                :class="{
                    'text-success': state.tone === 'ok',
                    'text-warning-soft-foreground': state.tone === 'warning',
                    'text-destructive': state.tone === 'error',
                }"
            >
                <CircleCheck
                    v-if="state.tone === 'ok'"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                <CircleAlert
                    v-else
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ state.label }}
            </p>
            <p
                v-if="telegram.bot.username"
                class="text-sm text-muted-foreground"
            >
                Bot
                <a
                    :href="`https://t.me/${telegram.bot.username}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="font-medium text-foreground underline-offset-2 hover:underline"
                    >@{{ telegram.bot.username }}</a
                >
            </p>
            <Button
                v-if="canManage && !deleted && webhook !== null"
                type="button"
                variant="outline"
                size="sm"
                class="sm:ml-auto"
                :loading="reconnecting"
                data-test="telegram-reconnect"
                @click="reconnect"
            >
                <RefreshCw />
                Volver a conectar webhook
            </Button>
        </div>

        <ul
            v-if="
                webhook?.checked &&
                (webhook.pending_update_count > 0 || webhook.last_error_message)
            "
            class="grid gap-1 text-xs text-muted-foreground"
        >
            <li
                v-if="webhook.pending_update_count > 0"
                class="flex items-center gap-1.5"
            >
                <Clock class="size-3.5 shrink-0" aria-hidden="true" />
                {{ webhook.pending_update_count }}
                {{
                    webhook.pending_update_count === 1
                        ? 'mensaje en espera'
                        : 'mensajes en espera'
                }}
                en Telegram.
            </li>
            <li
                v-if="webhook.last_error_message"
                class="flex items-start gap-1.5"
            >
                <CircleAlert
                    class="mt-px size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span class="min-w-0">
                    Último error{{
                        webhook.last_error_at
                            ? ` (${formatRelative(webhook.last_error_at)})`
                            : ''
                    }}: {{ webhook.last_error_message }}
                </span>
            </li>
        </ul>
    </div>
</template>
