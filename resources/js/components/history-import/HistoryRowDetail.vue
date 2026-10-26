<script setup lang="ts">
import { FileWarning, Paperclip, Save } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatFileSize } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import {
    show as rowShow,
    update as rowUpdate,
} from '@/routes/admin/history-import/inventory';
import type {
    HistoryInventoryRow,
    HistoryPreviewMessage,
    HistorySummary,
} from '@/types/history-import';
import { errorMessage, historyRequest, isAborted, latestRequest } from './api';
import {
    DECISION_SOURCES,
    DECISIONS,
    KIND_LABELS,
    ROW_STATES,
    decisionLabel,
    formatNumber,
    issueMessage,
} from './labels';

/**
 * One conversation of the inventory: its Whaticket data, a preview of its
 * first messages (only for the administrator deciding, never stored
 * elsewhere), which participants of a Chat.txt are the company, and
 * whether its contact is saved to the directory.
 */
const props = defineProps<{
    batchId: number;
    row: HistoryInventoryRow | null;
    editable: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    updated: [row: HistoryInventoryRow, summary: HistorySummary];
    /** The participants changed: the server rebuilds the inventory in the background. */
    rebuilding: [];
}>();

const preview = ref<HistoryPreviewMessage[]>([]);
const previewLimit = ref(20);
const loading = ref(false);
const loadError = ref<string | null>(null);
const businessDraft = ref<string[]>([]);
const saveContactDraft = ref(false);
const saving = ref(false);
const saveError = ref<string | null>(null);

/* Only the preview of the row on screen: opening another row aborts the previous load. */
const previewRequest = latestRequest();

async function load(row: HistoryInventoryRow): Promise<void> {
    const signal = previewRequest.next();
    loading.value = true;
    loadError.value = null;
    preview.value = [];

    try {
        const response = await historyRequest<{
            conversation: HistoryInventoryRow;
            preview: HistoryPreviewMessage[];
            preview_limit: number;
        }>(
            'GET',
            rowShow.url({ transfer: props.batchId, row: row.id }),
            undefined,
            signal,
        );

        if (props.row?.id !== row.id) {
            return;
        }

        preview.value = response.preview;
        previewLimit.value = response.preview_limit;
    } catch (error) {
        if (isAborted(error)) {
            return;
        }

        loadError.value = errorMessage(error);
    } finally {
        if (!signal.aborted) {
            loading.value = false;
        }
    }
}

onBeforeUnmount(() => previewRequest.cancel());

watch(
    () => [open.value, props.row?.id] as const,
    ([isOpen]) => {
        if (isOpen && props.row) {
            businessDraft.value = [...props.row.business_participants];
            saveContactDraft.value = props.row.save_contact;
            saveError.value = null;
            void load(props.row);
        } else {
            previewRequest.cancel();
        }
    },
    { immediate: true },
);

const needsParticipants = computed(
    () =>
        props.row !== null &&
        props.row.participants.length > 0 &&
        (props.row.kind === 'chat_txt' ||
            props.row.issues.some(
                (issue) => issue.code === 'participants_unidentified',
            )),
);

const dirty = computed(() => {
    if (!props.row) {
        return false;
    }

    return (
        saveContactDraft.value !== props.row.save_contact ||
        JSON.stringify([...businessDraft.value].sort()) !==
            JSON.stringify([...props.row.business_participants].sort())
    );
});

function toggleParticipant(
    name: string,
    checked: boolean | 'indeterminate',
): void {
    businessDraft.value =
        checked === true
            ? [...new Set([...businessDraft.value, name])]
            : businessDraft.value.filter((value) => value !== name);
}

async function save(): Promise<void> {
    if (!props.row) {
        return;
    }

    saving.value = true;
    saveError.value = null;

    const body: Record<string, unknown> = {};

    if (saveContactDraft.value !== props.row.save_contact) {
        body.save_contact = saveContactDraft.value;
    }

    if (needsParticipants.value) {
        body.business_participants = businessDraft.value;
    }

    try {
        const response = await historyRequest<{
            conversation: HistoryInventoryRow;
            summary: HistorySummary;
        }>(
            'PATCH',
            rowUpdate.url({ transfer: props.batchId, row: props.row.id }),
            body,
        );
        emit('updated', response.conversation, response.summary);

        if (body.business_participants !== undefined) {
            emit('rebuilding');
            toast.success(
                'Cambios guardados. El inventario se está actualizando; en unos segundos verás el resultado.',
            );
        } else {
            toast.success('Cambios guardados.');
        }
        void load(response.conversation);
    } catch (error) {
        saveError.value = errorMessage(error);
    } finally {
        saving.value = false;
    }
}

