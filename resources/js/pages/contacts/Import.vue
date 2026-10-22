<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    Check,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Info,
    Upload,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import { index as contactsIndex, show as contactShow } from '@/routes/contacts';
import {
    confirm as confirmImport,
    create as importCreate,
    destroy as destroyImport,
    store as storeImport,
    template as importTemplate,
    update as updateImport,
} from '@/routes/contacts/import';
import type {
    ContactImport,
    ContactImportDecision,
    ContactImportField,
    ContactImportMappingOptions,
    ContactImportPreview,
    ContactImportPreviewRow,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Contactos', href: contactsIndex() },
            { title: 'Importar contactos', href: importCreate() },
        ],
    },
});

const props = defineProps<{
    step: 'upload' | 'mapping' | 'result';
    transfer: ContactImport | null;
    preview: ContactImportPreview | null;
    mapping?: ContactImportMappingOptions;
    previousImport?: {
        finished_at: string | null;
        summary: { created: number } | null;
    } | null;
    recent: ContactImport[];
    limits: { max_megabytes: number; max_rows: number; max_columns: number };
}>();

const steps = [
    { key: 'upload', label: 'Subir archivo' },
    { key: 'mapping', label: 'Mapear y revisar' },
    { key: 'result', label: 'Resultado' },
] as const;

const currentStep = computed(() =>
    steps.findIndex((step) => step.key === props.step),
);

/* Step 1: upload */

const upload = useForm<{ file: File | null }>({ file: null });

function pickFile(event: Event): void {
    const input = event.target as HTMLInputElement;
    upload.file = input.files?.[0] ?? null;
    upload.clearErrors();
}

function submitUpload(): void {
    upload.post(storeImport.url(), { forceFormData: true });
}

/* Step 2: mapping and preview */

const NONE = 'none';

function initialMapping(): Record<ContactImportField, string> {
    const current = props.mapping?.current;
    const result = {} as Record<ContactImportField, string>;

    for (const field of props.mapping?.fields ?? []) {
        const column = current?.[field.value] ?? null;
        result[field.value] = column === null ? NONE : String(column);
    }

    return result;
}

const configuration = useForm({
    mapping: initialMapping(),
    decision: (props.mapping?.decision ?? 'skip') as ContactImportDecision,
});

watch(
    () => props.mapping,
    () => {
        configuration.defaults({
            mapping: initialMapping(),
            decision: props.mapping?.decision ?? 'skip',
        });
        configuration.reset();
    },
);

function mappingPayload(): Record<ContactImportField, number | null> {
    const result = {} as Record<ContactImportField, number | null>;

    for (const [field, column] of Object.entries(configuration.mapping)) {
        result[field as ContactImportField] =
            column === NONE ? null : Number(column);
    }

    return result;
}

function saveConfiguration(onSaved?: () => void): void {
    if (props.transfer === null) {
        return;
    }

    configuration
        .transform((data) => ({ ...data, mapping: mappingPayload() }))
        .put(updateImport.url(props.transfer.id), {
            preserveScroll: true,
            onSuccess: () => onSaved?.(),
        });
}

const confirming = ref(false);

function confirm(): void {
    if (props.transfer === null) {
        return;
    }

    const transferId = props.transfer.id;
    const send = () =>
        router.post(
            confirmImport.url(transferId),
            {},
            {
                onStart: () => (confirming.value = true),
                onFinish: () => (confirming.value = false),
            },
        );

    if (configuration.isDirty) {
        saveConfiguration(send);
    } else {
        send();
    }
}

const cancelOpen = ref(false);
const cancelling = ref(false);

function cancelImport(): void {
    if (props.transfer === null) {
        return;
    }

    router.delete(destroyImport.url(props.transfer.id), {
        onStart: () => (cancelling.value = true),
        onFinish: () => {
            cancelling.value = false;
            cancelOpen.value = false;
        },
    });
}

const previewTab = ref<'issues' | 'rows'>('issues');

const previewRows = computed<ContactImportPreviewRow[]>(() =>
    previewTab.value === 'issues'
        ? (props.preview?.issues ?? [])
        : (props.preview?.rows ?? []),
);

