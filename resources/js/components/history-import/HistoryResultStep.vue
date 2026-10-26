<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Archive,
    Ban,
    CircleAlert,
    Copy,
    Download,
    FileDown,
    History,
    Inbox,
    RotateCw,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import StatCard from '@/components/StatCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { index as historyIndex } from '@/routes/admin/history';
import { index as conversationsIndex } from '@/routes/conversations';
import {
    report as reportRoute,
    resume as resumeRoute,
} from '@/routes/admin/history-import';
import type { HistoryBatch } from '@/types/history-import';
import { errorMessage, historyRequest } from './api';
import { formatNumber, plural } from './labels';

/**
 * Step 5: progress while the confirmed import runs (the page keeps
 * working; the parent polls), resume when it stopped, and the final report
 * with its CSV.
 */
const props = defineProps<{
    batch: HistoryBatch;
    canExecute: boolean;
    canArchive: boolean;
}>();

const emit = defineEmits<{
    batch: [batch: HistoryBatch];
}>();

const running = computed(() =>
    ['queued', 'processing'].includes(props.batch.status),
);

const percent = computed(() => {
    const { total, processed } = props.batch.progress;

    return total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
});

const resuming = ref(false);

/** Conversations that failed and «Reintentar fallidas» would try again. */
const retryable = computed(() => props.batch.result?.retryable ?? 0);
const withErrors = computed(
    () =>
        props.batch.status === 'completed' &&
        (props.batch.result?.outcome === 'completed_with_errors' ||
            retryable.value > 0),
);

async function resume(): Promise<void> {
    resuming.value = true;

    try {
        const response = await historyRequest<{
            batch: HistoryBatch;
            message: string;
        }>('POST', resumeRoute.url(props.batch.id));
        emit('batch', response.batch);
        toast.info(response.message);
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        resuming.value = false;
    }
}
</script>

