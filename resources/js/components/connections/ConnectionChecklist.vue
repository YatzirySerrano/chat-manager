<script setup lang="ts">
import { CircleCheck, CircleDashed, CircleX, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import { splitTechnicalDetail } from '@/lib/metaErrors';
import type { Connection } from '@/types';

/**
 * Each piece of evidence behind the health of a connection, shown
 * separately. A check is only "ok" when there is recorded proof from Meta
 * (webhook verification, received messages, messages accepted).
 */
const props = defineProps<{
    checks: Connection['checks'];
    compact?: boolean;
}>();

type CheckState = 'ok' | 'pending' | 'error' | 'muted';

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
} as const;

const stateClass: Record<CheckState, string> = {
    ok: 'text-success',
    pending: 'text-amber-600 dark:text-amber-400',
    error: 'text-destructive',
    muted: 'text-muted-foreground',
};

const stateText: Record<CheckState, string> = {
    ok: 'Confirmado',
    pending: 'Pendiente',
    error: 'Con problema',
    muted: 'Sin confirmar todavía',
};

const items = computed<Check[]>(() => {
    const checks = props.checks;
    const credentials = checks.credentials;
    const credentialsError = splitTechnicalDetail(
        credentials.error ?? 'Meta rechazó las credenciales.',
    );
    const lastError = splitTechnicalDetail(
        checks.last_error?.message ?? 'Sin detalle.',
    );

    return [
        {
            key: 'configuration',
            label: 'Configuración guardada',
            state: checks.configuration_saved ? 'ok' : 'pending',
            detail: checks.configuration_saved
                ? 'Phone number ID, WABA ID y token guardados.'
                : 'Faltan el Phone number ID, el WABA ID o el token de acceso.',
        },
        {
            key: 'credentials',
            label: 'Credenciales verificadas',
            state:
                credentials.state === 'verified'
                    ? 'ok'
                    : credentials.state === 'invalid'
                      ? 'error'
                      : credentials.state === 'unverified'
                        ? 'pending'
                        : 'muted',
            detail:
                credentials.state === 'verified'
                    ? `Meta las aceptó ${formatRelative(credentials.verified_at)}.`
                    : credentials.state === 'invalid'
                      ? credentialsError.message
                      : credentials.state === 'unverified'
                        ? 'Aún no se han verificado con Meta.'
                        : 'Guarda las credenciales para poder verificarlas.',
            technical:
                credentials.state === 'invalid'
                    ? credentialsError.technical
                    : null,
            title: credentials.verified_at
                ? formatDateTime(credentials.verified_at)
                : undefined,
        },
        {
            key: 'webhook',
            label: 'Aviso de mensajes nuevos (webhook)',
            state: checks.webhook_verified_at ? 'ok' : 'muted',
            detail: checks.webhook_verified_at
                ? `Confirmado ${formatRelative(checks.webhook_verified_at)}.`
                : 'Meta aún no confirma la dirección para avisarnos de mensajes. Pega la URL y el token de verificación en tu app de Meta (ver Diagnóstico).',
            title: checks.webhook_verified_at
                ? formatDateTime(checks.webhook_verified_at)
                : undefined,
        },
        {
            key: 'inbound',
            label: 'Recepción confirmada',
            state: checks.last_inbound_at ? 'ok' : 'muted',
            detail: checks.last_inbound_at
                ? `Último mensaje recibido ${formatRelative(checks.last_inbound_at)}.`
                : 'Aún no se ha recibido ningún mensaje.',
            title: checks.last_inbound_at
                ? formatDateTime(checks.last_inbound_at)
                : undefined,
        },
        {
            key: 'outbound',
            label: 'Envío confirmado',
            state: checks.last_outbound_at ? 'ok' : 'muted',
            detail: checks.last_outbound_at
                ? `Último mensaje aceptado por Meta ${formatRelative(checks.last_outbound_at)}.`
                : 'Meta aún no ha aceptado ningún mensaje enviado.',
            title: checks.last_outbound_at
                ? formatDateTime(checks.last_outbound_at)
                : undefined,
        },
        {
            key: 'error',
            label: 'Último error',
            state: checks.last_error ? 'error' : 'muted',
            detail: checks.last_error
                ? `${formatRelative(checks.last_error.at)}: ${lastError.message}`
                : 'Sin errores registrados.',
            technical: checks.last_error ? lastError.technical : null,
            title: checks.last_error
                ? formatDateTime(checks.last_error.at)
                : undefined,
        },
    ];
});
</script>

<template>
    <ul
        class="grid"
        :class="compact ? 'gap-1.5' : 'gap-3'"
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
                <p class="font-medium" :class="compact ? 'text-xs' : 'text-sm'">
                    {{ item.label }}
                    <span class="sr-only">: {{ stateText[item.state] }}</span>
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
</template>