const canConfirm = computed(
    () =>
        props.preview !== null &&
        props.preview.mapping_errors.length === 0 &&
        props.preview.summary.valid > 0,
);

function rowState(row: ContactImportPreviewRow): {
    label: string;
    class: string;
} {
    if (row.status === 'error') {
        return {
            label: 'Con error',
            class: 'bg-destructive/10 text-destructive',
        };
    }

    if (row.status === 'duplicate') {
        return {
            label: `Repetida (fila ${row.duplicate_of_line})`,
            class: 'bg-muted text-muted-foreground',
        };
    }

    if (row.existing.length > 0) {
        return {
            label:
                configuration.decision === 'fill_empty'
                    ? 'Existe · completar vacíos'
                    : 'Existe · se omite',
            class: 'bg-warning/10 text-warning',
        };
    }

    return { label: 'Nuevo', class: 'bg-success/10 text-success' };
}

function displayPhone(phone: string): string {
    return phone.startsWith('+') ? formatPhone(phone) : phone;
}

/* Step 3: progress and result */

const isRunning = computed(() =>
    ['queued', 'processing'].includes(props.transfer?.status.value ?? ''),
);

const { start, stop } = usePoll(
    2000,
    { only: ['transfer'] },
    { autoStart: false },
);

watch(
    isRunning,
    (running) => {
        if (running) {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);

const progress = computed(() => {
    const total = props.transfer?.rows_total ?? 0;
    const processed = props.transfer?.rows_processed ?? 0;

    return total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
});
</script>

<template>
    <Head title="Importar contactos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Importar contactos"
            description="Registra contactos desde un archivo de Excel (.xlsx) o .csv. Revisa cada fila antes de confirmar; los datos existentes nunca se sobrescriben."
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <a :href="importTemplate.url()" download>
                        <Download />
                        Descargar plantilla
                    </a>
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="contactsIndex()">
                        <ArrowLeft />
                        Contactos
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <ol
            class="grid grid-cols-3 gap-2 text-sm"
            aria-label="Pasos de la importación"
        >
            <li
                v-for="(item, index) in steps"
                :key="item.key"
                :aria-current="index === currentStep ? 'step' : undefined"
                :class="
                    cn(
                        'flex items-center gap-2 rounded-lg border px-3 py-2',
                        index === currentStep &&
                            'border-primary bg-primary-soft text-primary-soft-foreground',
                        index !== currentStep && 'text-muted-foreground',
                    )
                "
            >
                <span
                    :class="
                        cn(
                            'flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums',
                            index === currentStep &&
                                'border-primary bg-primary text-primary-foreground',
                            index < currentStep &&
                                'border-success bg-success/10 text-success',
                        )
                    "
                >
                    <Check v-if="index < currentStep" class="size-3.5" />
                    <template v-else>{{ index + 1 }}</template>
                </span>
                <span class="truncate font-medium">{{ item.label }}</span>
            </li>
        </ol>

        <!-- Step 1 -->
        <template v-if="step === 'upload'">
            <SurfaceCard
                title="Sube tu archivo"
                description="Usa la primera hoja con los encabezados en la primera fila."
                body-class="p-5"
            >
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitUpload"
                >
                    <label
                        for="import-file"
                        class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors focus-within:border-primary hover:border-primary hover:bg-muted/40"
                    >
                        <span
                            class="flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                        >
                            <FileSpreadsheet
                                class="size-6"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="text-sm font-medium">
                            {{
                                upload.file
                                    ? upload.file.name
                                    : 'Selecciona un archivo .xlsx o .csv'
                            }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            Máximo {{ limits.max_megabytes }} MB,
                            {{ limits.max_rows.toLocaleString('es-MX') }} filas
                            y {{ limits.max_columns }} columnas. Los libros con
                            macros no se aceptan y las fórmulas se importan como
                            texto.
                        </span>
                        <input
                            id="import-file"
                            type="file"
                            accept=".xlsx,.csv"
                            class="sr-only"
                            @change="pickFile"
                        />
                    </label>
                    <InputError :message="upload.errors.file" />

                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <span
                            v-if="upload.progress"
                            class="text-xs text-muted-foreground tabular-nums"
                        >
                            Subiendo {{ upload.progress.percentage }} %
                        </span>
                        <Button
                            type="submit"
                            :disabled="
                                upload.file === null || upload.processing
                            "
                        >
                            <Spinner v-if="upload.processing" />
                            <Upload v-else />
                            Subir y revisar
                        </Button>
                    </div>
                </form>
            </SurfaceCard>

            <SurfaceCard title="Importaciones recientes">
                <EmptyState
                    v-if="recent.length === 0"
                    :icon="FileSpreadsheet"
                    title="Aún no hay importaciones"
                    description="Las importaciones que hagas aparecerán aquí con su resultado."
                />
                <Table v-else>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Archivo</TableHead>
                            <TableHead>Estado</TableHead>
                            <TableHead class="hidden sm:table-cell">
                                Fecha
                            </TableHead>
                            <TableHead class="text-right">Resultado</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="item in recent" :key="item.id">
                            <TableCell class="max-w-48 truncate font-medium">
                                <Link :href="item.url" class="hover:underline">
                                    {{ item.file ?? 'Archivo' }}
                                </Link>
                            </TableCell>
                            <TableCell>{{ item.status.label }}</TableCell>
                            <TableCell
                                class="hidden text-muted-foreground sm:table-cell"
                            >
                                {{ formatDateTime(item.created_at) }}
                            </TableCell>
                            <TableCell class="text-right tabular-nums">
                                <template v-if="item.summary">
                                    {{ item.summary.created }} creados ·
                                    {{ item.summary.failed }} con error
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </SurfaceCard>
        </template>

        <!-- Step 2 -->
        <template v-else-if="step === 'mapping' && transfer && mapping">
            <Alert v-if="previousImport">
                <Info />
                <AlertTitle>Este archivo ya se importó</AlertTitle>
                <AlertDescription>
                    Se importó el
                    {{ formatDateTime(previousImport.finished_at) }}. Las filas
                    que ya existen se detectan como duplicadas, así que volver a
                    importarlo no crea contactos repetidos.
                </AlertDescription>
            </Alert>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <SurfaceCard
                    title="Columnas del archivo"
                    :description="`${transfer.file ?? 'Archivo'} · ${transfer.rows_total ?? 0} filas`"
                    body-class="flex flex-col gap-3 p-5"
                >
                    <div
                        v-for="field in mapping.fields"
                        :key="field.value"
                        class="grid gap-1.5 sm:grid-cols-[9rem_minmax(0,1fr)] sm:items-center"
                    >
                        <label
                            :for="`map-${field.value}`"
                            class="text-sm font-medium"
                        >
                            {{ field.label }}
                        </label>
                        <Select v-model="configuration.mapping[field.value]">
                            <SelectTrigger
                                :id="`map-${field.value}`"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE"
                                    >No importar</SelectItem
                                >
                                <SelectItem
                                    v-for="(header, index) in mapping.headers"
                                    :key="index"
                                    :value="String(index)"
                                >
                                    {{ header }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <InputError :message="configuration.errors.mapping" />
                </SurfaceCard>

                <SurfaceCard
                    title="Contactos que ya existen"
                    description="Se detectan por teléfono normalizado o correo electrónico."
                    body-class="flex flex-col gap-3 p-5"
                >
                    <label
                        v-for="option in mapping.decisions"
                        :key="option.value"
                        :class="
                            cn(
                                'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                configuration.decision === option.value
                                    ? 'border-primary bg-primary-soft/60'
                                    : 'hover:bg-muted/40',
                            )
                        "
                    >
                        <input
                            v-model="configuration.decision"
                            type="radio"
                            name="decision"
                            :value="option.value"
                            class="mt-0.5 accent-primary"
                        />
                        <span>
                            <span class="block font-medium">{{
                                option.label
                            }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{
                                    option.value === 'skip'
                                        ? 'Recomendado. La fila se registra como omitida en el reporte.'
                                        : 'Solo agrega datos que falten (teléfono, correo, etiquetas o una nota). Nunca reemplaza datos existentes.'
                                }}
                            </span>
                        </span>
                    </label>
                    <InputError :message="configuration.errors.decision" />

                    <div class="mt-auto flex flex-wrap justify-end gap-2 pt-2">
                        <Button
                            variant="outline"
                            :disabled="
                                !configuration.isDirty ||
                                configuration.processing
                            "
                            @click="saveConfiguration()"
                        >
                            <Spinner v-if="configuration.processing" />
                            Actualizar vista previa
                        </Button>
                    </div>
                </SurfaceCard>
            </div>

            <Alert
                v-for="error in preview?.mapping_errors ?? []"
                :key="error"
                variant="destructive"
            >
                <AlertCircle />
                <AlertTitle>Revisa la asignación de columnas</AlertTitle>
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <div
                v-if="preview"
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
            >
                <StatCard label="Filas" :value="preview.summary.total" />
                <StatCard label="Nuevos" :value="preview.summary.new" />
                <StatCard
                    label="Ya existen"
                    :value="preview.summary.existing"
                />
                <StatCard
                    label="Repetidos en el archivo"
                    :value="preview.summary.duplicates_in_file"
                />
                <StatCard label="Con error" :value="preview.summary.errors" />
                <StatCard
                    label="Con advertencias"
                    :value="preview.summary.warnings"
                />
            </div>

            <SurfaceCard v-if="preview" title="Vista previa">
                <template #actions>
                    <div
                        class="inline-flex rounded-lg border p-0.5 text-sm"
                        role="tablist"
                    >
                        <button
                            v-for="tab in [
                                { key: 'issues', label: 'Con observaciones' },
                                { key: 'rows', label: 'Primeras filas' },
                            ] as const"
                            :key="tab.key"
                            type="button"
                            role="tab"
                            :aria-selected="previewTab === tab.key"
                            :class="
                                cn(
                                    'rounded-md px-3 py-1 transition-colors',
                                    previewTab === tab.key
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )
                            "
                            @click="previewTab = tab.key"
                        >
                            {{ tab.label }}
                        </button>
                    </div>
                </template>

                <EmptyState
                    v-if="previewRows.length === 0"
                    :icon="CheckCircle2"
                    :title="
                        previewTab === 'issues'
                            ? 'Ninguna fila tiene observaciones'
                            : 'Sin filas para mostrar'
                    "
                    description="Todas las filas se registrarán como contactos nuevos."
                />
                <div v-else class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-14">Fila</TableHead>
                                <TableHead>Estado</TableHead>
                                <TableHead>Nombre</TableHead>
                                <TableHead>Teléfono</TableHead>
                                <TableHead class="hidden md:table-cell">
                                    Correo
                                </TableHead>
                                <TableHead class="hidden lg:table-cell">
                                    Etiquetas
                                </TableHead>
                                <TableHead class="min-w-64"
                                    >Observaciones</TableHead
                                >
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in previewRows"
                                :key="row.line"
                                class="align-top"
                            >
                                <TableCell
                                    class="text-muted-foreground tabular-nums"
                                >
                                    {{ row.line }}
                                </TableCell>
                                <TableCell>
                                    <span
                                        :class="
                                            cn(
                                                'inline-flex rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                                                rowState(row).class,
                                            )
                                        "
                                    >
                                        {{ rowState(row).label }}
                                    </span>
                                </TableCell>
                                <TableCell class="max-w-48 break-words">
                                    {{ row.name || '—' }}
                                </TableCell>
                                <TableCell
                                    class="whitespace-nowrap tabular-nums"
                                >
                                    {{
                                        row.phone
                                            ? displayPhone(row.phone)
                                            : '—'
                                    }}
                                </TableCell>
                                <TableCell
                                    class="hidden max-w-48 break-all md:table-cell"
                                >
                                    {{ row.email || '—' }}
                                </TableCell>
                                <TableCell class="hidden lg:table-cell">
                                    {{
                                        row.tags.length
                                            ? row.tags.join(', ')
                                            : '—'
                                    }}
                                </TableCell>
                                <TableCell>
                                    <ul class="flex flex-col gap-1 text-xs">
                                        <li
                                            v-for="error in row.errors"
                                            :key="error"
                                            class="flex gap-1.5 text-destructive"
                                        >
                                            <AlertCircle
                                                class="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            {{ error }}
                                        </li>
                                        <li
                                            v-for="warning in row.warnings"
                                            :key="warning"
                                            class="flex gap-1.5 text-warning"
                                        >
                                            <AlertTriangle
                                                class="mt-0.5 size-3.5 shrink-0"
                                                aria-hidden="true"
                                            />
                                            {{ warning }}
                                        </li>
                                        <li
                                            v-if="row.existing.length"
                                            class="text-muted-foreground"
                                        >
                                            Coincide con
                                            <template
                                                v-for="(
                                                    match, index
                                                ) in row.existing"
                                                :key="match.id"
                                            >
                                                <Link
                                                    :href="
                                                        contactShow(match.id)
                                                    "
                                                    class="font-medium text-foreground hover:underline"
                                                    >{{ match.name }}</Link
                                                ><span
                                                    v-if="
                                                        index <
                                                        row.existing.length - 1
                                                    "
                                                    >,
                                                </span>
                                            </template>
                                        </li>
                                    </ul>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
                <p class="border-t px-5 py-3 text-xs text-muted-foreground">
                    Se muestran hasta {{ preview.limit }} filas. El reporte
                    final incluye el resultado de cada fila.
                </p>
            </SurfaceCard>

            <div
                class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
                <Button variant="ghost" @click="cancelOpen = true">
                    Cancelar importación
                </Button>
                <div class="flex flex-col gap-1 sm:items-end">
                    <Button
                        :disabled="
                            !canConfirm ||
                            confirming ||
                            configuration.processing
                        "
                        @click="confirm"
                    >
                        <Spinner v-if="confirming" />
                        <Upload v-else />
                        Importar {{ preview?.summary.valid ?? 0 }} filas válidas
                    </Button>
                    <span class="text-xs text-muted-foreground">
                        Las filas con error se omiten y quedan en el reporte.
                    </span>
                </div>
            </div>

            <ConfirmDialog
                v-model:open="cancelOpen"
                title="¿Cancelar la importación?"
                description="El archivo subido se eliminará y no se registrará ningún contacto."
                confirm-label="Cancelar importación"
                destructive
                :processing="cancelling"
                @confirm="cancelImport"
            />
        </template>

        <!-- Step 3 -->
        <template v-else-if="transfer">
            <SurfaceCard
                :title="transfer.file ?? 'Importación'"
                :description="`Estado: ${transfer.status.label}`"
                body-class="flex flex-col gap-5 p-5"
            >
                <div v-if="isRunning" class="flex flex-col gap-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 font-medium">
                            <Spinner />
                            Procesando filas…
                        </span>
                        <span class="text-muted-foreground tabular-nums">
                            {{ transfer.rows_processed ?? 0 }} de
                            {{ transfer.rows_total ?? 0 }}
                        </span>
                    </div>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-all"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Puedes salir de esta página; la importación continúa en
                        segundo plano.
                    </p>
                </div>

                <Alert
                    v-else-if="transfer.status.value !== 'completed'"
                    variant="destructive"
                >
                    <AlertCircle />
                    <AlertTitle>La importación no se completó</AlertTitle>
                    <AlertDescription>
                        {{ transfer.error ?? 'Vuelve a subir el archivo.' }}
                    </AlertDescription>
                </Alert>

                <template v-if="transfer.summary">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard
                            label="Creados"
                            :value="transfer.summary.created"
                        />
                        <StatCard
                            label="Completados"
                            :value="transfer.summary.updated"
                            hint="Solo campos vacíos"
                        />
                        <StatCard
                            label="Omitidos"
                            :value="transfer.summary.skipped"
                        />
                        <StatCard
                            label="Con error"
                            :value="transfer.summary.failed"
                        />
                    </div>
                </template>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="importCreate()">Nueva importación</Link>
                    </Button>
                    <Button v-if="transfer.report_url" as-child>
                        <a :href="transfer.report_url" download>
                            <Download />
                            Descargar reporte (.xlsx)
                        </a>
                    </Button>
                </div>
            </SurfaceCard>
        </template>
    </div>
</template>
