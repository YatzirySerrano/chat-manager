<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Check, FileDown, Trash2 } from '@lucide/vue';
import {
    useDocumentVisibility,
    useIntervalFn,
    usePreferredReducedMotion,
} from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import {
    errorMessage,
    historyRequest,
    latestRequest,
} from '@/components/history-import/api';
import HistoryFilesStep from '@/components/history-import/HistoryFilesStep.vue';
import HistoryInventory from '@/components/history-import/HistoryInventory.vue';
import HistoryMappingStep from '@/components/history-import/HistoryMappingStep.vue';
import HistoryResultStep from '@/components/history-import/HistoryResultStep.vue';
import HistoryRulesPanel from '@/components/history-import/HistoryRulesPanel.vue';
import HistorySummaryStep from '@/components/history-import/HistorySummaryStep.vue';
import {
    BATCH_STATUS_BADGE,
    FORMAT_DISCLAIMER,
    formatNumber,
} from '@/components/history-import/labels';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatTimeZone } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    destroy as destroyRoute,
    index as importIndex,
    progress as progressRoute,
    report as reportRoute,
    show as importShow,
    summary as summaryRoute,
} from '@/routes/admin/history-import';
import type {
    HistoryBatch,
    HistoryFacets,
    HistoryFilters,
    HistoryMappingRows,
    HistoryOptions,
    HistoryRule,
    HistorySummary,
} from '@/types/history-import';

const props = defineProps<{
    batch: HistoryBatch;
    summary: HistorySummary;
    facets: HistoryFacets;
    mappings: HistoryMappingRows;
    rules: HistoryRule[];
    options: HistoryOptions;
    can: { classify: boolean; execute: boolean; archive: boolean };
    limits: { max_kilobytes: number; per_page: number };
    formatNote: string;
}>();

defineOptions({
    layout: (pageProps: { batch: HistoryBatch }) => ({
        breadcrumbs: [
            { title: 'Importar de Whaticket', href: importIndex() },
            {
                title: pageProps.batch.label ?? `Lote ${pageProps.batch.id}`,
                href: importShow(pageProps.batch.id),
            },
        ],
    }),
});

type Step = 'files' | 'mapping' | 'classify' | 'summary' | 'result';

/* Live copies: polling and JSON responses update them between visits. */
const batch = ref<HistoryBatch>(props.batch);
const summary = ref<HistorySummary | null>(props.summary);
const mappings = ref<HistoryMappingRows>(props.mappings);

watch(
    () => props.batch,
    (value) => (batch.value = value),
);
watch(
    () => props.summary,
    (value) => (summary.value = value),
);
watch(
    () => props.mappings,
    (value) => (mappings.value = value),
);

const isDraft = computed(() => batch.value.status === 'draft');
const editable = computed(() => isDraft.value && props.can.classify);
const running = computed(() =>
    ['queued', 'processing'].includes(batch.value.status),
);
const reading = computed(() =>
    batch.value.files.some(
        (file) => file.status === 'uploaded' || file.status === 'parsing',
    ),
);

function defaultStep(): Step {
    if (!isDraft.value) {
        return 'result';
    }

    if (batch.value.files.length === 0 || reading.value) {
        return 'files';
    }

    return 'classify';
}

const step = ref<Step>(defaultStep());

const steps = computed(() => {
    const all: { key: Step; label: string; hint?: string }[] = [
        { key: 'files', label: 'Archivos' },
        { key: 'mapping', label: 'Correspondencias' },
        {
            key: 'classify',
            label: 'Clasificar',
            hint:
                summary.value && summary.value.totals.undecided > 0
                    ? `${formatNumber(summary.value.totals.undecided)} sin decidir`
                    : undefined,
        },
        { key: 'summary', label: 'Resumen y confirmación' },
    ];

    return isDraft.value
        ? all
        : [
              { key: 'result' as Step, label: 'Resultado' },
              { key: 'classify' as Step, label: 'Detalle por conversación' },
              { key: 'files' as Step, label: 'Archivos' },
          ];
});

/**
 * A step shows a checkmark only when it is really done (not merely
 * before the current one): files read without errors, nothing left to
 * decide. Correspondences and the summary have no "done" state.
 */
