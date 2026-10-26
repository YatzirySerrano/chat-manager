<script setup lang="ts">
import {
    Archive,
    Ban,
    ContactRound,
    Copy,
    Download,
    FileWarning,
    HardDrive,
    MessagesSquare,
    Paperclip,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import StatCard from '@/components/StatCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { formatFileSize } from '@/lib/format';
import { execute as executeRoute } from '@/routes/admin/history-import';
import type {
    HistoryBatch,
    HistorySummary,
    HistorySummaryBlocker,
} from '@/types/history-import';
import { errorMessage, historyRequest, HistoryRequestError } from './api';
import { DECISION_ORDER, DECISIONS, formatNumber, plural } from './labels';

/**
 * Step 4: everything the confirmation will do, with the server's numbers,
 * the consequences of each destination in plain words and an explicit
 * confirmation. The summary carries a token: if anything changed since it
 * was shown (another tab, a rule), the server answers «summary_changed»
 * and the summary is shown again before confirming.
 */
const props = defineProps<{
    batchId: number;
    summary: HistorySummary | null;
    loading: boolean;
    canExecute: boolean;
}>();

const emit = defineEmits<{
    refresh: [];
    executed: [batch: HistoryBatch, message: string];
    locked: [];
    go: [step: 'files' | 'mapping' | 'classify'];
}>();

const reviewed = ref(false);
const executing = ref(false);
const error = ref<string | null>(null);
const changedNotice = ref<string | null>(null);
const changedTitle = ref('El resumen cambió');

watch(
    () => props.summary?.confirmation_token,
    (token, previous) => {
        if (previous !== undefined && token !== previous) {
            reviewed.value = false;
        }
    },
);

const BLOCKER_STEP: Partial<
    Record<
        HistorySummaryBlocker['code'],
        { step: 'files' | 'mapping' | 'classify'; action: string }
    >
> = {
    empty_batch: { step: 'files', action: 'Subir archivos' },
    files_pending: { step: 'files', action: 'Ver archivos' },
    undecided: { step: 'classify', action: 'Ir a clasificar' },
    blocking_issues: { step: 'classify', action: 'Revisar conversaciones' },
    unmapped_connections: { step: 'mapping', action: 'Elegir conexiones' },
};

const DESTINATION_ICONS = { import: Download, archive: Archive, exclude: Ban };

const messagesFor = computed(() => ({
    import: props.summary?.messages.import ?? 0,
    archive: props.summary?.messages.archive ?? 0,
    exclude: null,
}));

async function confirm(): Promise<void> {
    if (!props.summary) {
        return;
    }

    executing.value = true;
    error.value = null;
    changedNotice.value = null;

    try {
        const response = await historyRequest<{
            batch: HistoryBatch;
            message: string;
        }>('POST', executeRoute.url(props.batchId), {
            confirmation_token: props.summary.confirmation_token,
            confirm: true,
        });
        emit('executed', response.batch, response.message);
    } catch (failure) {
        if (
            failure instanceof HistoryRequestError &&
            failure.code === 'summary_changed'
        ) {
            changedTitle.value = 'El resumen cambió';
            changedNotice.value = failure.message;
            reviewed.value = false;
            emit('refresh');
        } else if (
            failure instanceof HistoryRequestError &&
            failure.code === 'batch_locked'
        ) {
            error.value = failure.message;
            emit('locked');
        } else if (
            failure instanceof HistoryRequestError &&
            failure.code === 'batch_busy'
        ) {
            changedTitle.value = 'El inventario se está actualizando';
            changedNotice.value =
                'Estamos actualizando el inventario; inténtalo en unos segundos.';
            reviewed.value = false;
            emit('refresh');
        } else {
            error.value = errorMessage(failure);
        }
    } finally {
        executing.value = false;
    }
}
</script>

<template>
    <div class="grid gap-6" data-tour="history-import.summary">
        <div v-if="!summary" class="grid gap-3" aria-busy="true">
            <StatCard
                v-for="n in 4"
                :key="n"
                label="Cargando"
                :value="null"
                loading
            />
        </div>

        <template v-else>
            <StatusNote
                v-if="changedNotice"
                tone="warning"
                :title="changedTitle"
                live="assertive"
            >
                {{ changedNotice }}
            </StatusNote>

            <section aria-labelledby="summary-destinations" class="grid gap-3">
                <h2 id="summary-destinations" class="text-base font-semibold">
                    Qué pasará con las
                    {{
                        plural(
                            summary.totals.conversations,
                            'conversación',
                            'conversaciones',
                        )
                    }}
                    <Spinner
                        v-if="loading"
                        class="ml-1 inline size-4"
                        aria-label="Actualizando"
                    />
                </h2>
                <div class="grid gap-3 md:grid-cols-3">
                    <article
                        v-for="decision in DECISION_ORDER"
                        :key="decision"
                        class="grid content-start gap-2 rounded-xl border bg-card p-4 shadow-card"
                        :data-summary-destination="decision"
                    >
                        <p
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <component
                                :is="DESTINATION_ICONS[decision]"
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ DECISIONS[decision].label }}
                        </p>
                        <p
                            class="text-2xl font-semibold tabular-nums"
                            data-summary-count
                        >
                            {{ formatNumber(summary.totals[decision]) }}
                        </p>
                        <p
                            v-if="messagesFor[decision] !== null"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            {{ plural(messagesFor[decision] ?? 0, 'mensaje') }}
                            estimados
                        </p>
                        <ul class="grid gap-1 text-sm text-muted-foreground">
                            <li
                                v-for="consequence in DECISIONS[decision]
                                    .consequences"
                                :key="consequence"
                                class="flex gap-2"
                            >
                                <span aria-hidden="true">·</span>
                                <span>{{ consequence }}</span>
                            </li>
                        </ul>
                    </article>
                </div>
                <p
                    class="text-sm"
                    :class="
                        summary.totals.undecided > 0
                            ? 'font-medium text-warning'
                            : 'text-muted-foreground'
                    "
                    data-summary-undecided
                >
                    Sin decidir: {{ formatNumber(summary.totals.undecided) }}
                </p>
            </section>

            <section aria-labelledby="summary-details" class="grid gap-3">
                <h2 id="summary-details" class="text-base font-semibold">
                    Detalle
                </h2>
                <div
                    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <StatCard
                        label="Contactos involucrados"
                        :value="formatNumber(summary.contacts.involved)"
                        :icon="ContactRound"
                        :hint="`${formatNumber(summary.contacts.existing)} ya existen · ${formatNumber(summary.contacts.new)} nuevos`"
                    />
                    <StatCard
                        label="Contactos que se guardarán en el directorio"
                        :value="formatNumber(summary.contacts.to_save)"
                        :icon="ContactRound"
                        hint="Los demás solo existen para su conversación."
                    />
                    <StatCard
                        label="Mensajes estimados"
                        :value="formatNumber(summary.messages.total)"
                        :icon="MessagesSquare"
                    />
                    <StatCard
                        label="Archivos recuperables"
                        :value="formatNumber(summary.attachments.recoverable)"
                        :icon="Paperclip"
                        :hint="`Tamaño estimado: ${formatFileSize(summary.attachments.estimated_bytes)}`"
                    />
                    <StatCard
                        label="Archivos faltantes"
                        :value="formatNumber(summary.attachments.missing)"
                        :icon="FileWarning"
                        hint="Se mostrarán como «Archivo histórico no disponible»."
                    />
                    <StatCard
                        label="Posibles duplicados"
                        :value="formatNumber(summary.duplicates)"
                        :icon="Copy"
                        hint="Ya importadas antes: no se repiten."
                    />
                    <StatCard
                        label="Problemas"
                        :value="formatNumber(summary.issues.blocking)"
                        :icon="TriangleAlert"
                        :hint="`${formatNumber(summary.issues.warnings)} avisos`"
                    />
                    <StatCard
                        label="Espacio estimado de archivos"
                        :value="
                            formatFileSize(summary.attachments.estimated_bytes)
                        "
                        :icon="HardDrive"
                    />
                </div>
            </section>

            <SurfaceCard
                v-if="summary.blockers.length > 0"
                title="Antes de confirmar"
                description="Resuelve esto para poder confirmar la importación."
                body-class="grid gap-2 p-4 sm:p-5"
            >
                <StatusNote
                    v-for="blocker in summary.blockers"
                    :key="blocker.code"
                    tone="warning"
                    :data-blocker="blocker.code"
                >
                    {{ blocker.message }}
                    <template v-if="blocker.code === 'storage_unavailable'">
                        El almacenamiento de archivos no está disponible por
                        ahora, así que este lote todavía no se puede ejecutar.
                        Tus decisiones se conservan; pide a un administrador del
                        sistema que revise el almacenamiento y vuelve a revisar
                        el resumen.
                    </template>
                    <template
                        v-if="
                            blocker.code === 'unmapped_connections' &&
                            summary.unmapped_connections.length > 0
                        "
                    >
                        ({{
                            summary.unmapped_connections
                                .map((source) =>
                                    source === ''
                                        ? 'sin conexión indicada'
                                        : source,
                                )
                                .join(', ')
                        }})
                    </template>
                    <template #actions>
                        <Button
                            v-if="BLOCKER_STEP[blocker.code]"
                            size="sm"
                            variant="outline"
                            class="pointer-coarse:h-11"
                            @click="
                                emit('go', BLOCKER_STEP[blocker.code]!.step)
                            "
                        >
                            {{ BLOCKER_STEP[blocker.code]!.action }}
                        </Button>
                        <Button
                            v-else-if="blocker.code === 'storage_unavailable'"
                            size="sm"
                            variant="outline"
                            class="pointer-coarse:h-11"
                            :disabled="loading"
                            @click="emit('refresh')"
                        >
                            Volver a revisar
                        </Button>
                    </template>
                </StatusNote>
            </SurfaceCard>

            <SurfaceCard
                v-if="canExecute"
                title="Confirmar la importación"
                body-class="grid gap-4 p-4 sm:p-5"
                data-tour="history-import.confirm"
            >
                <ul class="grid gap-1 text-sm text-muted-foreground">
                    <li>
                        Nada se envía a los clientes ni se notifica a los
                        agentes.
                    </li>
                    <li>No se borra nada en Whaticket.</li>
                    <li>
                        Puedes seguir trabajando mientras se importa; el avance
                        aparecerá aquí.
                    </li>
                </ul>
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-[[data-state=checked]]:border-primary/50"
                >
                    <Checkbox
                        v-model="reviewed"
                        class="mt-0.5"
                        :disabled="!summary.ready"
                        data-confirm-reviewed
                    />
                    <span>
                        Revisé el resumen: se importarán
                        {{ formatNumber(summary.totals.import) }}, se archivarán
                        {{ formatNumber(summary.totals.archive) }} y no se
                        importarán {{ formatNumber(summary.totals.exclude) }}
                        conversaciones.
                    </span>
                </label>
                <InputError :message="error ?? undefined" />
                <div class="flex justify-end">
                    <Button
                        class="pointer-coarse:h-11"
                        :disabled="
                            !summary.ready || !reviewed || executing || loading
                        "
                        @click="confirm"
                    >
                        <Spinner v-if="executing" />
                        <ShieldCheck v-else />
                        Confirmar e importar
                    </Button>
                </div>
            </SurfaceCard>
            <StatusNote v-else tone="info">
                Tu rol puede revisar el resumen, pero no confirmar la
                importación.
            </StatusNote>
        </template>
    </div>
</template>
