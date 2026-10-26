<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    FileArchive,
    FileSpreadsheet,
    FileText,
    Pencil,
    RotateCw,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatFileSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    destroy as fileDestroy,
    mapping as fileMappingRoute,
    retry as fileRetry,
    store as fileStore,
} from '@/routes/admin/history-import/files';
import type {
    HistoryBatch,
    HistoryBatchFile,
    HistoryFileKind,
    HistoryOptions,
} from '@/types/history-import';
import { errorMessage, historyRequest } from './api';
import { FILE_STATUS_BADGE, formatNumber } from './labels';

/**
 * Step 1: upload the authorized Whaticket files and check how each one was
 * read (detected format, headers, columns not recognized, required fields
 * missing, rejected rows). The column mapping of each file can be
 * corrected; the file is read again with it. Nothing is imported here.
 */
const props = defineProps<{
    batch: HistoryBatch;
    kinds: HistoryOptions['kinds'];
    maxKilobytes: number;
    editable: boolean;
}>();

const emit = defineEmits<{
    batch: [batch: HistoryBatch];
    /** A file was removed: the server rebuilds the inventory. */
    rebuilding: [];
}>();

const KIND_HINTS: Record<HistoryFileKind, string> = {
    chats: 'El reporte de chats exportado de Whaticket, con agente, departamento, conexión y etiquetas de cada ticket.',
    messages:
        'Los mensajes de cada ticket: quién escribió, texto, archivo y fecha.',
    contacts:
        'La lista de contactos con nombre y número (sirve para completar nombres).',
    media: 'Whaticket-medias.zip con los archivos de los mensajes, o un Chat.txt exportado de WhatsApp (suelto o dentro del ZIP).',
};

const KIND_ICONS = {
    chats: FileSpreadsheet,
    messages: FileText,
    contacts: FileSpreadsheet,
    media: FileArchive,
} as const;

const upload = useForm<{ kind: HistoryFileKind; file: File | null }>({
    kind: props.kinds[0]?.value ?? 'chats',
    file: null,
});

const fileInput = ref<HTMLInputElement | null>(null);

const selectedKind = computed(() =>
    props.kinds.find((kind) => kind.value === upload.kind),
);

const accept = computed(() =>
    (selectedKind.value?.extensions ?? []).map((ext) => `.${ext}`).join(','),
);

const maxMegabytes = computed(() => Math.round(props.maxKilobytes / 1024));

const clientError = ref<string | null>(null);

function pickFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    upload.clearErrors();
    clientError.value = null;
    upload.file = file;

    if (!file) {
        return;
    }

    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (!(selectedKind.value?.extensions ?? []).includes(extension)) {
        clientError.value = `Para «${selectedKind.value?.label ?? 'este archivo'}» solo se aceptan archivos ${(selectedKind.value?.extensions ?? []).map((ext) => `.${ext}`).join(' o ')}.`;
        upload.file = null;
        input.value = '';

        return;
    }

    if (file.size > props.maxKilobytes * 1024) {
        clientError.value = `El archivo pesa ${formatFileSize(file.size)}; el máximo es ${maxMegabytes.value} MB. Exporta el periodo en partes.`;
        upload.file = null;
        input.value = '';
    }
}

watch(
    () => upload.kind,
    () => {
        upload.file = null;
        clientError.value = null;
        upload.clearErrors();

        if (fileInput.value) {
            fileInput.value.value = '';
        }
    },
);

function submit(): void {
    if (!upload.file) {
        clientError.value = 'Selecciona el archivo que quieres subir.';

        return;
    }

    upload.post(fileStore.url(props.batch.id), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            upload.reset('file');

            if (fileInput.value) {
                fileInput.value.value = '';
            }
        },
    });
}

const uploadError = computed(
    () =>
        clientError.value ??
        upload.errors.file ??
        upload.errors.kind ??
        (upload.errors as Record<string, string | undefined>).batch ??
        null,
);

/* Column mapping of one file. */

const NONE = '__none';
const mappingFile = ref<HistoryBatchFile | null>(null);
const mappingDraft = ref<Record<string, string>>({});
const mappingOpen = ref(false);
const mappingSaving = ref(false);
const mappingError = ref<string | null>(null);

function openMapping(file: HistoryBatchFile): void {
    mappingFile.value = file;
    mappingDraft.value = Object.fromEntries(
        file.fields.map((field) => {
            const column = file.mapping[field.value];

            return [
                field.value,
                column === null || column === undefined ? NONE : String(column),
            ];
        }),
    );
    mappingError.value = null;
    mappingOpen.value = true;
}

