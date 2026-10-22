<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarOff, Clock, Gauge, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { endSentence, formatDateTime } from '@/lib/format';
import { formatHoliday } from '@/lib/holidays';
import type { DepartmentAutomation, DepartmentConnection } from '@/types';

const props = defineProps<{
    automation: DepartmentAutomation;
    connections: DepartmentConnection[];
}>();

const modeLabel = computed(
    () =>
        ({
            department: 'Horario propio del departamento',
            platform: 'Horario de la plataforma',
            continuous: 'Atención continua (sin horario aplicado)',
        })[props.automation.hours.mode],
);

const activeConnections = computed(() =>
    props.connections.filter(
        (connection) => connection.is_active && !connection.is_deleted,
    ),
);

function reopenLabel(minutes: number | null): string {
    if (minutes === null) {
        return 'Siempre inicia una conversación nueva';
    }

    return minutes >= 60 && minutes % 60 === 0
        ? `Reabre si el cliente escribe dentro de ${minutes / 60} h`
        : `Reabre si el cliente escribe dentro de ${minutes} min`;
}

function messagesLabel(connection: DepartmentConnection): string {
    const enabled = [
        connection.messages.welcome && 'bienvenida',
        connection.messages.away && 'fuera de horario',
        connection.messages.farewell && 'despedida',
    ].filter(Boolean);

    return enabled.length === 0
        ? 'Sin mensajes automáticos propios'
        : `Mensajes activos: ${enabled.join(', ')}`;
}
</script>

<template>
    <SurfaceCard
        title="Atención en este momento"
        description="Cómo opera hoy el departamento según su horario, los días no laborables, el límite de carga y la reapertura de sus conexiones."
        body-class="divide-y"
    >
        <div class="flex items-start gap-3 p-5">
            <Clock
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="space-y-1 text-sm">
                <p class="flex flex-wrap items-center gap-2 font-medium">
                    <span
                        class="size-2 rounded-full"
                        :class="
                            automation.hours.is_open
                                ? 'bg-success'
                                : 'bg-warning'
                        "
                        aria-hidden="true"
                    />
                    {{
                        automation.hours.is_open
                            ? 'Dentro del horario de atención'
                            : 'Fuera del horario de atención'
                    }}
                </p>
                <p class="text-muted-foreground">
                    {{ modeLabel }} · zona horaria
                    {{ automation.hours.timezone.replaceAll('_', ' ') }}.
                </p>
                <p
                    v-if="automation.hours.holiday"
                    class="text-muted-foreground"
                >
                    Hoy es día no laborable: {{ automation.hours.holiday }}.
                </p>
                <p
                    v-if="!automation.hours.is_open"
                    class="text-muted-foreground"
                >
                    {{
                        automation.hours.next_opening
                            ? endSentence(
                                  `Vuelve a abrir: ${formatDateTime(automation.hours.next_opening)}`,
                              )
                            : 'El horario configurado no tiene días abiertos.'
                    }}
                    Mientras tanto se envía el mensaje fuera de horario, si
                    existe.
                </p>
            </div>
        </div>

        <div class="flex items-start gap-3 p-5">
            <CalendarOff
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 space-y-1 text-sm">
                <p class="font-medium">Próximos días no laborables</p>
                <ul
                    v-if="automation.holidays.length > 0"
                    class="space-y-0.5 text-muted-foreground"
                >
                    <li
                        v-for="holiday in automation.holidays"
                        :key="holiday.date"
                    >
                        {{ formatHoliday(holiday) }} · {{ holiday.label }}
                    </li>
                </ul>
                <p v-else class="text-muted-foreground">
                    No hay días no laborables configurados.
                </p>
            </div>
        </div>

        <div class="flex items-start gap-3 p-5">
            <Gauge
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="space-y-1 text-sm">
                <p class="font-medium">Límite de carga por agente</p>
                <p class="text-muted-foreground">
                    {{
                        automation.load_limit === null
                            ? 'Sin límite: la distribución automática reparte por menor carga.'
                            : `Hasta ${automation.load_limit} conversaciones en atención por agente (aplica a toda la plataforma).`
                    }}
                </p>
                <Link
                    v-if="automation.settings_url"
                    :href="automation.settings_url"
                    class="text-xs font-medium text-primary underline-offset-4 hover:underline"
                >
                    Cambiar en Configuración
                </Link>
            </div>
        </div>

        <div class="flex items-start gap-3 p-5">
            <RotateCcw
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 space-y-2 text-sm">
                <p class="font-medium">
                    Reapertura y mensajes de las conexiones
                </p>
                <ul v-if="activeConnections.length > 0" class="space-y-2">
                    <li
                        v-for="connection in activeConnections"
                        :key="connection.id"
                    >
                        <p class="truncate">{{ connection.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ reopenLabel(connection.reopen_window_minutes) }}.
                            {{ messagesLabel(connection) }}.
                        </p>
                    </li>
                </ul>
                <p v-else class="text-muted-foreground">
                    Ninguna conexión activa envía conversaciones a este
                    departamento.
                </p>
            </div>
        </div>
    </SurfaceCard>
</template>