<template>
    <div class="grid gap-6" data-tour="history-import.result">
        <SurfaceCard
            v-if="running"
            title="Importando…"
            description="Puedes salir de esta página o seguir trabajando: la importación continúa sola."
            body-class="grid gap-3 p-4 sm:p-5"
        >
            <div
                class="h-2.5 w-full overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-label="Avance de la importación"
                :aria-valuenow="percent"
                aria-valuemin="0"
                aria-valuemax="100"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width] duration-500 motion-reduce:transition-none"
                    :style="{ width: `${percent}%` }"
                />
            </div>
            <p class="text-sm text-muted-foreground tabular-nums" data-progress>
                {{ formatNumber(batch.progress.processed) }} de
                {{
                    plural(
                        batch.progress.total,
                        'conversación',
                        'conversaciones',
                    )
                }}
                ({{ percent }} %)
                <template v-if="batch.progress.failed > 0">
                    · {{ formatNumber(batch.progress.failed) }} con error
                </template>
            </p>
        </SurfaceCard>

        <StatusNote
            v-if="batch.status === 'failed'"
            tone="danger"
            title="La importación se detuvo"
        >
            {{ batch.error ?? 'Ocurrió un problema mientras se importaba.' }}
            Lo que ya se importó no se repetirá al continuar.
            <template v-if="canExecute" #actions>
                <Button size="sm" :disabled="resuming" @click="resume">
                    <Spinner v-if="resuming" />
                    <RotateCw v-else />
                    Continuar importación
                </Button>
            </template>
        </StatusNote>

        <StatusNote
            v-if="batch.status === 'expired'"
            tone="info"
            title="Lote cerrado"
        >
            {{ batch.error ?? 'Sus archivos se eliminaron.' }}
        </StatusNote>

        <template v-if="batch.result">
            <StatusNote
                v-if="withErrors"
                tone="warning"
                title="Importación terminada con errores"
                live="polite"
                data-result-with-errors
            >
                Terminó el {{ formatDateTime(batch.finished_at) }}, pero
                {{
                    plural(
                        batch.result.failed,
                        'conversación no se pudo importar',
                        'conversaciones no se pudieron importar',
                    )
                }}. No se envió ningún mensaje ni se notificó a nadie.
                <template v-if="retryable > 0">
                    Puedes reintentar
                    {{ plural(retryable, 'la que falló', 'las que fallaron') }}:
                    lo que ya se importó no se repite. El reporte CSV indica el
                    motivo de cada una.
                </template>
                <template v-else>
                    Ya no quedan reintentos; el reporte CSV indica el motivo de
                    cada una.
                </template>
                <template v-if="canExecute && retryable > 0" #actions>
                    <Button
                        size="sm"
                        :disabled="resuming"
                        data-retry-failed
                        @click="resume"
                    >
                        <Spinner v-if="resuming" />
                        <RotateCw v-else />
                        Reintentar fallidas ({{ formatNumber(retryable) }})
                    </Button>
                </template>
            </StatusNote>
            <StatusNote
                v-else-if="batch.status === 'completed'"
                tone="success"
                title="Importación terminada"
                live="polite"
            >
                Terminó el {{ formatDateTime(batch.finished_at) }}. No se envió
                ningún mensaje ni se notificó a nadie.
            </StatusNote>

            <section aria-labelledby="result-title" class="grid gap-3">
                <h2 id="result-title" class="text-base font-semibold">
                    Reporte final
                </h2>
                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5"
                    data-result
                >
                    <StatCard
                        label="Importadas"
                        :value="formatNumber(batch.result.imported)"
                        :icon="Download"
                        hint="Visibles en Cerradas"
                    />
                    <StatCard
                        label="Archivadas"
                        :value="formatNumber(batch.result.archived)"
                        :icon="Archive"
                        hint="Solo consulta"
                    />
                    <StatCard
                        label="Duplicadas"
                        :value="formatNumber(batch.result.duplicates)"
                        :icon="Copy"
                        hint="Ya estaban importadas"
                    />
                    <StatCard
                        label="No importadas"
                        :value="formatNumber(batch.result.excluded)"
                        :icon="Ban"
                    />
                    <StatCard
                        label="Con error"
                        :value="formatNumber(batch.result.failed)"
                        :icon="CircleAlert"
                    />
                </div>
                <dl
                    class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2"
                >
                    <div class="flex justify-between gap-2 border-b py-1.5">
                        <dt class="text-muted-foreground">
                            Mensajes guardados
                        </dt>
                        <dd class="tabular-nums">
                            {{ formatNumber(batch.result.messages) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b py-1.5">
                        <dt class="text-muted-foreground">
                            Archivos recuperados
                        </dt>
                        <dd class="tabular-nums">
                            {{
                                formatNumber(batch.result.attachments_available)
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b py-1.5">
                        <dt class="text-muted-foreground">
                            Archivos no disponibles
                        </dt>
                        <dd class="tabular-nums">
                            {{ formatNumber(batch.result.attachments_missing) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b py-1.5">
                        <dt class="text-muted-foreground">Contactos creados</dt>
                        <dd class="tabular-nums">
                            {{ formatNumber(batch.result.contacts_created) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2 border-b py-1.5">
                        <dt class="text-muted-foreground">
                            Contactos guardados en el directorio
                        </dt>
                        <dd class="tabular-nums">
                            {{ formatNumber(batch.result.contacts_saved) }}
                        </dd>
                    </div>
                </dl>
            </section>
        </template>

        <div
            class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:justify-end"
        >
            <Button
                v-if="canArchive && batch.result"
                variant="outline"
                as-child
            >
                <Link
                    :href="
                        historyIndex({
                            query: { batch_id: batch.id, destination: 'all' },
                        })
                    "
                >
                    <History />
                    Ver en Historial de Whaticket
                </Link>
            </Button>
            <Button
                v-if="batch.result && batch.result.imported > 0"
                variant="outline"
                as-child
            >
                <Link
                    :href="conversationsIndex({ query: { status: 'closed' } })"
                >
                    <Inbox />
                    Ver conversaciones cerradas
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <a
                    :href="reportRoute.url(batch.id)"
                    download
                    data-report-download
                >
                    <FileDown />
                    Descargar reporte (CSV)
                </a>
            </Button>
        </div>
    </div>
</template>
