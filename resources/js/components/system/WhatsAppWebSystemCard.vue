<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import WhatsAppWebStateBadge from '@/components/connections/WhatsAppWebStateBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import HealthStatusBadge from '@/components/system/HealthStatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatDuration, formatRelative } from '@/lib/format';
import { show as showConnection } from '@/routes/connections';
import type { WhatsAppWebSystem } from '@/types';

/**
 * WhatsApp Web (QR) in Administración > Sistema: whether the Node service
 * answers and the technical state of each session. A running service is
 * not a working session: a number can send only when its own row says so.
 * Nothing here shows conversation content.
 */
const props = defineProps<{
    whatsappWeb: WhatsAppWebSystem;
}>();

const service = computed(() => props.whatsappWeb.service);
const available = computed(() => service.value?.available === true);

const serviceFacts = computed(() => {
    const current = service.value;

    if (!current || !current.available) {
        return [];
    }

    return [
        current.library,
        current.version ? `servicio ${current.version}` : null,
        current.uptime_seconds !== null
            ? `en marcha desde hace ${formatDuration(current.uptime_seconds)}`
            : null,
    ].filter((fact): fact is string => Boolean(fact));
});

const sendingCount = computed(
    () =>
        props.whatsappWeb.sessions.filter((session) => session.can_send).length,
);
</script>

<template>
    <SurfaceCard
        title="WhatsApp Web (QR)"
        description="El servicio que mantiene las sesiones vinculadas por código QR y el estado real de cada una. Que el servicio esté en marcha no significa que una sesión pueda enviar."
        data-test="system-whatsapp-web"
    >
        <template #actions>
            <HealthStatusBadge
                :status="available ? 'ok' : 'fail'"
                :label="
                    available ? 'Servicio en marcha' : 'Servicio sin respuesta'
                "
                pulse
            />
        </template>

        <div
            class="flex flex-wrap items-center gap-x-6 gap-y-1 border-b px-5 py-3 text-xs text-muted-foreground"
        >
            <template v-if="available">
                <span v-for="fact in serviceFacts" :key="fact">{{ fact }}</span>
                <span class="font-medium text-foreground tabular-nums">
                    {{ sendingCount }} de {{ whatsappWeb.sessions.length }}
                    {{
                        whatsappWeb.sessions.length === 1
                            ? 'sesión puede'
                            : 'sesiones pueden'
                    }}
                    enviar ahora
                </span>
            </template>
            <span v-else class="text-destructive-soft-foreground">
                El servicio de Node no respondió a la plataforma: ninguna sesión
                puede enviar ni recibir. Revisa el proceso en el servidor; los
                estados de abajo son los últimos conocidos.
            </span>
        </div>

        <div v-if="whatsappWeb.sessions.length === 0" class="p-5">
            <EmptyState
                title="Sin conexiones por QR"
                description="Aún no hay números conectados mediante WhatsApp Web (QR)."
            />
        </div>
        <Table v-else>
            <TableHeader>
                <TableRow>
                    <TableHead>Conexión</TableHead>
                    <TableHead>Sesión</TableHead>
                    <TableHead>Conectada</TableHead>
                    <TableHead>Última actividad</TableHead>
                    <TableHead>Último entrante</TableHead>
                    <TableHead>Último saliente</TableHead>
                    <TableHead class="text-right">Reconexiones</TableHead>
                    <TableHead>Último error</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="session in whatsappWeb.sessions"
                    :key="session.connection_id"
                >
                    <TableCell>
                        <Link
                            :href="showConnection(session.connection_id)"
                            class="font-medium hover:underline"
                        >
                            {{ session.name }}
                        </Link>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{ session.phone ?? 'Sin teléfono vinculado' }}
                        </p>
                    </TableCell>
                    <TableCell>
                        <WhatsAppWebStateBadge
                            :state="session.state"
                            :label="session.state_label"
                            :tone="session.tone"
                        />
                        <p
                            class="mt-1 text-xs"
                            :class="
                                session.can_send
                                    ? 'text-success'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                session.can_send
                                    ? 'Puede enviar'
                                    : 'No puede enviar'
                            }}
                        </p>
                    </TableCell>
                    <TableCell
                        class="text-sm whitespace-nowrap"
                        :title="
                            session.connected_since
                                ? formatDateTime(session.connected_since)
                                : undefined
                        "
                    >
                        {{
                            session.connected_since
                                ? `Desde ${formatRelative(session.connected_since)}`
                                : 'No'
                        }}
                    </TableCell>
                    <TableCell
                        v-for="(at, column) in [
                            session.last_event_at,
                            session.last_inbound_at,
                            session.last_outbound_at,
                        ]"
                        :key="column"
                        class="text-sm whitespace-nowrap"
                        :title="at ? formatDateTime(at) : undefined"
                    >
                        {{ formatRelative(at) }}
                    </TableCell>
                    <TableCell class="text-right text-sm tabular-nums">
                        {{ session.reconnects }}
                    </TableCell>
                    <TableCell
                        class="max-w-xs text-xs whitespace-normal text-muted-foreground"
                    >
                        {{ session.last_error ?? 'Sin errores registrados' }}
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
    </SurfaceCard>
</template>
