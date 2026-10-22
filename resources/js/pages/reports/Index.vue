<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeftRight,
    CheckCircle2,
    Clock3,
    Inbox,
    MessageCircleReply,
    MoonStar,
    RotateCcw,
    Send,
    Timer,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import AnalyticsFilterBar from '@/components/reports/AnalyticsFilterBar.vue';
import BreakdownTable from '@/components/reports/BreakdownTable.vue';
import DistributionBars from '@/components/reports/DistributionBars.vue';
import HourHeatmap from '@/components/reports/HourHeatmap.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import TrendChart from '@/components/reports/TrendChart.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDuration } from '@/lib/format';
import { index as reportsIndex } from '@/routes/reports';
import type {
    AnalyticsDuration,
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
    OperationalAnalytics,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reportes', href: reportsIndex() }],
    },
});

const props = defineProps<{
    filters: AnalyticsQuery;
    period: AnalyticsPeriod;
    invalidFilters: string | null;
    presets: { value: AnalyticsPreset; label: string }[];
    maxDays: number;
    scope: 'all' | 'departments' | 'own';
    options: AnalyticsOptions;
    analytics?: OperationalAnalytics;
    canExport: boolean;
}>();

const loading = ref(false);
const removeStart = router.on('start', (event) => {
    if (!event.detail.visit.async) {
        loading.value = true;
    }
});
const removeFinish = router.on('finish', () => {
    loading.value = false;
});

onUnmounted(() => {
    removeStart();
    removeFinish();
});

function applyFilters(query: AnalyticsQuery): void {
    router.get(reportsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['filters', 'period', 'invalidFilters', 'analytics'],
    });
}

const scopeDescription = computed(
    () =>
        ({
            all: 'Indicadores de toda la operación.',
            departments:
                'Indicadores de tus departamentos y de las conversaciones que atiendes.',
            own: 'Tus propios indicadores de atención.',
        })[props.scope],
);

function duration(figure: AnalyticsDuration): string | null {
    return figure.value === null ? null : formatDuration(figure.value);
}

function count(value: number | null): string | null {
    return value === null ? null : value.toLocaleString('es-MX');
}

function share(part: number | null, total: number | null): string | undefined {
    if (part === null || total === null || total === 0) {
        return undefined;
    }

    return `${Math.round((part / total) * 100)} % de las recibidas`;
}

function samples(figure: AnalyticsDuration): string {
    return figure.samples === 0
        ? 'Ninguna conversación tiene aún los eventos necesarios'
        : `Promedio de ${figure.samples.toLocaleString('es-MX')} ${figure.samples === 1 ? 'conversación' : 'conversaciones'}`;
}

const statusItems = computed(() =>
    (props.analytics?.statuses ?? []).map((status) => ({
        key: status.status,
        label: status.label,
        count: status.count,
    })),
);
</script>

