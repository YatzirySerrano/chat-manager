<script setup lang="ts">
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CheckCircle2,
    Clock3,
    Hourglass,
    Info,
    MessageCircle,
    UserRoundCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDuration } from '@/lib/format';
import { dashboard } from '@/routes';
import { edit as platformSettings } from '@/routes/admin/settings';
import type { DashboardOverview } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Panel principal',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    canViewMetrics: boolean;
    overview?: DashboardOverview | null;
}>();

const page = usePage();
const { can } = usePermissions();

const firstName = computed(
    () => page.props.auth.user.name.split(' ')[0] ?? page.props.auth.user.name,
);

const scopeDescription = computed(() => {
    switch (props.overview?.scope) {
        case 'all':
            return 'Vista general de toda la operación.';
        case 'departments':
            return 'Vista general de tus departamentos.';
        case 'own':
            return 'Vista general de tu bandeja de atención.';
        default:
            return props.canViewMetrics
                ? 'Cargando indicadores…'
                : 'Usa el menú lateral para ir a los módulos de tu rol.';
    }
});

const messagingUnavailable = 'Disponible al conectar WhatsApp';
</script>

<template>
    <Head title="Panel principal" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            :title="`Hola, ${firstName}`"
            :description="scopeDescription"
        />

        <div
            v-if="!canViewMetrics"
            class="flex gap-3 rounded-xl border p-4 text-sm"
            role="status"
        >
            <Info
                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-muted-foreground">
                Tu rol no incluye los indicadores del panel principal. Si los
                necesitas, solicita el permiso «Ver el panel principal» a un
                administrador.
            </p>
        </div>

        <Deferred v-else data="overview">
            <template #fallback>
                <div
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                    aria-busy="true"
                    aria-label="Cargando indicadores"
                >
                    <StatCard
                        v-for="index in 6"
                        :key="index"
                        label="Cargando…"
                        :value="null"
                        loading
                    />
                </div>
            </template>

            <div v-if="overview" class="flex flex-col gap-6">
                <div
                    v-if="!overview.messaging_connected"
                    class="flex gap-3 rounded-xl border border-primary/20 bg-primary-soft/60 p-4 text-sm"
                    role="status"
                >
                    <Info
                        class="mt-0.5 size-4 shrink-0 text-primary-soft-foreground"
                        aria-hidden="true"
                    />
                    <div class="space-y-1">
                        <p class="font-medium">
                            La mensajería de WhatsApp aún no está conectada
                        </p>
                        <p class="text-muted-foreground">
                            Los indicadores de conversaciones y tiempos de
                            respuesta se calcularán con datos reales en cuanto
                            se habilite la integración con Meta.
                            <Link
                                v-if="can('settings.view')"
                                :href="platformSettings()"
                                class="font-medium text-foreground underline underline-offset-4"
                            >
                                Ver estado de la integración
                            </Link>
                        </p>
                    </div>
                </div>

                <section
                    aria-labelledby="conversations-heading"
                    class="space-y-3"
                >
                    <h2
                        id="conversations-heading"
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Conversaciones
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Activas"
                            :icon="MessageCircle"
                            :value="overview.conversations.active"
                            hint="Asignadas y en atención"
                            :unavailable-label="messagingUnavailable"
                        />
                        <StatCard
                            label="Pendientes"
                            :icon="Hourglass"
                            :value="overview.conversations.pending"
                            hint="En cola sin agente asignado"
                            :unavailable-label="messagingUnavailable"
                        />
                        <StatCard
                            label="Cerradas hoy"
                            :icon="CheckCircle2"
                            :value="overview.conversations.closed_today"
                            hint="Desde las 00:00 (hora de la plataforma)"
                            :unavailable-label="messagingUnavailable"
                        />
                        <StatCard
                            label="Primera respuesta (prom.)"
                            :icon="Clock3"
                            :value="
                                overview.average_first_response_seconds === null
                                    ? null
                                    : formatDuration(
                                          overview.average_first_response_seconds,
                                      )
                            "
                            hint="Últimos 30 días"
                            :unavailable-label="
                                overview.messaging_connected
                                    ? 'Aún no hay respuestas registradas'
                                    : messagingUnavailable
                            "
                        />
                    </div>
                </section>

                <section
                    v-if="overview.departments !== null"
                    aria-labelledby="team-heading"
                    class="space-y-3"
                >
                    <h2
                        id="team-heading"
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Equipo
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            label="Agentes disponibles"
                            :icon="UserRoundCheck"
                            :value="overview.agents.available"
                            :hint="
                                overview.agents.registered === null
                                    ? undefined
                                    : `${overview.agents.registered} ${overview.agents.registered === 1 ? 'persona activa puede atender conversaciones' : 'personas activas pueden atender conversaciones'}`
                            "
                            unavailable-label="Requiere estado de presencia en línea"
                        />
                        <StatCard
                            label="Departamentos activos"
                            :icon="Building2"
                            :value="overview.departments.active"
                            :hint="`${overview.departments.total} en total`"
                        />
                    </div>
                </section>
            </div>
        </Deferred>
    </div>
</template>
