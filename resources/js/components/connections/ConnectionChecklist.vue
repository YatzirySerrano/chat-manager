<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    Clock,
    History,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import ConnectionEvidenceLabel from '@/components/connections/ConnectionEvidenceLabel.vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import { splitTechnicalDetail } from '@/lib/metaErrors';
import { isWhatsAppWebProvider } from '@/lib/whatsappWeb';
import type { Connection } from '@/types';

/**
 * Consolidated monitoring of a connection: each piece of evidence behind
 * its health, shown separately and only the ones that apply to its channel
 * (a web chat has no credentials; only WhatsApp has a webhook of its own).
 *
 * A check is "ok" only with RECENT proof (Meta answered, or there was
 * traffic, within the freshness window the server reports). Older proof is
 * "history": another icon and other words, because having worked days ago
 * does not confirm the current state. A quiet period is normal and is never
 * shown as a problem.
 */
const props = defineProps<{
    checks: Connection['checks'];
    monitoring: Connection['monitoring'];
    channel: Connection['channel']['value'];
    /** Way of connecting; WhatsApp Web (QR) has no Meta identifiers to save. */
    provider?: string;
    compact?: boolean;
}>();

const isWhatsAppWeb = computed(() => isWhatsAppWebProvider(props.provider));

type CheckState = 'ok' | 'pending' | 'error' | 'muted' | 'history';

type Check = {
    key: string;
    label: string;
    state: CheckState;
    detail: string;
    /** Meta's technical tail of an older error text (code, English detail). */
    technical?: string | null;
    title?: string;
};

const stateIcon = {
    ok: CircleCheck,
    pending: Clock,
    error: CircleX,
    muted: CircleDashed,
    history: History,
} as const;

const stateClass: Record<CheckState, string> = {
    ok: 'text-success',
    pending: 'text-warning',
    error: 'text-destructive',
    muted: 'text-muted-foreground',
    history: 'text-muted-foreground',
};

const stateText: Record<CheckState, string> = {
    ok: 'Confirmado recientemente',
    pending: 'Pendiente',
    error: 'Con problema',
    muted: 'Sin confirmar todavía',
    history: 'Evidencia histórica, no reciente',
};

/** Traffic is "recent" within the same window the server uses for checks. */
function isRecent(iso: string | null): boolean {
    return (
        iso !== null &&
        Date.now() - new Date(iso).getTime() <=
            props.monitoring.fresh_minutes * 60_000
    );
}

const configurationDetail = computed(() => {
    const complete = props.monitoring.configuration.complete;

    if (isWhatsAppWeb.value) {
        return complete
            ? 'Hay un teléfono vinculado por código QR.'
            : 'Falta vincular el teléfono escaneando el código QR.';
    }

    switch (props.channel) {
        case 'whatsapp':
            return complete
                ? 'Phone number ID, WABA ID y token guardados.'
                : 'Faltan el Phone number ID, el WABA ID o el token de acceso.';
        case 'web_chat':
            return complete
                ? 'El widget tiene al menos un dominio autorizado.'
                : 'Autoriza al menos un dominio y guarda el widget.';
        default:
            return complete
                ? 'La cuenta está autorizada con Meta.'
                : 'La cuenta no tiene una autorización vigente de Meta.';
    }
});

