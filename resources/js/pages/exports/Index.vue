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
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLiveReload } from '@/composables/useLiveReload';
import { userChannelName } from '@/composables/useUserChannel';
import { formatDateTime, formatRelative } from '@/lib/format';
import { index as exportsIndex } from '@/routes/exports';
import type { DataTransfer, DataTransferStatus, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Archivos preparados', href: exportsIndex() }],
    },
});

const props = defineProps<{
    transfers: Paginated<DataTransfer>;
    retentionHours: number;
}>();

const hasPending = computed(() =>
    props.transfers.data.some((transfer) =>
        ['queued', 'processing'].includes(transfer.status.value),
    ),
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
                <span
                    v-if="hasPending"
                    class="inline-flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <Spinner class="size-4" />
                    Generando archivos…
                </span>
            </template>
        </PageHeader>

        <template v-if="transfers.data.length > 0">
            <div
                class="hidden reveal overflow-hidden rounded-xl border bg-card md:block"
                data-tour="exports.list"
            >
                <Table>
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="pl-5">Reporte</TableHead>
                            <TableHead>Estado</TableHead>
                            <TableHead class="text-right">Registros</TableHead>
                            <TableHead>Solicitado</TableHead>
                            <TableHead class="pr-5 text-right">
                                <span class="sr-only">Acciones</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="transfer in transfers.data"
                            :key="transfer.id"
                        >
                            <TableCell class="w-full max-w-0 pl-5">
                                <div class="flex items-start gap-3">
                                    <span
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                                    >
                                        <component
                                            :is="formatIcon(transfer)"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div class="min-w-0 space-y-0.5">
                                        <p class="font-medium">
                                            {{ transfer.report_title }}
                                            <span
                                                class="font-normal text-muted-foreground"
                                            >
                                                · {{ transfer.format.label }}
                                            </span>
                                        </p>
                                        <p
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{
                                                transfer.filters.length > 0
                                                    ? transfer.filters.join(
                                                          ' · ',
                                                      )
                                                    : 'Sin filtros'
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="space-y-1">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            statusStyles[transfer.status.value]
                                                .class
                                        "
                                    >
                                        <Spinner
                                            v-if="
                                                transfer.status.value ===
                                                'processing'
                                            "
                                            class="size-3"
                                        />
                                        <component
                                            :is="
                                                statusStyles[
                                                    transfer.status.value
                                                ].icon
                                            "
                                            v-else
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        {{ transfer.status.label }}
                                    </span>
                                    <div
                                        v-if="
                                            transfer.status.value ===
                                                'processing' ||
                                            transfer.status.value === 'queued'
                                        "
                                        class="h-1 w-40 overflow-hidden rounded-full bg-muted"
                                        aria-hidden="true"
                                    >
                                        <div
                                            class="export-progress h-full w-2/5 rounded-full bg-primary"
                                        />
                                    </div>
                                    <p
                                        class="w-64 text-xs text-pretty whitespace-normal text-muted-foreground"
                                    >
                                        {{ detail(transfer) }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell
                                class="text-right text-muted-foreground tabular-nums"
                            >
                                {{ rows(transfer) }}
                            </TableCell>
                            <TableCell
                                class="text-muted-foreground tabular-nums"
                                :title="formatDateTime(transfer.created_at)"
                            >
                                {{ formatRelative(transfer.created_at) }}
                            </TableCell>
                            <TableCell class="pr-5 text-right">
                                <Button
                                    v-if="transfer.download_url"
                                    size="sm"
                                    data-tour="exports.download"
                                    as-child
                                >
                                    <a :href="transfer.download_url">
                                        <Download />
                                        Descargar
                                    </a>
                                </Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <ul class="grid gap-3 md:hidden" data-tour="exports.list">
                <li
                    v-for="transfer in transfers.data"
                    :key="transfer.id"
                    class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
                        >
                            <component
                                :is="formatIcon(transfer)"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ transfer.report_title }}
                                <span class="font-normal text-muted-foreground">
                                    · {{ transfer.format.label }}
                                </span>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatRelative(transfer.created_at) }} ·
                                {{ rows(transfer) }}
                            </p>
                        </div>
                        <span
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="statusStyles[transfer.status.value].class"
                        >
                            {{ transfer.status.label }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ detail(transfer) }}
                    </p>
                    <Button
                        v-if="transfer.download_url"
                        size="sm"
                        class="w-full"
                        data-tour="exports.download"
                        as-child
                    >
                        <a :href="transfer.download_url">
                            <Download />
                            Descargar
                        </a>
                    </Button>
                </li>
            </ul>
        </template>

        <EmptyState
            v-else
            :icon="FolderDown"
            title="Aún no tienes archivos preparados"
            description="Usa el botón «Exportar» de cada módulo. Los archivos grandes se preparan en segundo plano y aparecen aquí."
        />

        <DataPagination :meta="transfers.meta" noun="archivos" />
    </div>
</template>

<style scoped>
/* Indeterminate bar of a file that is being prepared (a loading indicator). */
.export-progress {
    animation: export-progress 1.4s var(--ease-standard) infinite;
}

@keyframes export-progress {
    from {
        transform: translateX(-110%);
    }

    to {
        transform: translateX(260%);
    }
}
</style>
