<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    FileArchive,
    History,
    ListChecks,
    Plus,
    ShieldCheck,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusPill from '@/components/admin/StatusPill.vue';
import '@/components/admin/admin.css';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FormField from '@/components/FormField.vue';
import {
    FORMAT_DISCLAIMER,
    BATCH_STATUS_TONE,
    formatNumber,
} from '@/components/history-import/labels';
import PageHeader from '@/components/PageHeader.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
    can: { create: boolean; archive: boolean; purge: boolean };
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

/* «Eliminar lote definitivamente»: borra para siempre un lote ya importado
 * (distinto de «Descartar lote», que solo cancela un borrador en Show.vue). */
const purgeTarget = ref<HistoryBatchListItem | null>(null);
const purgeOpen = ref(false);

function openPurge(batch: HistoryBatchListItem): void {
    purgeTarget.value = batch;
    purgeOpen.value = true;
}

function onPurged(): void {
    router.reload();
}

const purgeUrl = computed(() =>
    purgeTarget.value
        ? `/admin/history-import/${purgeTarget.value.id}/purge`
        : '',
);

const purgeLabel = computed(
    () =>
        purgeTarget.value?.label ?? `Lote ${purgeTarget.value?.id ?? ''}`,
);

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
            tone="info"
            title="Formatos de Whaticket"
            data-tour="history-import.format"
        >
            <p>{{ FORMAT_DISCLAIMER }}</p>
            <p class="mt-1">{{ formatNote }}</p>
        </StatusNote>

        <ol
            class="grid reveal-stagger gap-3 md:grid-cols-3"
            aria-label="Cómo funciona la importación"
        >
            <li
                v-for="(item, position) in steps"
                :key="item.title"
                class="admin-card relative flex gap-3 p-4"
            >
                <span
                    v-if="position < steps.length - 1"
                    class="absolute top-1/2 -right-3 hidden h-px w-3 bg-linear-to-r from-border to-transparent md:block"
                    aria-hidden="true"
                />
                <span class="admin-icon-tile size-10">
                    <component
                        :is="item.icon"
                        class="size-4"
                        aria-hidden="true"
                    />
                </span>
                <span class="min-w-0 space-y-1">
                    <span class="block font-display text-base font-semibold">
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

        <div :class="can.create && 'split-layout-start [--aside-w:24rem]'">
            <SurfaceCard
                v-if="can.create"
                title="Nueva importación"
                description="Crea un lote para subir sus archivos. Puedes dejarlo a medias y volver después."
                body-class="p-4 sm:p-5"
                class="self-start rounded-2xl bg-card/85 backdrop-blur-md xl:sticky xl:top-20"
                data-tour="history-import.create"
            >
                <form
                    class="grid gap-4 md:grid-cols-3 xl:grid-cols-1"
                    @submit.prevent="create"
                >
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
                            :aria-invalid="
                                Boolean(form.errors.label) || undefined
                            "
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
                                Boolean(form.errors.source_instance) ||
                                undefined
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
                    <div class="flex justify-end md:col-span-3 xl:col-span-1">
                        <Button
                            type="submit"
                            class="pointer-coarse:h-11"
                            :disabled="form.processing"
                            :loading="form.processing"
                        >
                            <Plus v-if="!form.processing" />
                            Crear lote y subir archivos
                        </Button>
                    </div>
                </form>
            </SurfaceCard>

            <SurfaceCard
                title="Lotes de importación"
                description="Los últimos 50 lotes, del más reciente al más antiguo."
                class="admin-list overflow-clip"
                data-tour="history-import.batches"
            >
                <FilteredEmpty
                    v-if="batches.length === 0"
                    :narrowed="false"
                    narrowed-title=""
                    :icon="FileArchive"
                    title="Aún no hay importaciones"
                    :description="
                        can.create
                            ? 'Crea un lote para empezar. Podrás revisar todo antes de importar.'
                            : 'Cuando alguien con permiso cree un lote, aparecerá aquí.'
                    "
                />
                <ul
                    v-else
                    class="grid reveal-stagger gap-0.5 p-1.5"
                    aria-label="Lotes de importación"
                >
                    <li v-for="batch in batches" :key="batch.id" class="relative">
                        <Link
                            :href="importShow(batch.id)"
                            class="list-row group/row flex flex-col gap-2 px-3 py-3 outline-none sm:flex-row sm:items-center sm:gap-4 sm:px-4"
                            :class="can.purge && 'sm:pr-14'"
                            :data-batch="batch.id"
                        >
                            <span
                                class="admin-icon-tile hidden size-10 sm:inline-flex"
                                aria-hidden="true"
                            >
                                <FileArchive class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1 space-y-0.5">
                                <span
                                    class="flex flex-wrap items-center gap-2 font-medium"
                                >
                                    <span class="min-w-0 wrap-anywhere">
                                        {{ batch.label ?? `Lote ${batch.id}` }}
                                    </span>
                                    <StatusPill
                                        :label="batch.status_label"
                                        :tone="
                                            BATCH_STATUS_TONE[batch.status].tone
                                        "
                                        :live="
                                            BATCH_STATUS_TONE[batch.status].live
                                        "
                                    />
                                </span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{ batch.source_instance }} ·
                                    {{ formatNumber(batch.files_count) }}
                                    {{
                                        batch.files_count === 1
                                            ? 'archivo'
                                            : 'archivos'
                                    }}
                                    · creado
                                    {{ formatDateTime(batch.created_at) }}
                                    <template v-if="batch.owner">
                                        por {{ batch.owner }}</template
                                    >
                                </span>
                            </span>
                            <span
                                v-if="batch.result"
                                class="text-xs text-muted-foreground tabular-nums sm:text-right"
                            >
                                <span class="font-semibold text-foreground">{{
                                    formatNumber(batch.result.imported)
                                }}</span>
                                importadas ·
                                <span class="font-semibold text-foreground">{{
                                    formatNumber(batch.result.archived)
                                }}</span>
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
                                <ArrowRight
                                    class="size-4 transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/row:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </span>
                        </Link>
                        <Button
                            v-if="can.purge"
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="absolute top-2 right-2 z-10 text-muted-foreground hover:bg-destructive/10 hover:text-destructive dark:hover:bg-destructive/20 sm:top-1/2 sm:right-3 sm:-translate-y-1/2"
                            :aria-label="
                                'Eliminar lote definitivamente: ' +
                                (batch.label ?? `Lote ${batch.id}`)
                            "
                            @click.stop.prevent="openPurge(batch)"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                        </Button>
                    </li>
                </ul>
            </SurfaceCard>
        </div>

        <PermanentDeleteDialog
            v-if="can.purge"
            v-model:open="purgeOpen"
            title="Eliminar lote definitivamente"
            :description="`Esto borra para siempre el lote «${purgeLabel}»: todas sus conversaciones, mensajes y el lote mismo. No se puede deshacer.`"
            :erased="[
                'Todas las conversaciones importadas de este lote',
                'Todos los mensajes de esas conversaciones',
                'El lote y sus archivos de importación',
            ]"
            :kept="['Los contactos']"
            :url="purgeUrl"
            confirm-label="Eliminar lote definitivamente"
            @deleted="onPurged"
        />
    </div>
</template>
