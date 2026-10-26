<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    History,
    MessagesSquare,
    Upload,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import { formatNumber, plural } from '@/components/history-import/labels';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { index as historyIndex } from '@/routes/admin/history';
import { show as conversationShow } from '@/routes/admin/history/conversations';
import { index as importIndex } from '@/routes/admin/history-import';
import { ARCHIVE_LIST_KEY } from '@/components/history-import/labels';
import type {
    ArchiveFilters,
    ArchiveOptions,
    ArchivedConversation,
    ArchivedConversationPage,
} from '@/types/history-import';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Historial de Whaticket', href: historyIndex() },
        ],
    },
});

const props = defineProps<{
    filters: ArchiveFilters;
    conversations?: ArchivedConversationPage;
    options?: ArchiveOptions;
}>();

const { can } = usePermissions();

const ALL = '__all';

const state = reactive({
    destination: props.filters.destination ?? 'archive',
    search: props.filters.search ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    department_id: props.filters.department_id
        ? String(props.filters.department_id)
        : ALL,
    agent_id: props.filters.agent_id ? String(props.filters.agent_id) : ALL,
    tag_id: props.filters.tag_id ? String(props.filters.tag_id) : ALL,
    connection_id: props.filters.connection_id
        ? String(props.filters.connection_id)
        : ALL,
    batch_id: props.filters.batch_id ? String(props.filters.batch_id) : ALL,
});

function query(page?: number): Record<string, string | number> {
    const result: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(state)) {
        if (
            value !== '' &&
            value !== ALL &&
            !(key === 'destination' && value === 'archive')
        ) {
            result[key] = value.trim();
        }
    }

    if (props.filters.contact_id) {
        result.contact_id = props.filters.contact_id;
    }

    if (page && page > 1) {
        result.page = page;
    }

    return result;
}

const filtering = ref(false);

function apply(page?: number): void {
    router.get(historyIndex.url({ query: query(page) }), undefined, {
        preserveState: true,
        preserveScroll: page === undefined,
        replace: true,
        only: ['conversations', 'filters'],
        onStart: () => (filtering.value = true),
        onFinish: () => (filtering.value = false),
    });
}

watchDebounced(
    () => ({ ...state }),
    () => apply(),
    { debounce: 350, deep: true },
);

const hasFilters = computed(
    () =>
        state.search !== '' ||
        state.date_from !== '' ||
        state.date_to !== '' ||
        state.department_id !== ALL ||
        state.agent_id !== ALL ||
        state.tag_id !== ALL ||
        state.connection_id !== ALL ||
        state.batch_id !== ALL ||
        state.destination !== 'archive',
);

function clear(): void {
    state.destination = 'archive';
    state.search = '';
    state.date_from = '';
    state.date_to = '';
    state.department_id = ALL;
    state.agent_id = ALL;
    state.tag_id = ALL;
    state.connection_id = ALL;
    state.batch_id = ALL;
}

const selects = computed(() => [
    {
        key: 'department_id' as const,
        label: 'Departamento',
        all: 'Todos los departamentos',
        items: props.options?.departments ?? [],
    },
    {
        key: 'agent_id' as const,
        label: 'Agente',
        all: 'Todos los agentes',
        items: props.options?.agents ?? [],
    },
    {
        key: 'tag_id' as const,
        label: 'Etiqueta',
        all: 'Todas las etiquetas',
        items: props.options?.tags ?? [],
    },
    {
        key: 'connection_id' as const,
        label: 'Conexión',
        all: 'Todas las conexiones',
        items: props.options?.connections ?? [],
    },
    {
        key: 'batch_id' as const,
        label: 'Lote de importación',
        all: 'Todos los lotes',
        items: (props.options?.batches ?? []).map((batch) => ({
            id: batch.id,
            name:
                batch.label ??
                `Lote ${batch.id}${batch.finished_at ? ` · ${formatDate(batch.finished_at)}` : ''}`,
        })),
    },
]);

function contactName(conversation: ArchivedConversation): string {
    return (
        conversation.contact?.name ??
        displayPhone(conversation.contact?.phone ?? null) ??
        'Contacto sin nombre'
    );
}

function displayPhone(phone: string | null): string | null {
    return phone ? formatPhone(phone) : null;
}

const meta = computed(() => props.conversations?.meta ?? null);