async function saveMapping(): Promise<void> {
    const file = mappingFile.value;

    if (!file) {
        return;
    }

    mappingSaving.value = true;
    mappingError.value = null;

    try {
        const mapping: Record<string, number | null> = {};

        for (const [field, column] of Object.entries(mappingDraft.value)) {
            mapping[field] = column === NONE ? null : Number(column);
        }

        const response = await historyRequest<{ batch: HistoryBatch }>(
            'PUT',
            fileMappingRoute.url({ transfer: props.batch.id, file: file.id }),
            { mapping },
        );
        emit('batch', response.batch);
        mappingOpen.value = false;
        toast.success(
            'Columnas guardadas. El archivo se está leyendo de nuevo.',
        );
    } catch (error) {
        mappingError.value = errorMessage(error);
    } finally {
        mappingSaving.value = false;
    }
}

/* Remove a file uploaded by mistake (only while the batch is a draft). */

const removingFile = ref<HistoryBatchFile | null>(null);
const removeOpen = computed({
    get: () => removingFile.value !== null,
    set: (open: boolean) => {
        if (!open && !removeProcessing.value) {
            removingFile.value = null;
        }
    },
});
const removeProcessing = ref(false);

async function removeFile(): Promise<void> {
    const file = removingFile.value;

    if (!file) {
        return;
    }

    removeProcessing.value = true;

    try {
        const response = await historyRequest<{ batch: HistoryBatch }>(
            'DELETE',
            fileDestroy.url({ transfer: props.batch.id, file: file.id }),
        );
        emit('batch', response.batch);
        emit('rebuilding');
        toast.success(
            `Se quitó «${file.name}». El inventario se está actualizando.`,
        );
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        removeProcessing.value = false;
        removingFile.value = null;
    }
}

/* Read a failed file again. */

const retryingFile = ref<number | null>(null);

async function retryFile(file: HistoryBatchFile): Promise<void> {
    retryingFile.value = file.id;

    try {
        const response = await historyRequest<{ batch: HistoryBatch }>(
            'POST',
            fileRetry.url({ transfer: props.batch.id, file: file.id }),
        );
        emit('batch', response.batch);
        toast.info(`«${file.name}» se está leyendo de nuevo.`);
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        retryingFile.value = null;
    }
}

function fieldLabel(file: HistoryBatchFile, value: string): string {
    return file.fields.find((field) => field.value === value)?.label ?? value;
}

function isReading(file: HistoryBatchFile): boolean {
    return file.status === 'uploaded' || file.status === 'parsing';
}

function hasColumns(file: HistoryBatchFile): boolean {
    return file.headers.length > 0 && file.fields.length > 0;
}
</script>