function isStepDone(key: Step): boolean {
    if (!isDraft.value) {
        return false;
    }

    if (key === 'files') {
        return (
            batch.value.files.length > 0 &&
            batch.value.files.every((file) => file.status === 'parsed')
        );
    }

    if (key === 'classify') {
        return (
            !!summary.value &&
            summary.value.totals.conversations > 0 &&
            summary.value.totals.undecided === 0
        );
    }

    return false;
}

const reducedMotion = usePreferredReducedMotion();
const stepContent = ref<HTMLElement | null>(null);
const mappingDirty = ref(false);
const pendingStep = ref<Step | null>(null);
const leaveMappingOpen = computed({
    get: () => pendingStep.value !== null,
    set: (open: boolean) => {
        if (!open) {
            pendingStep.value = null;
        }
    },
});

function go(next: Step): void {
    if (step.value === 'mapping' && next !== 'mapping' && mappingDirty.value) {
        pendingStep.value = next;

        return;
    }

    show(next);
}

function leaveMapping(): void {
    const next = pendingStep.value;
    pendingStep.value = null;
    mappingDirty.value = false;

    if (next) {
        show(next);
    }
}

function show(next: Step): void {
    step.value = next;

    if (next === 'summary') {
        void refreshSummary();
    }

    window.scrollTo({
        top: 0,
        behavior: reducedMotion.value === 'reduce' ? 'auto' : 'smooth',
    });

    /* Keyboard and screen reader users land on the step they opened. */
    void nextTick(() => stepContent.value?.focus({ preventScroll: true }));
}

const currentStepLabel = computed(
    () => steps.value.find((item) => item.key === step.value)?.label ?? '',
);

/* Summary. */

const summaryLoading = ref(false);

async function refreshSummary(): Promise<void> {
    summaryLoading.value = true;

    try {
        const response = await historyRequest<{ summary: HistorySummary }>(
            'GET',
            summaryRoute.url(batch.value.id),
        );
        summary.value = response.summary;
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        summaryLoading.value = false;
    }
}

function onSummary(next: HistorySummary): void {
    summary.value = next;
}

/* Inventory filters (kept while moving between steps). */

const inventoryFilters = ref<HistoryFilters>({});
const inventoryRefresh = ref(0);

function onRulesChanged(next: HistorySummary): void {
    summary.value = next;
    inventoryRefresh.value += 1;
    router.reload({ only: ['rules'] });
}

function onMappingsSaved(rows: HistoryMappingRows, next: HistorySummary): void {
    mappings.value = rows;
    summary.value = next;
    inventoryRefresh.value += 1;
}

/* Progress: polled while files are read or the import runs. */

/**
 * Polls left while waiting for a background inventory rebuild (after the
 * participants of a conversation changed) to show up as «parsing».
 */
const rebuildWaitPolls = ref(0);
const polling = computed(
    () => reading.value || running.value || rebuildWaitPolls.value > 0,
);

function onRebuilding(): void {
    rebuildWaitPolls.value = 5;
    void poll();
}
const visibility = useDocumentVisibility();
const pollRequest = latestRequest();
let pollInFlight = false;

/**
 * One poll at a time: a slow answer is never overtaken by (or overwrites)
 * a newer one, so the bar never goes back and the end toasts fire once.
 */
async function poll(): Promise<void> {
    if (pollInFlight || visibility.value === 'hidden') {
        return;
    }

    pollInFlight = true;

    try {
        const response = await historyRequest<{ batch: HistoryBatch }>(
            'GET',
            progressRoute.url(batch.value.id),
            undefined,
            pollRequest.next(),
        );
        const wasReading = reading.value;
        const wasRunning = running.value;
        const waitingRebuild = rebuildWaitPolls.value > 0;
        batch.value = response.batch;

        if (reading.value) {
            rebuildWaitPolls.value = 0;
        } else if (waitingRebuild) {
            rebuildWaitPolls.value -= 1;

            /* The rebuild finished before it was ever seen running. */
            if (rebuildWaitPolls.value === 0) {
                router.reload({ only: ['summary', 'facets'] });
                inventoryRefresh.value += 1;
            }
        }

        if (wasReading && !reading.value) {
            router.reload({
                only: ['batch', 'summary', 'facets', 'mappings', 'rules'],
            });
            inventoryRefresh.value += 1;
        }

        if (wasRunning && !running.value) {
            router.reload({ only: ['batch', 'summary'] });
            inventoryRefresh.value += 1;

            if (response.batch.status === 'completed') {
                toast.success('La importación terminó.');
            } else if (response.batch.status === 'failed') {
                toast.error('La importación se detuvo. Puedes continuarla.');
            }
        }
    } catch {
        // A missed (or cancelled) poll is retried on the next tick.
    } finally {
        pollInFlight = false;
    }
}

