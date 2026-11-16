<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    AlertCircle,
    CheckCircle2,
    Clock,
    Download,
    FileSpreadsheet,
    FileText,
    FolderDown,
    Hourglass,
} from '@lucide/vue';
import { computed } from 'vue';
import DataPagination from '@/components/DataPagination.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLiveReload } from '@/composables/useLiveReload';
import { userChannelName } from '@/composables/useUserChannel';
import { formatDateTime, formatRelative } from '@/lib/format';
import { index as exportsIndex } from '@/routes/exports';
import type { DataTransfer, DataTransferStatus, Paginated } from '@/types';
import '@/components/filters/filters.css';
import '@/components/reports/insights.css';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Archivos preparados', href: exportsIndex() }],
    },
});

const props = defineProps<{
    transfers: Paginated<DataTransfer>;
    retentionHours: number;
}>();

function inProgress(transfer: DataTransfer): boolean {
    return ['queued', 'processing'].includes(transfer.status.value);
}

const hasPending = computed(() => props.transfers.data.some(inProgress));

/** The guided tour points at one download button, the first one. */
const firstDownloadable = computed(
    () => props.transfers.data.find((transfer) => transfer.download_url)?.id,
);

/**
 * While something is still being generated, the list refreshes as soon as
 * the server signals that one of the user's exports finished; without
 * real-time it refreshes every few seconds instead.
 */
useLiveReload({
    channels: () => {
        const channel = userChannelName();

        return channel ? [{ name: channel, events: ['transfer.changed'] }] : [];
    },
    only: ['transfers'],
    enabled: hasPending,
    fallbackIntervalMs: 4000,
    safetyIntervalMs: 30_000,
});

const statusStyles: Record<
    DataTransferStatus,
    { class: string; icon: typeof Clock }
> = {
    queued: {
        class: 'bg-warning-soft text-warning-soft-foreground',
        icon: Clock,
    },
    processing: {
        class: 'bg-warning-soft text-warning-soft-foreground',
        icon: Hourglass,
    },
    completed: {
        class: 'bg-success-soft text-success-soft-foreground',
        icon: CheckCircle2,
    },
    failed: {
        class: 'bg-destructive-soft text-destructive-soft-foreground',
        icon: AlertCircle,
    },
    expired: { class: 'bg-muted text-muted-foreground', icon: Clock },
};

function formatIcon(transfer: DataTransfer): typeof FileText {
    return transfer.format.value === 'xlsx' ? FileSpreadsheet : FileText;
}

function rows(transfer: DataTransfer): string {
    if (transfer.rows_total === null) {
        return '—';
    }

    return `${new Intl.NumberFormat('es-MX').format(transfer.rows_total)} ${transfer.rows_total === 1 ? 'registro' : 'registros'}`;
}

function filtersText(transfer: DataTransfer): string {
    return transfer.filters.length > 0
        ? transfer.filters.join(' · ')
        : 'Sin filtros';
}

function detail(transfer: DataTransfer): string {
    switch (transfer.status.value) {
        case 'completed':
            return transfer.expires_at
                ? `Disponible hasta ${formatDateTime(transfer.expires_at)}`
                : 'Disponible';
        case 'failed':
            return transfer.error ?? 'No se pudo generar el archivo.';
        case 'expired':
            return 'El archivo se eliminó al vencer su plazo.';
        default:
            return 'Se actualiza automáticamente.';
    }
}
</script>

