<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    Copy,
    FlaskConical,
    RefreshCw,
    TriangleAlert,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import {
    check as checkSubscription,
    store as subscribeApp,
} from '@/routes/connections/subscription';
import { show as showConversation } from '@/routes/conversations';
import {
    index as templatesIndex,
    sync as syncTemplates,
} from '@/routes/templates';
import type { Connection, ConnectionDiagnostics } from '@/types';

/**
 * Real activation checklist of a WhatsApp Cloud API connection: the webhook
 * URL to register in Meta, the evidence received (verification, deliveries,
 * errors), the app subscription to the business account and templates.
 * Secrets are never shown: only whether each one is saved.
 */
const props = defineProps<{
    connection: Connection;
    diagnostics: ConnectionDiagnostics;
    can: { verify: boolean; syncTemplates: boolean; viewTemplates: boolean };
}>();

const { copy, isSupported } = useClipboard({ legacy: true });
const busy = ref<'check' | 'subscribe' | 'sync' | null>(null);

async function copyUrl(): Promise<void> {
    if (!props.diagnostics.webhook.url) {
        return;
    }

    await copy(props.diagnostics.webhook.url);
    toast.success('URL del webhook copiada.');
}

function post(action: 'check' | 'subscribe' | 'sync'): void {
    const url =
        action === 'check'
            ? checkSubscription.url(props.connection.id)
            : action === 'subscribe'
              ? subscribeApp.url(props.connection.id)
              : syncTemplates.url(props.connection.id);

    router.post(url, undefined, {
        preserveScroll: true,
        onStart: () => (busy.value = action),
        onError: (errors) => {
            toast.error(
                Object.values(errors)[0] ??
                    'No fue posible completar la acción.',
            );
        },
        onFinish: () => (busy.value = null),
    });
}

type State = 'ok' | 'error' | 'pending';

const icon = {
    ok: CircleCheck,
    error: CircleX,
    pending: CircleDashed,
} as const;
const iconClass: Record<State, string> = {
    ok: 'text-success',
    error: 'text-destructive',
    pending: 'text-muted-foreground',
};

const checks = computed<
    { key: string; label: string; state: State; detail: string }[]
>(() => {
    const webhook = props.diagnostics.webhook;
    const subscription = props.diagnostics.subscription;

    return [
        {
            key: 'secrets',
            label: 'Secreto de la app y token de verificación',
            state:
                webhook.app_secret_saved && webhook.verify_token_saved
                    ? 'ok'
                    : 'error',
            detail:
                webhook.app_secret_saved && webhook.verify_token_saved
                    ? 'Guardados (cifrados). Sirven para confirmar que los avisos vienen de Meta y para que Meta pueda conectarse.'
                    : 'Faltan datos: sin el secreto de la app no podemos confirmar que los mensajes vienen de Meta, y sin el token de verificación Meta no puede conectarse.',
        },
        {
            key: 'verified',
            label: 'Aviso de mensajes nuevos (webhook) confirmado por Meta',
            state: webhook.verified_at ? 'ok' : 'pending',
            detail: webhook.verified_at
                ? `Meta verificó la URL ${formatRelative(webhook.verified_at)}.`
                : 'Pega la URL de abajo y el token de verificación en la configuración de WhatsApp de tu app en Meta.',
        },
        {
            key: 'subscription',
            label: 'Recepción de mensajes activada en la cuenta de WhatsApp Business',
            state:
                subscription.state === 'subscribed'
                    ? 'ok'
                    : subscription.state === 'not_subscribed'
                      ? 'error'
                      : 'pending',
            detail:
                subscription.state === 'unknown'
                    ? 'Aún no se ha consultado en Meta.'
                    : `${subscription.state === 'subscribed' ? 'Activada' : 'No activada'} según la consulta ${formatRelative(subscription.checked_at)}.${subscription.meta_app_id_saved ? '' : ' Guarda el Meta App ID para comprobar que sea tu app.'}`,
        },
        {
            key: 'deliveries',
            label: 'Avisos recibidos de Meta',
            state: webhook.last_received_at ? 'ok' : 'pending',
            detail: webhook.last_received_at
                ? `Último aviso confirmado ${formatRelative(webhook.last_received_at)}. Últimas 24 h: ${webhook.events_24h.processed} procesados, ${webhook.events_24h.pending} en espera, ${webhook.events_24h.failed} con error.`
                : 'Todavía no llega ningún aviso de Meta (mensajes nuevos o estados de envío).',
        },
    ];
});

const SCHEDULE_LABELS = {
    department: 'Horario propio del departamento',
    platform: 'Horario de atención de Configuración',
    continuous: 'Atención continua (sin horario aplicado)',
} as const;

/**
 * What the automatic messages do: the schedule that applies now, which
 * greeting a customer starting a conversation right now would get, and the
 * warnings that explain one that "did not arrive".
 */
