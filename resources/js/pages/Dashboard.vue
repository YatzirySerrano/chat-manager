<script setup lang="ts">
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    CheckCircle2,
    Hourglass,
    Info,
    MessageCircle,
    UserRoundCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import Sparkline from '@/components/reports/Sparkline.vue';
import StatCard from '@/components/StatCard.vue';
import { usePermissions } from '@/composables/usePermissions';
import { formatDuration } from '@/lib/format';
import { dashboard } from '@/routes';
import { edit as platformSettings } from '@/routes/admin/settings';
import { index as reportsIndex } from '@/routes/reports';
import type {
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
    DashboardOverview,
} from '@/types';

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
    canExport?: boolean;
    canViewMetrics: boolean;
    overview?: DashboardOverview | null;
    filters?: AnalyticsQuery;
    period?: AnalyticsPeriod;
    periods?: AnalyticsPreset[];
    options?: AnalyticsOptions;
    canViewReports?: boolean;
}>();

const presetLabels: Record<string, string> = {
    today: 'Hoy',
    '7d': 'Últimos 7 días',
    '30d': 'Últimos 30 días',
};

const presets = computed(() =>
    (props.periods ?? []).map((value) => ({
        value,
        label: presetLabels[value] ?? value,
    })),
);

function applyFilters(query: AnalyticsQuery): void {
    router.get(dashboard.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['filters', 'period', 'overview'],
    });
}

const trendTotal = computed(() =>
    (props.overview?.trend ?? []).reduce(
        (sum, point) => sum + point.received,
        0,
    ),
);

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
        >
            <template v-if="canExport && canViewMetrics" #actions>
                <ExportMenu
                    report="dashboard"
                    :filters="filters"
                    label="Exportar resumen"
                />
            </template>
        </PageHeader>

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

        <AnalyticsFilterBar
            v-if="canViewMetrics && filters && period && options"
            :query="filters"
            :period="period"
            :presets="presets"
            :options="options"
            @change="applyFilters"
        />

        <Deferred v-if="canViewMetrics" data="overview">
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
                        <KpiCard
                            label="Primera respuesta humana (prom.)"
                            :value="
                                overview.average_first_response_seconds === null
                                    ? null
                                    : formatDuration(
                                          overview.average_first_response_seconds,
                                      )
                            "
                            :current="overview.average_first_response_seconds"
                            :previous="overview.previous_first_response_seconds"
                            :previous-label="
                                overview.previous_first_response_seconds ===
                                null
                                    ? null
                                    : formatDuration(
                                          overview.previous_first_response_seconds,
                                      )
                            "
                            better="lower"
                            :hint="
                                overview.first_response_samples > 0
                                    ? `${period?.label ?? ''} · ${overview.first_response_samples} conversaciones`
                                    : undefined
                            "
                            :unavailable-label="
                                overview.messaging_connected
                                    ? 'Sin datos suficientes en el periodo'
                                    : messagingUnavailable
                            "
                        />
                    </div>
                </section>

                <section
                    v-if="overview.received.value !== null"
                    aria-labelledby="demand-heading"
                    class="space-y-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2
                            id="demand-heading"
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Demanda del periodo
                        </h2>
                        <Link
                            v-if="canViewReports"
                            :href="reportsIndex({ query: filters })"
                            class="flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        >
                            Ver reportes completos
                            <ArrowRight class="size-3.5" aria-hidden="true" />
                        </Link>
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <KpiCard
                            label="Conversaciones recibidas"
                            :value="
                                overview.received.value.toLocaleString('es-MX')
                            "
                            :current="overview.received.value"
                            :previous="overview.received.previous"
                            :hint="
                                period
                                    ? `${period.label} vs. ${period.previous.label}`
                                    : undefined
                            "
                        />
                        <div
                            class="flex flex-col gap-2 rounded-xl border bg-card p-4 shadow-card lg:col-span-2"
                        >
                            <span class="text-sm text-muted-foreground">
                                Tendencia de recibidas
                            </span>
                            <Sparkline
                                v-if="trendTotal > 0"
                                :values="
                                    overview.trend.map(
                                        (point) => point.received,
                                    )
                                "
                                :label="`Conversaciones recibidas por ${period?.granularity === 'month' ? 'mes' : 'día'}: ${overview.trend.map((point) => `${point.label} ${point.received}`).join(', ')}`"
                            />
                            <p v-else class="text-sm text-muted-foreground">
                                Sin conversaciones recibidas en el periodo.
                            </p>
                            <div
                                v-if="
                                    trendTotal > 0 && overview.trend.length > 1
                                "
                                class="flex justify-between text-xs text-muted-foreground"
                            >
                                <span>{{ overview.trend[0]?.label }}</span>
                                <span>{{
                                    overview.trend[overview.trend.length - 1]
                                        ?.label
                                }}</span>
                            </div>
                        </div>
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
                            label="Agentes disponibles ahora"
                            :icon="UserRoundCheck"
                            :value="overview.agents.available"
                            :hint="
                                overview.agents.registered === null
                                    ? undefined
                                    : `De ${overview.agents.registered} ${overview.agents.registered === 1 ? 'persona activa que puede atender' : 'personas activas que pueden atender'} (disponibilidad declarada)`
                            "
                            unavailable-label="Sin datos"
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