const items = computed<Check[]>(() => {
    const checks = props.checks;
    const monitoring = props.monitoring;
    const list: Check[] = [
        {
            key: 'configuration',
            label: 'Estado de configuración',
            state: monitoring.configuration.complete ? 'ok' : 'pending',
            detail: configurationDetail.value,
        },
    ];

    const credentials = monitoring.credentials;

    if (credentials !== null) {
        const credentialsError = splitTechnicalDetail(
            credentials.error ?? 'Meta rechazó las credenciales.',
        );
        const attempt = credentials.last_attempt;
        // The last automatic attempt could not reach Meta: not a credential failure.
        const unreachable =
            attempt !== null &&
            (attempt.outcome === 'unreachable' ||
                attempt.outcome === 'rate_limited')
                ? ` El último intento automático (${formatRelative(attempt.at)}) no pudo comprobarse con Meta; no es un fallo de tus credenciales y se reintentará.`
                : '';
        const automatic = credentials.automatic
            ? ' Se comprueban solas cada cierto tiempo.'
            : '';

        list.push({
            key: 'credentials',
            label: 'Última verificación de credenciales',
            state:
                credentials.state === 'verified'
                    ? credentials.fresh
                        ? 'ok'
                        : 'history'
                    : credentials.state === 'invalid'
                      ? 'error'
                      : credentials.state === 'unverified'
                        ? 'pending'
                        : 'muted',
            detail:
                credentials.state === 'verified'
                    ? credentials.fresh
                        ? `Comprobadas ${formatRelative(credentials.checked_at)}: Meta las aceptó.${automatic}`
                        : `Sin comprobar desde ${formatRelative(credentials.checked_at)} (entonces Meta las aceptó).${unreachable || automatic}`
                    : credentials.state === 'invalid'
                      ? credentialsError.message
                      : credentials.state === 'unverified'
                        ? `Aún no se han verificado con Meta.${unreachable}`
                        : 'Guarda las credenciales para poder verificarlas.',
            technical:
                credentials.state === 'invalid'
                    ? credentialsError.technical
                    : null,
            title: credentials.checked_at
                ? formatDateTime(credentials.checked_at)
                : undefined,
        });
    }

    const webhook = monitoring.webhook;

    if (webhook !== null) {
        list.push({
            key: 'webhook',
            label: 'Aviso de mensajes nuevos (webhook)',
            state: webhook.verified_at
                ? webhook.fresh
                    ? 'ok'
                    : 'history'
                : webhook.last_received_at
                  ? 'history'
                  : 'muted',
            detail: webhook.verified_at
                ? `Meta confirmó la dirección ${formatRelative(webhook.verified_at)}.${
                      webhook.last_received_at
                          ? ` Último aviso recibido ${formatRelative(webhook.last_received_at)}.`
                          : ' Aún no llega ningún aviso.'
                  }`
                : webhook.last_received_at
                  ? `Último aviso recibido ${formatRelative(webhook.last_received_at)}.`
                  : 'Meta aún no confirma la dirección para avisarnos de mensajes. Pega la URL y el token de verificación en tu app de Meta (ver Diagnóstico).',
            title: webhook.last_received_at
                ? formatDateTime(webhook.last_received_at)
                : webhook.verified_at
                  ? formatDateTime(webhook.verified_at)
                  : undefined,
        });
    }

    const subscription = monitoring.subscription;

    if (subscription !== null) {
        list.push({
            key: 'subscription',
            label: 'Suscripción de la app en Meta',
            state:
                subscription.state === 'subscribed'
                    ? subscription.fresh
                        ? 'ok'
                        : 'history'
                    : subscription.state === 'not_subscribed'
                      ? 'error'
                      : 'muted',
            detail:
                subscription.state === 'subscribed'
                    ? subscription.fresh
                        ? `Comprobada ${formatRelative(subscription.checked_at)}: Meta nos envía los mensajes de esta cuenta.`
                        : `Sin comprobar desde ${formatRelative(subscription.checked_at)} (entonces estaba suscrita).`
                    : subscription.state === 'not_subscribed'
                      ? `Meta respondió ${formatRelative(subscription.checked_at)} que la app no está suscrita: los mensajes no llegarán.`
                      : 'Aún no se ha comprobado si Meta envía los mensajes de esta cuenta.',
            title: subscription.checked_at
                ? formatDateTime(subscription.checked_at)
                : undefined,
        });
    }

    list.push(
        {
            key: 'inbound',
            label: 'Último mensaje recibido',
            state: checks.last_inbound_at
                ? isRecent(checks.last_inbound_at)
                    ? 'ok'
                    : 'history'
                : 'muted',
            detail: checks.last_inbound_at
                ? `${capitalize(formatRelative(checks.last_inbound_at))}.${
                      isRecent(checks.last_inbound_at)
                          ? ''
                          : ' Es normal que haya periodos sin mensajes.'
                  }`
                : 'Aún no se ha recibido ningún mensaje.',
            title: checks.last_inbound_at
                ? formatDateTime(checks.last_inbound_at)
                : undefined,
        },
        {
            key: 'outbound',
            label: 'Último envío',
            state: checks.last_outbound_at
                ? isRecent(checks.last_outbound_at)
                    ? 'ok'
                    : 'history'
                : 'muted',
            detail: checks.last_outbound_at
                ? `${capitalize(formatRelative(checks.last_outbound_at))}: ${
                      props.channel === 'web_chat'
                          ? 'se entregó una respuesta al visitante.'
                          : isWhatsAppWeb.value
                            ? 'WhatsApp recibió un mensaje.'
                            : 'Meta aceptó un mensaje.'
                  }`
                : props.channel === 'web_chat'
                  ? 'Aún no se ha entregado ninguna respuesta.'
                  : isWhatsAppWeb.value
                    ? 'Aún no se ha enviado ningún mensaje por esta sesión.'
                    : 'Meta aún no ha aceptado ningún mensaje enviado.',
            title: checks.last_outbound_at
                ? formatDateTime(checks.last_outbound_at)
                : undefined,
        },
    );

    const lastError = monitoring.last_error;
    const lastErrorText = splitTechnicalDetail(
        lastError?.message ?? 'Sin detalle.',
    );

    list.push({
        key: 'error',
        label: 'Último error',
        state: lastError ? (lastError.current ? 'error' : 'history') : 'muted',
        detail: lastError
            ? lastError.current
                ? `${capitalize(formatRelative(lastError.at))} y nada ha funcionado después: ${lastErrorText.message}`
                : `${capitalize(formatRelative(lastError.at))} (ya superado: después hubo una comprobación o un mensaje correcto): ${lastErrorText.message}`
            : 'Sin errores registrados.',
        technical: lastError ? lastErrorText.technical : null,
        title: lastError ? formatDateTime(lastError.at) : undefined,
    });

    return list;
});

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}
</script>

