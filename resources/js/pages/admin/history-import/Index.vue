<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    FileArchive,
    History,
    ListChecks,
    Plus,
    ShieldCheck,
    Upload,
} from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import {
    FORMAT_DISCLAIMER,
    BATCH_STATUS_BADGE,
    formatNumber,
} from '@/components/history-import/labels';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatTimeZone } from '@/lib/format';
import { index as historyIndex } from '@/routes/admin/history';
import {
    index as importIndex,
    show as importShow,
    store as importStore,
} from '@/routes/admin/history-import';
import type { HistoryBatchListItem } from '@/types/history-import';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Importar de Whaticket', href: importIndex() }],
    },
});

const props = defineProps<{
    batches: HistoryBatchListItem[];
    can: { create: boolean; archive: boolean };
    defaults: { timezone: string; source_instance: string };
    formatNote: string;
}>();

const ZONES = [
    'America/Mexico_City',
    'America/Monterrey',
    'America/Merida',
    'America/Cancun',
    'America/Chihuahua',
    'America/Mazatlan',
    'America/Hermosillo',
    'America/Tijuana',
    'America/Bogota',
    'America/Lima',
    'America/Santiago',
    'America/Argentina/Buenos_Aires',
    'America/Sao_Paulo',
    'America/New_York',
    'Europe/Madrid',
    'UTC',
];

const zones = ZONES.includes(props.defaults.timezone)
    ? ZONES
    : [props.defaults.timezone, ...ZONES];

const form = useForm({
    label: '',
    source_instance: props.defaults.source_instance,
    timezone: props.defaults.timezone,
});

function create(): void {
    form.post(importStore.url(), { preserveScroll: true });
}

const steps = [
    {
        icon: Upload,
        title: 'Sube los archivos',
        text: 'El reporte de chats, los mensajes, los contactos y el ZIP de archivos o un Chat.txt. Nada se importa al subirlos.',
    },
    {
        icon: ListChecks,
        title: 'Decide qué pasa con cada conversación',
        text: 'Importar, archivar como histórico o no importar, una por una, en grupo o con reglas.',
    },
    {
        icon: ShieldCheck,
        title: 'Revisa el resumen y confirma',
        text: 'Solo al confirmar se crea algo. Nunca se envían mensajes ni se borra nada en Whaticket.',
    },
];
</script>