const { pause, resume } = useIntervalFn(poll, 2000, { immediate: false });

watch(
    [polling, visibility],
    ([active, visible], previous) => {
        if (active && visible !== 'hidden') {
            resume();

            /* Back to the tab: catch up at once instead of waiting a tick. */
            if (previous && previous[1] === 'hidden') {
                void poll();
            }
        } else {
            pause();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    pause();
    pollRequest.cancel();
});

function onFilesBatch(next: HistoryBatch): void {
    batch.value = next;
}

function onExecuted(next: HistoryBatch, message: string): void {
    batch.value = next;
    toast.info(message);
    step.value = 'result';
    router.reload({ only: ['batch'] });
}

function onLocked(): void {
    router.reload();
}

/* Discard. */

const discardOpen = ref(false);
const discarding = ref(false);

function discard(): void {
    router.delete(destroyRoute.url(batch.value.id), {
        onStart: () => (discarding.value = true),
        onFinish: () => {
            discarding.value = false;
            discardOpen.value = false;
        },
    });
}

const title = computed(() => batch.value.label ?? `Lote ${batch.value.id}`);
</script>

<template>
    <Head :title="`${title} · Importar historial`" />

    <div class="page-shell">
        <PageHeader
            help="historial"
            :title="title"
            show-title
            :description="`Cuenta de Whaticket: ${batch.source_instance} · fechas en ${formatTimeZone(batch.timezone)} · creado ${formatDateTime(batch.created_at)}${batch.owner ? ` por ${batch.owner}` : ''}`"
        >
            <template #actions>
                <Badge
                    :variant="BATCH_STATUS_BADGE[batch.status]"
                    data-batch-status
                >
                    {{ batch.status_label }}
                </Badge>
                <Button v-if="!isDraft" variant="outline" as-child>
                    <a :href="reportRoute.url(batch.id)" download>
                        <FileDown />
                        Reporte CSV
                    </a>
                </Button>
                <Button
                    v-if="editable"
                    variant="ghost"
                    class="text-destructive hover:text-destructive"
                    @click="discardOpen = true"
                >
                    <Trash2 />
                    Descartar lote
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="importIndex()">
                        <ArrowLeft />
                        Lotes
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <StatusNote tone="warning" title="Formato aún no verificado">
            <p>{{ FORMAT_DISCLAIMER }}</p>
            <p class="mt-1">{{ formatNote }}</p>
        </StatusNote>

        <StatusNote v-if="isDraft && !can.classify" tone="info">
            Tu rol puede consultar este lote, pero no cambiarlo.
        </StatusNote>

        <nav
            aria-label="Pasos de la importación"
            data-tour="history-import.steps"
        >
            <ol
                class="grid gap-2"
                :class="
                    steps.length === 4
                        ? 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4'
                        : 'grid-cols-1 sm:grid-cols-3'
                "
            >
                <li v-for="(item, index) in steps" :key="item.key">
                    <button
                        type="button"
                        :aria-current="item.key === step ? 'step' : undefined"
                        :class="
                            cn(
                                'flex h-full w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11',
                                item.key === step
                                    ? 'border-primary bg-primary-soft text-primary-soft-foreground'
                                    : 'text-muted-foreground hover:bg-muted/40',
                            )
                        "
                        :data-step="item.key"
                        @click="go(item.key)"
                    >
                        <span
                            :class="
                                cn(
                                    'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums',
                                    item.key === step &&
                                        'border-primary bg-primary text-primary-foreground',
                                    item.key !== step &&
                                        isStepDone(item.key) &&
                                        'border-success bg-success-soft text-success-soft-foreground',
                                )
                            "
                        >
                            <template
                                v-if="item.key !== step && isStepDone(item.key)"
                            >
                                <Check class="size-3.5" aria-hidden="true" />
                                <span class="sr-only">(completo)</span>
                            </template>
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block leading-tight font-medium break-words"
                                >{{ item.label }}</span
                            >
                            <span
                                v-if="item.hint"
                                class="block text-xs"
                                data-undecided-hint
                            >
                                {{ item.hint }}
                            </span>
                        </span>
                    </button>
                </li>
            </ol>
        </nav>

        <h2 ref="stepContent" tabindex="-1" class="sr-only" data-step-heading>
            Paso: {{ currentStepLabel }}
        </h2>

        <HistoryResultStep
            v-if="step === 'result'"
            :batch="batch"
            :can-execute="can.execute"
            :can-archive="can.archive"
            @batch="onFilesBatch"
        />

        <HistoryFilesStep
            v-else-if="step === 'files'"
            :batch="batch"
            :kinds="options.kinds"
            :max-kilobytes="limits.max_kilobytes"
            :editable="editable"
            @batch="onFilesBatch"
            @rebuilding="onRebuilding"
        />

        <HistoryMappingStep
            v-else-if="step === 'mapping'"
            v-model:dirty="mappingDirty"
            :batch-id="batch.id"
            :mappings="mappings"
            :options="options"
            :editable="editable"
            @saved="onMappingsSaved"
        />

        <div v-else-if="step === 'classify'" class="grid gap-6">
            <StatusNote
                v-if="isDraft && (reading || rebuildWaitPolls > 0)"
                tone="info"
                data-inventory-rebuilding
            >
                <span class="inline-flex items-center gap-2">
                    <Spinner class="size-4" aria-hidden="true" />
                    Estamos actualizando el inventario. Las cifras y la lista se
                    refrescan solas en unos segundos.
                </span>
            </StatusNote>
            <StatusNote
                v-if="isDraft && summary"
                :tone="summary.totals.undecided > 0 ? 'warning' : 'success'"
                data-classify-status
            >
                {{ formatNumber(summary.totals.import) }} para importar ·
                {{ formatNumber(summary.totals.archive) }} para archivar ·
                {{ formatNumber(summary.totals.exclude) }} sin importar ·
                <strong
                    >{{ formatNumber(summary.totals.undecided) }} sin
                    decidir</strong
                >
                <template #actions>
                    <Button
                        size="sm"
                        variant="outline"
                        class="pointer-coarse:h-10"
                        @click="go('summary')"
                    >
                        Ver resumen
                    </Button>
                </template>
            </StatusNote>
            <HistoryInventory
                v-model:filters="inventoryFilters"
                :batch-id="batch.id"
                :facets="facets"
                :editable="editable"
                :per-page="limits.per_page"
                :refresh-key="inventoryRefresh"
                @summary="onSummary"
                @rebuilding="onRebuilding"
            />
            <HistoryRulesPanel
                v-if="isDraft"
                :batch-id="batch.id"
                :rules="rules"
                :facets="facets"
                :editable="editable"
                :current-filters="inventoryFilters"
                @changed="onRulesChanged"
            />
        </div>

        <HistorySummaryStep
            v-else-if="step === 'summary'"
            :batch-id="batch.id"
            :summary="summary"
            :loading="summaryLoading"
            :can-execute="can.execute"
            @refresh="refreshSummary"
            @executed="onExecuted"
            @locked="onLocked"
            @go="go"
        />

        <ConfirmDialog
            v-model:open="discardOpen"
            title="¿Descartar este lote?"
            description="Se eliminan del sistema los archivos que subiste y las decisiones que tomaste en este lote. No se importa ninguna conversación y no se borra nada en Whaticket. No se puede deshacer."
            confirm-label="Descartar lote"
            destructive
            :processing="discarding"
            @confirm="discard"
        />

        <ConfirmDialog
            v-model:open="leaveMappingOpen"
            title="¿Salir sin guardar las correspondencias?"
            description="Cambiaste correspondencias que aún no guardas. Si sales de este paso, esos cambios se pierden."
            confirm-label="Salir sin guardar"
            destructive
            @confirm="leaveMapping"
        />
    </div>
</template>