<template>
    <div class="grid gap-6">
        <SurfaceCard
            v-if="editable"
            title="Sube los archivos de Whaticket"
            description="Puedes subir varios archivos de cada tipo. Al subirlos solo se leen: nada se importa todavía. Si subiste uno equivocado, quítalo con «Quitar archivo»."
            body-class="p-4 sm:p-5"
            data-tour="history-import.upload"
        >
            <form class="grid gap-4" @submit.prevent="submit">
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        ¿Qué archivo vas a subir?
                    </legend>
                    <div class="grid gap-2 md:grid-cols-2">
                        <label
                            v-for="kind in kinds"
                            :key="kind.value"
                            :class="
                                cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors focus-within:ring-2 focus-within:ring-ring',
                                    upload.kind === kind.value
                                        ? 'border-primary bg-primary-soft/60'
                                        : 'hover:bg-muted/40',
                                )
                            "
                        >
                            <input
                                v-model="upload.kind"
                                type="radio"
                                name="history-file-kind"
                                :value="kind.value"
                                class="mt-0.5 accent-primary"
                            />
                            <component
                                :is="KIND_ICONS[kind.value]"
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 space-y-0.5">
                                <span class="block font-medium">
                                    {{ kind.label }}
                                </span>
                                <span
                                    class="block text-xs text-pretty text-muted-foreground"
                                >
                                    {{ KIND_HINTS[kind.value] }}
                                    Acepta
                                    {{
                                        kind.extensions
                                            .map((ext) => `.${ext}`)
                                            .join(' o ')
                                    }}.
                                </span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                <label
                    for="history-file"
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center transition-colors focus-within:border-primary hover:border-primary hover:bg-muted/40"
                >
                    <span
                        class="flex size-11 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                    >
                        <Upload class="size-5" aria-hidden="true" />
                    </span>
                    <span class="text-sm font-medium wrap-anywhere">
                        {{
                            upload.file
                                ? upload.file.name
                                : `Selecciona el archivo (${accept.replaceAll(',', ' o ')})`
                        }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        Máximo {{ maxMegabytes }} MB por archivo.
                    </span>
                    <input
                        id="history-file"
                        ref="fileInput"
                        type="file"
                        :accept="accept"
                        class="sr-only"
                        :aria-invalid="Boolean(uploadError) || undefined"
                        aria-describedby="history-file-error"
                        @change="pickFile"
                    />
                </label>
                <InputError
                    id="history-file-error"
                    :message="uploadError ?? undefined"
                />

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <span
                        v-if="upload.progress"
                        class="text-xs text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        Subiendo {{ upload.progress.percentage }} %
                    </span>
                    <span class="sr-only" role="status">
                        {{ upload.processing ? 'Subiendo el archivo…' : '' }}
                    </span>
                    <Button
                        type="submit"
                        class="pointer-coarse:h-11"
                        :disabled="upload.file === null || upload.processing"
                    >
                        <Spinner v-if="upload.processing" />
                        <Upload v-else />
                        Subir y leer
                    </Button>
                </div>
            </form>
        </SurfaceCard>

        <SurfaceCard
            title="Archivos del lote"
            description="Cómo se leyó cada archivo. Revisa las columnas no reconocidas y los datos que faltan."
            data-tour="history-import.files"
        >
            <EmptyState
                v-if="batch.files.length === 0"
                class="border-none"
                :icon="FileSpreadsheet"
                title="Todavía no hay archivos"
                description="Sube al menos el reporte de chats o los mensajes para ver el inventario."
            />
            <ul v-else class="divide-y" aria-label="Archivos del lote">
                <li
                    v-for="file in batch.files"
                    :key="file.id"
                    class="grid gap-3 px-4 py-4 sm:px-5"
                    :data-file="file.kind"
                >
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <p
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                <component
                                    :is="KIND_ICONS[file.kind]"
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 wrap-anywhere">{{
                                    file.name
                                }}</span>
                                <Badge
                                    :variant="FILE_STATUS_BADGE[file.status]"
                                >
                                    <Spinner
                                        v-if="isReading(file)"
                                        class="size-3"
                                    />
                                    {{ file.status_label }}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    title="El formato de Whaticket aún no se comprobó con una exportación real (ver el aviso de arriba)."
                                >
                                    Sin verificar
                                </Badge>
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ file.kind_label }} ·
                                {{ formatFileSize(file.size_bytes) }}
                                <template v-if="file.format">
                                    · formato detectado: {{ file.format }}
                                </template>
                            </p>
                        </div>
                        <div
                            v-if="editable"
                            class="flex shrink-0 flex-wrap gap-2"
                        >
                            <Button
                                v-if="file.status === 'failed'"
                                variant="outline"
                                size="sm"
                                class="pointer-coarse:h-11"
                                :disabled="retryingFile === file.id"
                                data-file-retry
                                @click="retryFile(file)"
                            >
                                <Spinner v-if="retryingFile === file.id" />
                                <RotateCw v-else />
                                Volver a leer
                            </Button>
                            <Button
                                v-if="hasColumns(file)"
                                variant="outline"
                                size="sm"
                                class="pointer-coarse:h-11"
                                :disabled="isReading(file)"
                                @click="openMapping(file)"
                            >
                                <Pencil />
                                Corregir columnas
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive hover:text-destructive pointer-coarse:h-11"
                                :aria-label="`Quitar el archivo ${file.name}`"
                                data-file-remove
                                @click="removingFile = file"
                            >
                                <Trash2 />
                                Quitar archivo
                            </Button>
                        </div>
                    </div>

                    <StatusNote
                        v-if="file.status === 'failed' && file.error"
                        tone="danger"
                        title="No se pudo leer el archivo"
                    >
                        {{ file.error }}
                    </StatusNote>

                    <dl
                        v-if="file.status === 'parsed'"
                        class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4"
                    >
                        <div class="rounded-lg bg-muted/40 px-3 py-2">
                            <dt class="text-xs text-muted-foreground">
                                Filas leídas
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ formatNumber(file.rows.total) }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-muted/40 px-3 py-2">
                            <dt class="text-xs text-muted-foreground">
                                Aceptadas
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ formatNumber(file.rows.accepted) }}
                            </dd>
                        </div>
                        <div class="rounded-lg bg-muted/40 px-3 py-2">
                            <dt class="text-xs text-muted-foreground">
                                Rechazadas
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ formatNumber(file.rows.rejected) }}
                            </dd>
                        </div>
                        <div
                            v-if="file.kind === 'media'"
                            class="rounded-lg bg-muted/40 px-3 py-2"
                        >
                            <dt class="text-xs text-muted-foreground">
                                Archivos en el ZIP
                            </dt>
                            <dd class="font-semibold tabular-nums">
                                {{ formatNumber(file.media_entries) }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="file.headers.length > 0"
                        class="grid gap-1 text-sm"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            Encabezados del archivo
                        </p>
                        <ul class="flex flex-wrap gap-1.5">
                            <li
                                v-for="(header, index) in file.headers"
                                :key="index"
                                :class="
                                    cn(
                                        'rounded-md border px-2 py-0.5 text-xs wrap-anywhere',
                                        file.unknown_headers.includes(header) &&
                                            'border-dashed text-muted-foreground',
                                    )
                                "
                            >
                                {{ header || '(sin nombre)' }}
                            </li>
                        </ul>
                    </div>

                    <StatusNote
                        v-if="file.unknown_headers.length > 0"
                        tone="info"
                        title="Columnas que no se usan"
                    >
                        No reconocimos
                        {{ file.unknown_headers.join(', ') }}. No se importan;
                        si alguna contiene un dato necesario, corrígela en
                        «Corregir columnas».
                    </StatusNote>
                    <StatusNote
                        v-if="file.missing_fields.length > 0"
                        tone="warning"
                        title="Faltan datos obligatorios"
                    >
                        No encontramos una columna para
                        {{
                            file.missing_fields
                                .map((field) => `«${fieldLabel(file, field)}»`)
                                .join(', ')
                        }}. Elige qué columna los contiene en «Corregir
                        columnas».
                    </StatusNote>
                    <ul
                        v-if="file.issues.length > 0"
                        class="grid gap-1 text-sm"
                        aria-label="Avisos de lectura"
                    >
                        <li
                            v-for="issue in file.issues"
                            :key="issue.code"
                            class="flex flex-wrap items-baseline gap-x-2 text-muted-foreground"
                        >
                            <span class="font-medium text-foreground">
                                {{ issue.message }}
                            </span>
                            <span class="text-xs tabular-nums">
                                {{
                                    issue.count === 1
                                        ? '1 fila'
                                        : `${formatNumber(issue.count)} filas`
                                }}<template v-if="issue.lines.length > 0">
                                    (línea{{
                                        issue.lines.length === 1 ? '' : 's'
                                    }}
                                    {{ issue.lines.slice(0, 8).join(', ')
                                    }}{{
                                        issue.lines.length > 8 ? '…' : ''
                                    }})</template
                                >
                            </span>
                        </li>
                    </ul>
                </li>
            </ul>
        </SurfaceCard>

        <ConfirmDialog
            v-model:open="removeOpen"
            :title="`¿Quitar «${removingFile?.name ?? ''}» del lote?`"
            description="Se borran el archivo guardado y los datos que se leyeron de él. Las conversaciones que solo venían de este archivo desaparecen del inventario, con sus decisiones. No se borra nada en Whaticket."
            confirm-label="Quitar archivo"
            destructive
            :processing="removeProcessing"
            @confirm="removeFile"
        />

        <Dialog v-model:open="mappingOpen">
            <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Corregir columnas</DialogTitle>
                    <DialogDescription>
                        Elige qué columna de «{{ mappingFile?.name }}» contiene
                        cada dato. Al guardar, el archivo se vuelve a leer.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="mappingFile" class="grid gap-3">
                    <div
                        v-for="field in mappingFile.fields"
                        :key="field.value"
                        class="grid gap-1.5 sm:grid-cols-[11rem_minmax(0,1fr)] sm:items-center"
                    >
                        <label
                            :id="`map-${field.value}-label`"
                            class="text-sm font-medium"
                        >
                            {{ field.label }}
                            <span
                                v-if="field.required"
                                class="text-xs font-normal text-muted-foreground"
                                >(obligatorio)</span
                            >
                        </label>
                        <Select v-model="mappingDraft[field.value]">
                            <SelectTrigger
                                :aria-labelledby="`map-${field.value}-label`"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE">
                                    Sin columna
                                </SelectItem>
                                <SelectItem
                                    v-for="(
                                        header, index
                                    ) in mappingFile.headers"
                                    :key="index"
                                    :value="String(index)"
                                >
                                    {{ header || `Columna ${index + 1}` }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <InputError :message="mappingError ?? undefined" />
                </div>
                <DialogFooter class="gap-2">
                    <Button
                        variant="outline"
                        :disabled="mappingSaving"
                        @click="mappingOpen = false"
                    >
                        Cancelar
                    </Button>
                    <Button :disabled="mappingSaving" @click="saveMapping">
                        <Spinner v-if="mappingSaving" />
                        Guardar y volver a leer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