<template>
    <Head title="Archivos preparados" />

    <div class="page-shell">
        <PageHeader
            help="reportes"
            title="Archivos preparados"
            :description="`Archivos de Excel y PDF que pediste preparar. Solo tú puedes descargarlos y se eliminan ${retentionHours} horas después de generarse. También los encuentras en el menú «Exportar» de cada módulo y en tus notificaciones.`"
        >
            <template #actions>
                <Transition
                    enter-active-class="motion-safe:transition-[opacity,transform] duration-(--duration-base) ease-(--ease-spring-soft)"
                    enter-from-class="opacity-0 scale-90"
                    leave-active-class="motion-safe:transition-opacity duration-(--duration-fast)"
                    leave-to-class="opacity-0"
                >
                    <span
                        v-if="hasPending"
                        class="inline-flex h-8 items-center gap-2 rounded-full border border-primary/25 bg-primary-soft px-3 text-sm font-medium text-primary-soft-foreground shadow-(--shadow-control)"
                        role="status"
                    >
                        <Spinner class="size-3.5" />
                        Generando archivos…
                    </span>
                </Transition>
            </template>
        </PageHeader>

        <section
            v-if="transfers.data.length > 0"
            class="overflow-hidden rounded-xl border border-[color-mix(in_oklch,var(--border)_85%,transparent)] bg-card shadow-float"
            aria-label="Tus archivos preparados"
            data-tour="exports.list"
        >
            <div
                class="hidden grid-cols-[minmax(0,1fr)_14rem_8rem_8rem_9rem] items-center gap-4 border-b bg-surface-sunken/60 px-5 py-2.5 text-xs font-medium text-muted-foreground lg:grid"
                aria-hidden="true"
            >
                <span>Reporte</span>
                <span>Estado</span>
                <span class="text-right">Registros</span>
                <span>Solicitado</span>
                <span class="sr-only">Acciones</span>
            </div>
            <TransitionGroup
                tag="ul"
                name="filter-row"
                class="relative reveal-stagger divide-y divide-[color-mix(in_oklch,var(--border)_70%,transparent)]"
            >
                <li
                    v-for="transfer in transfers.data"
                    :key="transfer.id"
                    class="grid gap-3 px-4 py-4 transition-colors duration-(--duration-fast) hover:bg-[color-mix(in_oklch,var(--accent)_45%,transparent)] sm:px-5 lg:grid-cols-[minmax(0,1fr)_14rem_8rem_8rem_9rem] lg:items-center lg:gap-4"
                    :data-transfer-status="transfer.status.value"
                    data-transfer
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="insight-tile"
                            :data-tone="
                                transfer.format.value === 'xlsx'
                                    ? 'success'
                                    : 'destructive'
                            "
                            aria-hidden="true"
                        >
                            <component
                                :is="formatIcon(transfer)"
                                class="size-4"
                            />
                        </span>
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <span class="font-medium">{{
                                    transfer.report_title
                                }}</span>
                                <span
                                    class="rounded-md border bg-surface-sunken px-1.5 py-px text-[0.6875rem] font-semibold text-muted-foreground"
                                    >{{ transfer.format.label }}</span
                                >
                            </p>
                            <p
                                class="text-xs text-pretty wrap-anywhere text-muted-foreground lg:truncate"
                                :title="filtersText(transfer)"
                            >
                                {{ filtersText(transfer) }}
                            </p>
                            <p
                                class="text-xs text-muted-foreground tabular-nums lg:hidden"
                            >
                                <time
                                    :datetime="transfer.created_at ?? undefined"
                                    :title="formatDateTime(transfer.created_at)"
                                    >{{
                                        formatRelative(transfer.created_at)
                                    }}</time
                                >
                                · {{ rows(transfer) }}
                            </p>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-1.5">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                            :class="statusStyles[transfer.status.value].class"
                            data-transfer-badge
                        >
                            <Spinner
                                v-if="transfer.status.value === 'processing'"
                                class="size-3"
                            />
                            <component
                                :is="statusStyles[transfer.status.value].icon"
                                v-else
                                class="size-3"
                                aria-hidden="true"
                            />
                            {{ transfer.status.label }}
                        </span>
                        <div
                            v-if="inProgress(transfer)"
                            class="insight-progress h-1 w-full max-w-48"
                            aria-hidden="true"
                        />
                        <p
                            class="text-xs text-pretty text-muted-foreground"
                            :class="
                                transfer.status.value === 'failed' &&
                                'text-destructive'
                            "
                        >
                            {{ detail(transfer) }}
                        </p>
                    </div>

                    <p
                        class="hidden text-right text-sm text-muted-foreground tabular-nums lg:block"
                    >
                        {{ rows(transfer) }}
                    </p>
                    <p
                        class="hidden text-sm text-muted-foreground tabular-nums lg:block"
                        :title="formatDateTime(transfer.created_at)"
                    >
                        <time :datetime="transfer.created_at ?? undefined">{{
                            formatRelative(transfer.created_at)
                        }}</time>
                    </p>

                    <div class="flex lg:justify-end">
                        <Button
                            v-if="transfer.download_url"
                            size="sm"
                            class="w-full rounded-full sm:w-auto"
                            :data-tour="
                                transfer.id === firstDownloadable
                                    ? 'exports.download'
                                    : undefined
                            "
                            as-child
                        >
                            <a
                                :href="transfer.download_url"
                                :aria-label="`Descargar ${transfer.report_title} (${transfer.format.label})`"
                            >
                                <Download aria-hidden="true" />
                                Descargar
                            </a>
                        </Button>
                    </div>
                </li>
            </TransitionGroup>
        </section>

        <SurfaceCard v-else>
            <FilteredEmpty
                :narrowed="false"
                narrowed-title=""
                :icon="FolderDown"
                title="Aún no tienes archivos preparados"
                description="Usa el botón «Exportar» de cada módulo. Los archivos grandes se preparan en segundo plano y aparecen aquí."
            />
        </SurfaceCard>

        <DataPagination :meta="transfers.meta" noun="archivos" />
    </div>
</template>