/* «Historial» in a conversation goes back to this list with its filters. */
watch(
    () => [{ ...state }, meta.value?.current_page] as const,
    () => {
        try {
            sessionStorage.setItem(
                ARCHIVE_LIST_KEY,
                historyIndex.url({ query: query(meta.value?.current_page) }),
            );
        } catch {
            // Without storage the button goes back to the unfiltered list.
        }
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Head title="Historial de Whaticket" />

    <div class="page-shell">
        <PageHeader
            help="historial"
            title="Historial de Whaticket"
            description="Conversaciones traídas de Whaticket. Solo se consultan: no se puede responder, reabrir ni cambiar nada."
        >
            <template v-if="can('history-import.view')" #actions>
                <Button variant="outline" as-child>
                    <Link :href="importIndex()">
                        <Upload />
                        Importar de Whaticket
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <StatusNote tone="info" :icon="History">
            Historial importado de Whaticket · solo consulta. Las conversaciones
            «importadas» también aparecen en Cerradas; las «archivadas» solo
            aquí.
        </StatusNote>

        <SurfaceCard
            body-class="grid gap-3 p-4 sm:p-5"
            data-tour="history.filters"
        >
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label
                    for="history-search"
                    class="grid gap-1.5 text-sm font-medium sm:col-span-2"
                >
                    Buscar
                    <Input
                        id="history-search"
                        v-model="state.search"
                        type="search"
                        placeholder="Nombre o número del contacto"
                        autocomplete="off"
                    />
                </label>
                <div class="grid gap-1.5">
                    <span
                        id="history-destination-label"
                        class="text-sm font-medium"
                        >Mostrar</span
                    >
                    <Select v-model="state.destination">
                        <SelectTrigger
                            aria-labelledby="history-destination-label"
                            class="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="destination in options?.destinations ?? [
                                    {
                                        value: 'archive',
                                        label: 'Archivadas como historial',
                                    },
                                ]"
                                :key="destination.value"
                                :value="destination.value"
                            >
                                {{ destination.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <fieldset class="grid gap-1.5 sm:col-span-2">
                    <legend class="text-sm font-medium">
                        Con actividad entre
                    </legend>
                    <div
                        class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2"
                    >
                        <Input
                            id="history-from"
                            v-model="state.date_from"
                            type="date"
                            aria-label="Con actividad desde el"
                            aria-describedby="history-dates-hint"
                            :max="state.date_to || undefined"
                        />
                        <span
                            class="text-sm text-muted-foreground"
                            aria-hidden="true"
                            >y</span
                        >
                        <Input
                            id="history-to"
                            v-model="state.date_to"
                            type="date"
                            aria-label="Con actividad hasta el"
                            aria-describedby="history-dates-hint"
                            :min="state.date_from || undefined"
                        />
                    </div>
                    <p
                        id="history-dates-hint"
                        class="text-xs text-muted-foreground"
                    >
                        Muestra las conversaciones que tuvieron mensajes en
                        algún momento de ese periodo, aunque hayan empezado
                        antes o terminado después.
                    </p>
                </fieldset>
                <div
                    v-for="field in selects"
                    :key="field.key"
                    class="grid gap-1.5"
                >
                    <span
                        :id="`history-${field.key}-label`"
                        class="text-sm font-medium"
                        >{{ field.label }}</span
                    >
                    <Select v-model="state[field.key]">
                        <SelectTrigger
                            :aria-labelledby="`history-${field.key}-label`"
                            class="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">{{
                                field.all
                            }}</SelectItem>
                            <SelectItem
                                v-for="item in field.items"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <div v-if="hasFilters" class="flex justify-end">
                <Button
                    variant="ghost"
                    size="sm"
                    class="pointer-coarse:h-10"
                    @click="clear"
                >
                    <X />
                    Quitar filtros
                </Button>
            </div>
        </SurfaceCard>

        <SurfaceCard data-tour="history.list">
            <div
                v-if="!conversations"
                class="grid gap-2 p-4"
                aria-busy="true"
                aria-label="Cargando historial"
            >
                <Skeleton v-for="n in 5" :key="n" class="h-16 w-full" />
            </div>
            <EmptyState
                v-else-if="conversations.data.length === 0"
                class="border-none"
                :tone="hasFilters ? 'filtered' : 'neutral'"
                :icon="hasFilters ? undefined : MessagesSquare"
                :title="
                    hasFilters
                        ? 'Sin resultados para estos filtros'
                        : 'Todavía no hay historial importado'
                "
                :description="
                    hasFilters
                        ? 'Cambia o quita los filtros para ver otras conversaciones.'
                        : 'Cuando se confirme una importación de Whaticket, sus conversaciones aparecerán aquí.'
                "
            >
                <Button v-if="hasFilters" variant="outline" @click="clear"
                    >Quitar filtros</Button
                >
            </EmptyState>
            <template v-else>
                <p
                    class="flex items-center gap-2 border-b px-4 py-2 text-sm text-muted-foreground tabular-nums sm:px-5"
                >
                    <span data-history-total>{{
                        plural(
                            conversations.meta.total,
                            'conversación',
                            'conversaciones',
                        )
                    }}</span>
                    <Spinner
                        v-if="filtering"
                        class="size-4"
                        aria-label="Actualizando la lista"
                    />
                </p>
                <ul
                    class="divide-y transition-opacity"
                    :class="filtering && 'opacity-60'"
                    :aria-busy="filtering || undefined"
                    aria-label="Conversaciones importadas"
                >
                    <li
                        v-for="conversation in conversations.data"
                        :key="conversation.id"
                    >
                        <Link
                            :href="conversationShow(conversation.id)"
                            class="grid gap-2 px-4 py-3 transition-colors outline-none hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset sm:px-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center"
                            :data-history-conversation="conversation.id"
                        >
                            <span class="min-w-0 space-y-0.5">
                                <span
                                    class="flex flex-wrap items-center gap-2 font-medium"
                                >
                                    <span class="wrap-anywhere">{{
                                        contactName(conversation)
                                    }}</span>
                                    <Badge
                                        :variant="
                                            conversation.destination ===
                                            'archive'
                                                ? 'info'
                                                : 'success'
                                        "
                                    >
                                        {{
                                            conversation.destination ===
                                            'archive'
                                                ? 'Archivada'
                                                : 'Importada'
                                        }}
                                    </Badge>
                                </span>
                                <span
                                    v-if="
                                        conversation.contact?.name &&
                                        conversation.contact.phone
                                    "
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{
                                        displayPhone(conversation.contact.phone)
                                    }}
                                </span>
                            </span>
                            <span class="text-xs text-muted-foreground">
                                <span class="block">
                                    {{ formatDate(conversation.started_at) }} –
                                    <time
                                        :datetime="
                                            conversation.last_message_at ??
                                            undefined
                                        "
                                        :title="
                                            formatDateTime(
                                                conversation.last_message_at,
                                            )
                                        "
                                    >
                                        {{
                                            formatDate(
                                                conversation.last_message_at,
                                            )
                                        }}
                                    </time>
                                </span>
                                <span class="block tabular-nums">
                                    {{
                                        plural(
                                            conversation.messages_count,
                                            'mensaje',
                                        )
                                    }}
                                </span>
                            </span>
                            <span class="text-xs text-muted-foreground">
                                <span class="block wrap-anywhere">
                                    {{
                                        conversation.department?.name ??
                                        'Sin departamento'
                                    }}
                                    ·
                                    {{
                                        conversation.agent?.name ??
                                        (typeof conversation.source.agent ===
                                        'string'
                                            ? conversation.source.agent
                                            : 'Sin agente')
                                    }}
                                </span>
                                <span
                                    v-if="conversation.tags.length > 0"
                                    class="block wrap-anywhere"
                                >
                                    {{
                                        conversation.tags
                                            .map((tag) => tag.name)
                                            .join(', ')
                                    }}
                                </span>
                            </span>
                            <span class="text-sm font-medium text-primary"
                                >Ver historial</span
                            >
                        </Link>
                    </li>
                </ul>
                <nav
                    v-if="meta && meta.last_page > 1"
                    class="flex items-center justify-between gap-2 border-t px-4 py-3 sm:px-5"
                    aria-label="Paginación del historial"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        class="pointer-coarse:h-11 max-sm:pointer-coarse:w-11"
                        :disabled="meta.current_page <= 1"
                        @click="apply(meta.current_page - 1)"
                    >
                        <ChevronLeft />
                        <span class="max-sm:sr-only">Anterior</span>
                    </Button>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        Página {{ formatNumber(meta.current_page) }} de
                        {{ formatNumber(meta.last_page) }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        class="pointer-coarse:h-11 max-sm:pointer-coarse:w-11"
                        :disabled="meta.current_page >= meta.last_page"
                        @click="apply(meta.current_page + 1)"
                    >
                        <span class="max-sm:sr-only">Siguiente</span>
                        <ChevronRight />
                    </Button>
                </nav>
            </template>
        </SurfaceCard>
    </div>
</template>