<template>
    <div class="grid" :class="compact ? 'gap-1.5' : 'gap-3'">
        <ConnectionEvidenceLabel
            :monitoring="monitoring"
            :with-warnings="compact"
        />
        <ul
            :class="
                compact
                    ? 'grid gap-1.5'
                    : 'auto-grid [--col-min:17rem] [--grid-gap:1rem]'
            "
            aria-label="Evidencia del estado de la conexión"
        >
            <li
                v-for="item in items"
                :key="item.key"
                class="flex items-start gap-2"
                :title="item.title"
            >
                <component
                    :is="stateIcon[item.state]"
                    class="mt-0.5 shrink-0"
                    :class="[
                        stateClass[item.state],
                        compact ? 'size-3.5' : 'size-4',
                    ]"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p
                        class="font-medium"
                        :class="compact ? 'text-xs' : 'text-sm'"
                    >
                        {{ item.label }}
                        <span class="sr-only"
                            >: {{ stateText[item.state] }}</span
                        >
                    </p>
                    <p
                        v-if="!compact || item.state === 'error'"
                        class="text-xs break-words text-muted-foreground"
                        :class="compact && 'line-clamp-2'"
                    >
                        {{ item.detail }}
                    </p>
                    <details
                        v-if="!compact && item.technical"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        <summary class="cursor-pointer select-none">
                            Detalles técnicos
                        </summary>
                        <p class="mt-1 font-mono break-words">
                            {{ item.technical }}
                        </p>
                    </details>
                </div>
            </li>
        </ul>
        <div
            v-if="!compact && monitoring.warnings.length > 0"
            class="grid gap-2 rounded-lg bg-warning-soft p-3 text-xs"
            role="status"
            data-connection-warnings
        >
            <p class="flex items-center gap-1.5 font-medium">
                <TriangleAlert
                    class="size-4 shrink-0 text-warning"
                    aria-hidden="true"
                />
                Advertencias operativas
            </p>
            <ul class="grid gap-1.5">
                <li
                    v-for="warning in monitoring.warnings"
                    :key="`${warning.code}-${warning.message}`"
                    class="break-words"
                    :title="warning.at ? formatDateTime(warning.at) : undefined"
                >
                    {{ warning.message }}
                    <span v-if="warning.at" class="text-muted-foreground">
                        · {{ formatRelative(warning.at) }}
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