<template>
    <Head title="Importar historial de Whaticket" />

    <div class="page-shell">
        <PageHeader
            help="historial"
            title="Importar historial de Whaticket"
            description="Trae conversaciones anteriores de Whaticket para consultarlas en Chat Manager. Primero revisas y decides; nada se crea hasta que confirmas."
        >
            <template v-if="can.archive" #actions>
                <Button variant="outline" as-child>
                    <Link :href="historyIndex()">
                        <History />
                        Historial de Whaticket
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <StatusNote
            tone="warning"
            title="Formato aún no verificado"
            data-tour="history-import.format"
        >
            <p>{{ FORMAT_DISCLAIMER }}</p>
            <p class="mt-1">{{ formatNote }}</p>
        </StatusNote>

        <ol
            class="grid gap-3 md:grid-cols-3"
            aria-label="Cómo funciona la importación"
        >
            <li
                v-for="(item, position) in steps"
                :key="item.title"
                class="flex gap-3 rounded-xl border bg-card p-4 shadow-card"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                >
                    <component
                        :is="item.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                </span>
                <span class="min-w-0 space-y-1">
                    <span class="block text-sm font-semibold">
                        {{ position + 1 }}. {{ item.title }}
                    </span>
                    <span
                        class="block text-sm text-pretty text-muted-foreground"
                    >
                        {{ item.text }}
                    </span>
                </span>
            </li>
        </ol>

        <SurfaceCard
            v-if="can.create"
            title="Nueva importación"
            description="Crea un lote para subir sus archivos. Puedes dejarlo a medias y volver después."
            body-class="p-4 sm:p-5"
            data-tour="history-import.create"
        >
            <form class="grid gap-4 md:grid-cols-3" @submit.prevent="create">
                <FormField
                    id="batch-label"
                    label="Nombre del lote (opcional)"
                    hint="Por ejemplo «Whaticket 2024 ventas»."
                    :error="form.errors.label"
                >
                    <Input
                        id="batch-label"
                        v-model="form.label"
                        maxlength="120"
                        autocomplete="off"
                        :aria-invalid="Boolean(form.errors.label) || undefined"
                    />
                </FormField>
                <FormField
                    id="batch-instance"
                    label="Nombre de la cuenta de Whaticket (opcional)"
                    hint="Un nombre corto para reconocer de qué Whaticket vienen los datos y no importarlos dos veces. Si solo usas uno, deja el que aparece."
                    :error="form.errors.source_instance"
                >
                    <Input
                        id="batch-instance"
                        v-model="form.source_instance"
                        maxlength="64"
                        autocomplete="off"
                        :aria-invalid="
                            Boolean(form.errors.source_instance) || undefined
                        "
                    />
                </FormField>
                <FormField
                    id="batch-timezone"
                    label="Zona horaria de las fechas del archivo"
                    hint="Las fechas de Whaticket no indican zona horaria."
                    :error="form.errors.timezone"
                >
                    <Select v-model="form.timezone">
                        <SelectTrigger id="batch-timezone" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="zone in zones"
                                :key="zone"
                                :value="zone"
                            >
                                {{ formatTimeZone(zone) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>
                <div class="flex justify-end md:col-span-3">
                    <Button
                        type="submit"
                        class="pointer-coarse:h-11"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        <Plus v-else />
                        Crear lote y subir archivos
                    </Button>
                </div>
            </form>
        </SurfaceCard>

        <SurfaceCard
            title="Lotes de importación"
            description="Los últimos 50 lotes, del más reciente al más antiguo."
            data-tour="history-import.batches"
        >
            <EmptyState
                v-if="batches.length === 0"
                class="border-none"
                :icon="FileArchive"
                title="Aún no hay importaciones"
                :description="
                    can.create
                        ? 'Crea un lote para empezar. Podrás revisar todo antes de importar.'
                        : 'Cuando alguien con permiso cree un lote, aparecerá aquí.'
                "
            />
            <ul v-else class="divide-y" aria-label="Lotes de importación">
                <li v-for="batch in batches" :key="batch.id">
                    <Link
                        :href="importShow(batch.id)"
                        class="flex flex-col gap-2 px-4 py-3 transition-colors outline-none hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset sm:flex-row sm:items-center sm:gap-4 sm:px-5"
                        :data-batch="batch.id"
                    >
                        <span class="min-w-0 flex-1 space-y-0.5">
                            <span
                                class="flex flex-wrap items-center gap-2 font-medium"
                            >
                                <span class="min-w-0 wrap-anywhere">
                                    {{ batch.label ?? `Lote ${batch.id}` }}
                                </span>
                                <Badge
                                    :variant="BATCH_STATUS_BADGE[batch.status]"
                                >
                                    {{ batch.status_label }}
                                </Badge>
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ batch.source_instance }} ·
                                {{ formatNumber(batch.files_count) }}
                                {{
                                    batch.files_count === 1
                                        ? 'archivo'
                                        : 'archivos'
                                }}
                                · creado {{ formatDateTime(batch.created_at) }}
                                <template v-if="batch.owner">
                                    por {{ batch.owner }}</template
                                >
                            </span>
                        </span>
                        <span
                            v-if="batch.result"
                            class="text-xs text-muted-foreground tabular-nums sm:text-right"
                        >
                            {{ formatNumber(batch.result.imported) }}
                            importadas ·
                            {{ formatNumber(batch.result.archived) }}
                            archivadas
                        </span>
                        <span
                            class="inline-flex items-center gap-1 text-sm font-medium text-primary"
                        >
                            {{
                                batch.status === 'draft'
                                    ? 'Continuar'
                                    : 'Ver detalle'
                            }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </span>
                    </Link>
                </li>
            </ul>
        </SurfaceCard>
    </div>
</template>
