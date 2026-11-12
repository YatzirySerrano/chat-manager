<script setup lang="ts">
import {
    Activity,
    CircleDashed,
    CircleX,
    History,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { ConnectionMonitoring } from '@/types';

/**
 * One line that says how current the evidence about a connection is. Recent
 * evidence (Meta answered a check, or there was traffic, a short while ago)
 * and history (it worked at some point) never look or read the same: the
 * icon and the words change, not only the color. Old activity is never
 * presented as "connected now".
 */
const props = defineProps<{
    monitoring: ConnectionMonitoring;
    /** Also tell how many operational warnings the connection has. */
    withWarnings?: boolean;
}>();

const headline = computed(() => {
    const { evidence, credentials } = props.monitoring;
    const when = formatRelative(evidence.at);

    switch (evidence.level) {
        case 'problem':
            return {
                icon: CircleX,
                tone: 'text-destructive',
                text: 'Con un problema sin resolver',
                hint: 'Lo último que se sabe de esta conexión es un error o un rechazo de Meta.',
            };
        case 'recent':
            return evidence.source === 'check'
                ? {
                      icon: ShieldCheck,
                      tone: 'text-success',
                      text: `Comprobada ${when}`,
                      hint: 'Evidencia reciente: Meta aceptó las credenciales en la última comprobación.',
                  }
                : {
                      icon: Activity,
                      tone: 'text-success',
                      text: `Con actividad ${when}`,
                      hint: 'Evidencia reciente: se recibió o envió un mensaje hace poco.',
                  };
        case 'historical':
            return {
                icon: History,
                tone: 'text-muted-foreground',
                text:
                    credentials === null
                        ? `Última actividad ${when}`
                        : `Sin comprobar desde ${when}`,
                hint: 'Actividad histórica: funcionó entonces, pero no hay una comprobación reciente que confirme el estado actual. Un periodo sin mensajes es normal.',
            };
        default:
            return {
                icon: CircleDashed,
                tone: 'text-muted-foreground',
                text: 'Sin evidencia todavía',
                hint: 'Aún no hay ninguna comprobación ni mensaje registrado.',
            };
    }
});

const warnings = computed(() => props.monitoring.warnings.length);
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
        <span
            class="inline-flex items-center gap-1"
            :class="headline.tone"
            :data-connection-evidence="monitoring.evidence.level"
            :title="
                monitoring.evidence.at
                    ? `${headline.hint} (${formatDateTime(monitoring.evidence.at)})`
                    : headline.hint
            "
        >
            <component
                :is="headline.icon"
                class="size-3.5 shrink-0"
                aria-hidden="true"
            />
            <span>{{ headline.text }}</span>
            <span class="sr-only">. {{ headline.hint }}</span>
        </span>
        <span
            v-if="withWarnings && warnings > 0"
            class="inline-flex items-center gap-1 text-warning-soft-foreground"
            data-connection-warning-count
            :title="
                monitoring.warnings.map((warning) => warning.message).join(' ')
            "
        >
            <TriangleAlert class="size-3.5 shrink-0" aria-hidden="true" />
            {{ warnings === 1 ? '1 advertencia' : `${warnings} advertencias` }}
        </span>
    </span>
</template>