const automation = computed(() => {
    const state = props.diagnostics.automation;
    const messages = props.connection.messages;
    const warnings: string[] = [];

    if (messages.away.enabled && state.schedule === 'continuous') {
        warnings.push(
            'El mensaje fuera de horario está activado, pero no hay un horario aplicado: la atención se considera continua y nunca se envía. Activa «Aplicar horario de atención» en Configuración.',
        );
    }

    if (state.auto_close_after_hours === null) {
        warnings.push(
            'No hay cierre automático por inactividad: una conversación sigue abierta hasta que un asesor la cierra, y mientras tanto el cliente no recibe otro saludo.',
        );
    }

    return {
        schedule: SCHEDULE_LABELS[state.schedule],
        now:
            state.schedule === 'continuous'
                ? `Ahora son las ${state.local_time} (${state.timezone}).`
                : `Ahora son las ${state.local_time} (${state.timezone}): ${state.is_open ? 'dentro del horario' : `fuera del horario${state.holiday ? ` (${state.holiday})` : ''}`}.${!state.is_open && state.next_opening ? ` Se abre ${formatDateTime(state.next_opening)}.` : ''}`,
        greeting: state.greeting_now
            ? `Un cliente que inicie una conversación ahora recibirá «${state.greeting_now.label}»${state.greeting_now.source === 'department' ? ` del departamento ${state.department ?? ''}` : ''}.`
            : `Un cliente que inicie una conversación ahora no recibirá ningún saludo: ${state.is_open ? 'la bienvenida no está activada' : 'el mensaje fuera de horario no está activado'}${state.department ? ' ni en la conexión ni en su departamento' : ''}.`,
        recent: `Últimos ${state.days} días: ${state.sent.welcome} de bienvenida, ${state.sent.away} fuera de horario y ${state.sent.farewell} de despedida${state.failed > 0 ? `; ${state.failed} no se pudieron enviar` : ''}.`,
        warnings,
    };
});
</script>