<template>
    <Head title="Reportes" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader title="Reportes operativos" :description="scopeDescription">
            <template v-if="canExport" #actions>
                <ExportMenu
                    report="analytics"
                    :filters="filters"
                    label="Exportar reporte"
                    heading="Exportar con el periodo y filtros actuales"
                />
            </template>
        </PageHeader>

        <AnalyticsFilterBar
            :query="filters"
            :period="period"
            :presets="presets"
            :options="options"
            :max-days="maxDays"
            @change="applyFilters"
        />

        <div
            v-if="invalidFilters"
            class="flex gap-3 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm"
            role="alert"
        >
            <AlertTriangle
                class="mt-0.5 size-4 shrink-0 text-warning"
                aria-hidden="true"
            />
            <p>
                No se pudieron aplicar los filtros ({{ invalidFilters }}). Se
                muestran los últimos 30 días.
            </p>
        </div>

        <Deferred data="analytics">
            <template #fallback>
                <div
                    class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    aria-busy="true"
                    aria-label="Cargando reportes"
                >
                    <StatCard
                        v-for="index in 8"
                        :key="index"
                        label="Cargando…"
                        :value="null"
                        loading
                    />
                </div>
                <Skeleton class="mt-6 h-64 w-full rounded-xl" />
            </template>

            <div
                v-if="analytics"
                class="flex flex-col gap-6 transition-opacity"
                :class="{ 'opacity-60': loading }"
                :aria-busy="loading"
            >
                <section aria-labelledby="attention-heading" class="space-y-3">
                    <h2
                        id="attention-heading"
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Atención del periodo
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <KpiCard
                            label="Recibidas"
                            :icon="Inbox"
                            :value="count(analytics.summary.received.value)"
                            :current="analytics.summary.received.value"
                            :previous="analytics.summary.received.previous"
                            hint="Conversaciones nuevas en el periodo"
                        />
                        <KpiCard
                            label="Atendidas"
                            :icon="UserRoundCheck"
                            :value="count(analytics.summary.attended.value)"
                            :current="analytics.summary.attended.value"
                            :previous="analytics.summary.attended.previous"
                            better="higher"
                            :hint="
                                share(
                                    analytics.summary.attended.value,
                                    analytics.summary.received.value,
                                ) ?? 'Aceptadas o asignadas a un agente'
                            "
                        />
                        <KpiCard
                            label="Resueltas"
                            :icon="CheckCircle2"
                            :value="count(analytics.summary.resolved.value)"
                            :current="analytics.summary.resolved.value"
                            :previous="analytics.summary.resolved.previous"
                            better="higher"
                            :hint="
                                share(
                                    analytics.summary.resolved.value,
                                    analytics.summary.received.value,
                                ) ?? 'Cerradas al menos una vez'
                            "
                        />
                        <KpiCard
                            label="Pendientes ahora"
                            :icon="Clock3"
                            :value="count(analytics.current.pending)"
                            :hint="`${analytics.current.open.toLocaleString('es-MX')} en atención · ${analytics.current.on_hold.toLocaleString('es-MX')} en espera`"
                        />
                    </div>
                </section>

                <section aria-labelledby="times-heading" class="space-y-3">
                    <h2
                        id="times-heading"
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Tiempos (tiempo calendario)
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <KpiCard
                            label="Primera atención (prom.)"
                            :icon="Timer"
                            :value="
                                duration(
                                    analytics.summary.first_attention_seconds,
                                )
                            "
                            :current="
                                analytics.summary.first_attention_seconds.value
                            "
                            :previous="
                                analytics.summary.first_attention_seconds
                                    .previous
                            "
                            :previous-label="
                                analytics.summary.first_attention_seconds
                                    .previous === null
                                    ? null
                                    : formatDuration(
                                          analytics.summary
                                              .first_attention_seconds.previous,
                                      )
                            "
                            better="lower"
                            :hint="`Desde que llega hasta que un agente la toma. ${samples(analytics.summary.first_attention_seconds)}`"
                        />
                        <KpiCard
                            label="Primera respuesta humana (prom.)"
                            :icon="MessageCircleReply"
                            :value="
                                duration(
                                    analytics.summary.first_response_seconds,
                                )
                            "
                            :current="
                                analytics.summary.first_response_seconds.value
                            "
                            :previous="
                                analytics.summary.first_response_seconds
                                    .previous
                            "
                            :previous-label="
                                analytics.summary.first_response_seconds
                                    .previous === null
                                    ? null
                                    : formatDuration(
                                          analytics.summary
                                              .first_response_seconds.previous,
                                      )
                            "
                            better="lower"
                            :hint="`Sin contar mensajes automáticos ni fallidos. ${samples(analytics.summary.first_response_seconds)}`"
                        />
                        <KpiCard
                            label="Resolución (prom.)"
                            :icon="CheckCircle2"
                            :value="
                                duration(analytics.summary.resolution_seconds)
                            "
                            :current="
                                analytics.summary.resolution_seconds.value
                            "
                            :previous="
                                analytics.summary.resolution_seconds.previous
                            "
                            :previous-label="
                                analytics.summary.resolution_seconds
                                    .previous === null
                                    ? null
                                    : formatDuration(
                                          analytics.summary.resolution_seconds
                                              .previous,
                                      )
                            "
                            better="lower"
                            :hint="`Desde que llega hasta su primer cierre. ${samples(analytics.summary.resolution_seconds)}`"
                        />
                    </div>
                </section>

                <section aria-labelledby="quality-heading" class="space-y-3">
                    <h2
                        id="quality-heading"
                        class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                    >
                        Seguimiento y mensajes
                    </h2>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <KpiCard
                            label="Reabiertas"
                            :icon="RotateCcw"
                            :value="count(analytics.summary.reopened.value)"
                            :current="analytics.summary.reopened.value"
                            :previous="analytics.summary.reopened.previous"
                            better="lower"
                            hint="Conversaciones reabiertas al menos una vez"
                        />
                        <KpiCard
                            label="Transferencias"
                            :icon="ArrowLeftRight"
                            :value="count(analytics.summary.transfers.value)"
                            :current="analytics.summary.transfers.value"
                            :previous="analytics.summary.transfers.previous"
                            hint="Total de transferencias realizadas"
                        />
                        <KpiCard
                            label="Fuera de horario"
                            :icon="MoonStar"
                            :value="count(analytics.summary.out_of_hours.value)"
                            :current="analytics.summary.out_of_hours.value"
                            :previous="analytics.summary.out_of_hours.previous"
                            unavailable-label="Sin horario laboral configurado"
                            :hint="
                                analytics.summary.out_of_hours.value === null
                                    ? 'Activa el horario en Configuración o en el departamento'
                                    : `De ${analytics.summary.out_of_hours_measurable.toLocaleString('es-MX')} con horario aplicable`
                            "
                        />
                        <KpiCard
                            label="Errores de envío"
                            :icon="Send"
                            :value="count(analytics.summary.failed_out.value)"
                            :current="analytics.summary.failed_out.value"
                            :previous="analytics.summary.failed_out.previous"
                            better="lower"
                            :hint="
                                analytics.summary.failure_rate.value === null
                                    ? 'Sin mensajes enviados en el periodo'
                                    : `${analytics.summary.failure_rate.value} % de ${(analytics.summary.messages_out.value ?? 0).toLocaleString('es-MX')} enviados · ${(analytics.summary.messages_in.value ?? 0).toLocaleString('es-MX')} recibidos`
                            "
                        />
                    </div>
                </section>

                <div class="grid gap-6 lg:grid-cols-3">
                    <SurfaceCard
                        class="lg:col-span-2"
                        title="Conversaciones recibidas"
                        :description="`${analytics.trend.granularity === 'day' ? 'Por día' : 'Por mes'}, comparadas con el periodo anterior`"
                        body-class="p-5"
                    >
                        <TrendChart
                            :labels="
                                analytics.trend.points.map(
                                    (point) => point.label,
                                )
                            "
                            :series="{
                                name: 'Este periodo',
                                values: analytics.trend.points.map(
                                    (point) => point.received,
                                ),
                            }"
                            :comparison="{
                                name: 'Periodo anterior',
                                values: analytics.trend.points.map(
                                    (point) => point.previous_received,
                                ),
                            }"
                            :description="`Conversaciones recibidas ${period.label}: ${analytics.summary.received.value ?? 0} en total`"
                        />
                    </SurfaceCard>
                    <SurfaceCard
                        title="Estado actual"
                        description="De las conversaciones recibidas en el periodo"
                        body-class="p-5"
                    >
                        <DistributionBars
                            :items="statusItems"
                            label="Conversaciones por estado"
                        />
                    </SurfaceCard>
                </div>

                <SurfaceCard
                    title="Horarios de mayor demanda"
                    description="Conversaciones recibidas por día de la semana y hora"
                    body-class="p-5"
                >
                    <HourHeatmap
                        :cells="analytics.heatmap.cells"
                        :max="analytics.heatmap.max"
                        :timezone="period.timezone"
                    />
                </SurfaceCard>

                <SurfaceCard
                    :title="
                        scope === 'own'
                            ? 'Tu desempeño'
                            : 'Desempeño y carga por agente'
                    "
                    :description="
                        analytics.current.available_agents === null
                            ? 'Agente responsable actual (o último, si ya se cerró)'
                            : `${analytics.current.available_agents} de ${analytics.current.agents} agentes disponibles ahora · agente responsable actual o último`
                    "
                >
                    <BreakdownTable
                        :rows="analytics.by_agent"
                        dimension="Agente"
                        show-load
                    />
                </SurfaceCard>

                <SurfaceCard
                    v-if="scope !== 'own'"
                    title="Por departamento"
                    description="Departamento actual de cada conversación"
                >
                    <BreakdownTable
                        :rows="analytics.by_department"
                        dimension="Departamento"
                        show-load
                    />
                </SurfaceCard>

                <SurfaceCard
                    title="Por conexión"
                    description="Número de WhatsApp por el que llegó"
                >
                    <BreakdownTable
                        :rows="analytics.by_connection"
                        dimension="Conexión"
                    />
                </SurfaceCard>
            </div>
        </Deferred>
    </div>
</template>