const sourceDetails = computed(() =>
    Object.entries(props.row?.source.details ?? {}).filter(
        ([, value]) => typeof value === 'string' && value !== '',
    ),
);

const DETAIL_LABELS: Record<string, string> = {
    conversation_id: 'Id de conversación en Whaticket',
    chat_id: 'Id de chat en Whaticket',
    chat_link: 'Enlace en Whaticket',
    number: 'Número en Whaticket',
};

function displayPhone(phone: string | null): string | null {
    return phone ? (phone.startsWith('+') ? formatPhone(phone) : phone) : null;
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-xl">
            <template v-if="row">
                <SheetHeader class="border-b p-5">
                    <SheetTitle class="wrap-anywhere">
                        {{
                            row.contact.name ??
                            displayPhone(row.contact.phone) ??
                            row.contact.identifier ??
                            'Sin nombre'
                        }}
                    </SheetTitle>
                    <SheetDescription>
                        {{ KIND_LABELS[row.kind] ?? row.kind }}
                        <template v-if="row.ticket_id">
                            {{ row.ticket_id }}</template
                        >
                        · {{ decisionLabel(row.decision) }}
                        <template v-if="row.decision_source">
                            ({{
                                DECISION_SOURCES[row.decision_source] ??
                                row.decision_source
                            }})
                        </template>
                    </SheetDescription>
                </SheetHeader>

                <div class="flex-1 space-y-5 overflow-y-auto p-5 text-sm">
                    <StatusNote
                        v-if="row.recommendation"
                        tone="info"
                        title="Sugerencia (tú decides)"
                    >
                        {{ DECISIONS[row.recommendation.decision]?.label }}:
                        {{ row.recommendation.reason }}
                    </StatusNote>

                    <ul v-if="row.issues.length > 0" class="grid gap-2">
                        <li v-for="issue in row.issues" :key="issue.code">
                            <StatusNote
                                :tone="
                                    issue.severity === 'error'
                                        ? 'danger'
                                        : 'warning'
                                "
                            >
                                {{ issueMessage(issue) }}
                            </StatusNote>
                        </li>
                    </ul>

                    <dl
                        class="grid grid-cols-[9rem_minmax(0,1fr)] gap-x-4 gap-y-2"
                    >
                        <dt class="text-muted-foreground">Número</dt>
                        <dd class="wrap-anywhere">
                            {{
                                displayPhone(row.contact.phone) ??
                                row.contact.identifier ??
                                '—'
                            }}
                        </dd>
                        <dt class="text-muted-foreground">Primer mensaje</dt>
                        <dd>{{ formatDateTime(row.first_message_at) }}</dd>
                        <dt class="text-muted-foreground">Último mensaje</dt>
                        <dd>{{ formatDateTime(row.last_message_at) }}</dd>
                        <dt class="text-muted-foreground">Mensajes</dt>
                        <dd class="tabular-nums">
                            {{ formatNumber(row.messages.total) }}
                            ({{ formatNumber(row.messages.inbound) }} del
                            cliente,
                            {{ formatNumber(row.messages.outbound) }} de la
                            empresa)
                        </dd>
                        <dt class="text-muted-foreground">Archivos</dt>
                        <dd class="tabular-nums">
                            {{ formatNumber(row.attachments.available) }}
                            disponibles,
                            {{ formatNumber(row.attachments.missing) }}
                            faltantes
                            <template v-if="row.attachments.bytes > 0">
                                · {{ formatFileSize(row.attachments.bytes) }}
                            </template>
                        </dd>
                        <dt class="text-muted-foreground">Agente anterior</dt>
                        <dd>{{ row.source.agent ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Departamento</dt>
                        <dd>{{ row.source.department ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Conexión</dt>
                        <dd>{{ row.source.connection ?? '—' }}</dd>
                        <dt class="text-muted-foreground">
                            Estado en Whaticket
                        </dt>
                        <dd>{{ row.source.status ?? '—' }}</dd>
                        <dt class="text-muted-foreground">Etiquetas</dt>
                        <dd>
                            {{
                                row.source.tags.length > 0
                                    ? row.source.tags.join(', ')
                                    : '—'
                            }}
                        </dd>
                        <template v-if="row.state !== 'pending'">
                            <dt class="text-muted-foreground">Resultado</dt>
                            <dd>
                                <Badge :variant="ROW_STATES[row.state].badge">
                                    {{ ROW_STATES[row.state].label }}
                                </Badge>
                                <span
                                    v-if="row.result.error"
                                    class="mt-1 block text-destructive"
                                >
                                    {{ row.result.error }}
                                </span>
                            </dd>
                        </template>
                        <template
                            v-for="[key, value] in sourceDetails"
                            :key="key"
                        >
                            <dt class="text-muted-foreground">
                                {{ DETAIL_LABELS[key] ?? key }}
                            </dt>
                            <dd class="wrap-anywhere">{{ value }}</dd>
                        </template>
                    </dl>

                    <fieldset
                        v-if="needsParticipants"
                        class="grid gap-2 rounded-lg border p-3"
                        data-tour="history-import.participants"
                    >
                        <legend class="px-1 text-sm font-semibold">
                            ¿Quién es la empresa?
                        </legend>
                        <p class="text-xs text-muted-foreground">
                            Un Chat.txt no indica quién escribió por la empresa.
                            Marca los participantes que son la empresa; sus
                            mensajes se mostrarán como enviados.
                        </p>
                        <label
                            v-for="participant in row.participants"
                            :key="participant"
                            class="flex min-h-10 cursor-pointer items-center gap-3 rounded-md px-2 hover:bg-muted/40"
                        >
                            <Checkbox
                                :model-value="
                                    businessDraft.includes(participant)
                                "
                                :disabled="!editable"
                                @update:model-value="
                                    toggleParticipant(participant, $event)
                                "
                            />
                            <span class="wrap-anywhere">{{ participant }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{
                                    businessDraft.includes(participant)
                                        ? 'es la empresa'
                                        : 'cliente u otro'
                                }}
                            </span>
                        </label>
                    </fieldset>

                    <label
                        v-if="!row.is_group"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                    >
                        <Checkbox
                            v-model="saveContactDraft"
                            :disabled="!editable"
                            class="mt-0.5"
                        />
                        <span>
                            <span class="block font-medium">
                                Guardar el contacto en el directorio
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                Si no lo marcas, el contacto solo existe para
                                esta conversación y no aparece en Contactos.
                            </span>
                        </span>
                    </label>

                    <div v-if="editable" class="flex flex-col items-end gap-2">
                        <InputError :message="saveError ?? undefined" />
                        <Button
                            size="sm"
                            class="pointer-coarse:h-10"
                            :disabled="saving || !dirty"
                            @click="save"
                        >
                            <Spinner v-if="saving" />
                            <Save v-else />
                            Guardar cambios
                        </Button>
                    </div>

                    <section
                        class="grid gap-2"
                        aria-labelledby="row-preview-title"
                    >
                        <h3
                            id="row-preview-title"
                            class="text-sm font-semibold"
                        >
                            Primeros mensajes
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            Vista previa de hasta {{ previewLimit }} mensajes,
                            solo para decidir.
                        </p>
                        <div v-if="loading" class="grid gap-2" aria-busy="true">
                            <Skeleton
                                v-for="n in 4"
                                :key="n"
                                class="h-12 w-full"
                            />
                        </div>
                        <StatusNote v-else-if="loadError" tone="danger">
                            {{ loadError }}
                        </StatusNote>
                        <p
                            v-else-if="preview.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            Esta conversación no tiene mensajes en los archivos.
                        </p>
                        <ol v-else class="grid gap-2">
                            <li
                                v-for="message in preview"
                                :key="message.id"
                                :class="[
                                    'max-w-[85%] rounded-lg px-3 py-2',
                                    message.from_me
                                        ? 'justify-self-end bg-primary-soft text-primary-soft-foreground'
                                        : 'justify-self-start bg-muted',
                                ]"
                            >
                                <p class="text-xs font-medium opacity-80">
                                    {{
                                        message.sender ??
                                        (message.from_me
                                            ? 'Empresa'
                                            : 'Cliente')
                                    }}
                                    · {{ formatDateTime(message.at) }}
                                </p>
                                <p
                                    v-if="message.body"
                                    class="wrap-anywhere whitespace-pre-line"
                                >
                                    {{ message.body }}
                                </p>
                                <p
                                    v-if="message.file"
                                    class="mt-1 flex items-center gap-1 text-xs"
                                >
                                    <Paperclip
                                        v-if="message.file.available"
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <FileWarning
                                        v-else
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <span class="wrap-anywhere">{{
                                        message.file.name
                                    }}</span>
                                    <span v-if="!message.file.available">
                                        · no está en el ZIP
                                    </span>
                                </p>
                            </li>
                        </ol>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