<template>
    <SurfaceCard
        title="Integración con WhatsApp Cloud API"
        description="Pasos para activar la conexión, confirmados con Meta."
        body-class="grid grid-cols-1 gap-4 p-5 text-sm"
    >
        <div
            v-if="diagnostics.transport.mode !== 'meta'"
            class="flex gap-2 rounded-lg bg-warning-soft p-3 text-xs"
        >
            <FlaskConical
                v-if="diagnostics.transport.mode === 'simulated'"
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <TriangleAlert
                v-else
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <p>
                <strong>{{
                    diagnostics.transport.mode === 'simulated'
                        ? 'Modo de prueba'
                        : 'Envío apagado'
                }}</strong>
                · {{ diagnostics.transport.label }}
            </p>
        </div>

        <p
            v-if="diagnostics.transport.pilot_recipients > 0"
            class="flex gap-2 rounded-lg bg-warning-soft p-3 text-xs"
        >
            <FlaskConical
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <span>
                <strong>Piloto controlado</strong> · solo se envían mensajes a
                {{ diagnostics.transport.pilot_recipients }}
                destinatario(s) autorizados (WHATSAPP_PILOT_RECIPIENTS); los
                demás fallan sin llegar a Meta.
            </span>
        </p>

        <div class="grid min-w-0 grid-cols-1 gap-1.5">
            <p class="text-xs font-medium">
                URL de devolución de llamada (webhook)
            </p>
            <div class="flex gap-2">
                <code
                    class="min-w-0 flex-1 truncate rounded-md border bg-muted/50 px-2 py-1.5 text-xs"
                    :title="diagnostics.webhook.url ?? ''"
                    >{{ diagnostics.webhook.url ?? 'No disponible' }}</code
                >
                <Button
                    v-if="isSupported && diagnostics.webhook.url"
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Copiar URL del webhook"
                    @click="copyUrl"
                >
                    <Copy />
                </Button>
            </div>
            <p
                v-if="!diagnostics.webhook.https"
                class="text-xs text-destructive"
            >
                Meta solo acepta URLs https públicas: configura APP_URL con el
                dominio https de producción.
            </p>
            <p class="text-xs text-muted-foreground">
                Token de verificación: el valor que guardaste en esta conexión
                (no se muestra). Campos a suscribir:
                {{ diagnostics.webhook.fields.join(', ') }}.
            </p>
        </div>

        <ul class="grid gap-3">
            <li v-for="item in checks" :key="item.key" class="flex gap-2">
                <component
                    :is="icon[item.state]"
                    class="mt-0.5 size-4 shrink-0"
                    :class="iconClass[item.state]"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p class="text-xs font-medium">{{ item.label }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ item.detail }}
                    </p>
                </div>
            </li>
        </ul>

        <div
            v-if="diagnostics.webhook.last_failure"
            class="rounded-lg bg-destructive-soft p-3 text-xs"
        >
            <p class="font-medium text-destructive">
                Último evento con error ·
                {{ formatDateTime(diagnostics.webhook.last_failure.at) }}
            </p>
            <p class="text-muted-foreground">
                {{ diagnostics.webhook.last_failure.error }}
            </p>
        </div>

        <p
            v-if="diagnostics.outbound.unconfirmed > 0"
            class="rounded-lg bg-warning-soft p-3 text-xs"
        >
            {{ diagnostics.outbound.unconfirmed }} mensaje(s) enviados sin
            confirmación de Meta. Si no llega la confirmación se marcarán como
            «entrega no confirmada».
        </p>

        <div
            v-if="diagnostics.outbound.unknown > 0"
            class="grid gap-1.5 rounded-lg bg-warning-soft p-3 text-xs"
            role="status"
        >
            <p>
                <strong
                    >{{ diagnostics.outbound.unknown }} mensaje(s) con entrega
                    no confirmada</strong
                >
                en los últimos {{ diagnostics.outbound.unknown_days }} días:
                WhatsApp pudo haberlos recibido o no. El agente responsable debe
                verificarlo con el cliente antes de reintentarlos.
            </p>
            <ul
                v-if="diagnostics.outbound.unknown_conversations.length > 0"
                class="grid gap-1"
            >
                <li
                    v-for="item in diagnostics.outbound.unknown_conversations"
                    :key="item.id"
                >
                    <Link
                        :href="showConversation(item.id)"
                        class="font-medium text-primary hover:underline"
                        >Conversación #{{ item.id
                        }}{{ item.contact ? ` · ${item.contact}` : '' }}</Link
                    >
                    <span class="text-muted-foreground">
                        · {{ item.messages }} mensaje(s)
                        <template v-if="item.last_failed_at">
                            · {{ formatRelative(item.last_failed_at) }}
                        </template>
                    </span>
                </li>
            </ul>
        </div>

        <div class="grid gap-1.5 text-xs" data-diagnostics="automation">
            <p class="font-medium">Mensajes automáticos</p>
            <p class="text-muted-foreground">
                {{ automation.schedule }}. {{ automation.now }}
            </p>
            <p>{{ automation.greeting }}</p>
            <p class="text-muted-foreground">
                El saludo (bienvenida o fuera de horario) se envía una vez por
                conversación: cuando el cliente inicia una nueva o reabre una
                cerrada, nunca en una que sigue abierta. La despedida, al
                cerrarla, solo dentro de la ventana de 24 horas.
            </p>
            <p class="text-muted-foreground">{{ automation.recent }}</p>
            <p
                v-if="diagnostics.automation.last_failure"
                class="text-destructive"
            >
                Último envío automático fallido
                {{ formatRelative(diagnostics.automation.last_failure.at) }}:
                {{
                    diagnostics.automation.last_failure.error ??
                    diagnostics.automation.last_failure.code
                }}
            </p>
            <p
                v-for="warning in automation.warnings"
                :key="warning"
                class="flex gap-2 rounded-lg bg-warning-soft p-3"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0 text-warning"
                    aria-hidden="true"
                />
                <span>{{ warning }}</span>
            </p>
        </div>

        <div v-if="diagnostics.signals.length > 0" class="grid gap-1 text-xs">
            <p class="font-medium">Avisos recientes de Meta</p>
            <p
                v-for="signal in diagnostics.signals"
                :key="signal.field"
                class="text-muted-foreground"
            >
                {{ signal.field
                }}{{ signal.event ? `: ${signal.event}` : '' }} ·
                {{ formatRelative(signal.at) }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2 border-t pt-4">
            <Button
                v-if="can.verify"
                variant="outline"
                size="sm"
                :disabled="busy !== null"
                @click="post('check')"
            >
                <Spinner v-if="busy === 'check'" />
                <RefreshCw v-else />
                Revisar suscripción
            </Button>
            <Button
                v-if="
                    can.verify &&
                    diagnostics.subscription.state !== 'subscribed'
                "
                size="sm"
                :disabled="busy !== null"
                @click="post('subscribe')"
            >
                <Spinner v-if="busy === 'subscribe'" />
                Suscribir app
            </Button>
            <Button
                v-if="can.syncTemplates"
                variant="outline"
                size="sm"
                :disabled="busy !== null"
                @click="post('sync')"
            >
                <Spinner v-if="busy === 'sync'" />
                <RefreshCw v-else />
                Actualizar plantillas desde Meta
            </Button>
        </div>
        <p class="text-xs text-muted-foreground">
            Plantillas aprobadas: {{ diagnostics.templates.approved }}
            <template v-if="diagnostics.templates.last_synced_at">
                · actualizadas desde Meta
                {{
                    formatRelative(diagnostics.templates.last_synced_at)
                }}</template
            >.
            <Link
                v-if="can.viewTemplates"
                :href="templatesIndex({ query: { connection: connection.id } })"
                class="font-medium text-primary hover:underline"
                >Ver plantillas</Link
            >
        </p>
    </SurfaceCard>
</template>
