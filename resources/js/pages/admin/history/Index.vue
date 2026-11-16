<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    History,
    MessagesSquare,
    Upload,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import StatusPill from '@/components/admin/StatusPill.vue';
import TableSkeleton from '@/components/admin/TableSkeleton.vue';
import '@/components/admin/admin.css';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import type {
    ActiveFilterPill,
    FilterTabOption,
} from '@/components/filters/types';
import { formatNumber, plural } from '@/components/history-import/labels';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-picker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
let visitId = 0;

function apply(page?: number): void {
    const visit = ++visitId;

    router.get(historyIndex.url({ query: query(page) }), undefined, {
        preserveState: true,
        preserveScroll: page === undefined,
        replace: true,
        only: ['conversations', 'filters'],
        onStart: () => (filtering.value = true),
        // A cancelled older visit finishes after the newer one started.
        onFinish: () => {
            if (visit === visitId) {
                filtering.value = false;
            }
        },
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

/* «Mostrar» as tabs: short names, the server's full label as the tooltip. */
const DESTINATION_SHORT: Record<string, string> = {
    archive: 'Archivadas',
    import: 'Importadas',
    all: 'Todas',
};

type Destination = 'archive' | 'import' | 'all';

const destinationTabs = computed<FilterTabOption<Destination>[]>(() =>
    (
        props.options?.destinations ?? [
            { value: 'archive' as const, label: 'Archivadas como historial' },
        ]
    ).map((destination) => ({
        value: destination.value,
        label: DESTINATION_SHORT[destination.value] ?? destination.label,
        title: destination.label,
    })),
);

/* A picked day (YYYY-MM-DD) in local time: new Date() would read it as UTC. */
function dayLabel(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, (month ?? 1) - 1, day ?? 1).toLocaleDateString(
        'es-MX',
        { dateStyle: 'medium' },
    );
}

/* The applied selects and dates as removable pills over the list. */
const pills = computed<ActiveFilterPill[]>(() => {
    const result: ActiveFilterPill[] = [];

    for (const field of selects.value) {
        const value = state[field.key];

        if (value !== ALL) {
            result.push({
                key: field.key,
                label: `${field.label}: ${field.items.find((item) => String(item.id) === value)?.name ?? 'No disponible'}`,
                remove: () => (state[field.key] = ALL),
            });
        }
    }

    if (state.date_from !== '' || state.date_to !== '') {
        result.push({
            key: 'dates',
            label: `Actividad: ${state.date_from ? dayLabel(state.date_from) : '…'} – ${state.date_to ? dayLabel(state.date_to) : '…'}`,
            remove: () => {
                state.date_from = '';
                state.date_to = '';
            },
        });
    }

    return result;
});

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
            class="admin-list reveal overflow-clip"
            data-tour="history.list"
        >
            <div
                class="admin-toolbar grid gap-2 px-3 py-2.5"
                data-tour="history.filters"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <FilterSearch
                        id="history-search"
                        v-model="state.search"
                        placeholder="Nombre o número del contacto"
                        label="Buscar"
                        :loading="filtering"
                        class="max-sm:basis-full sm:min-w-60"
                    />
                    <FilterTabs
                        :options="destinationTabs"
                        :model-value="state.destination"
                        label="Mostrar"
                        class="max-sm:w-full"
                        @update:model-value="
                            (value) => (state.destination = value)
                        "
                    />
                    <div class="w-full sm:w-56">
                        <DateRangePicker
                            id="history-from"
                            v-model:from="state.date_from"
                            v-model:to="state.date_to"
                            placeholder="Cualquier fecha"
                            aria-label="Con actividad entre"
                            aria-describedby="history-dates-hint"
                        />
                        <p id="history-dates-hint" class="sr-only">
                            Muestra las conversaciones que tuvieron mensajes en
                            algún momento de ese periodo, aunque hayan empezado
                            antes o terminado después.
                        </p>
                    </div>
                </div>
                <div
                    class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center"
                >
                    <Select
                        v-for="field in selects"
                        :key="field.key"
                        v-model="state[field.key]"
                    >
                        <SelectTrigger
                            :aria-label="field.label"
                            class="w-full bg-card sm:w-44"
                            :class="
                                state[field.key] !== ALL && 'border-primary/40'
                            "
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
                    <p
                        v-if="conversations"
                        class="text-xs text-muted-foreground tabular-nums max-sm:col-span-2 sm:ml-auto"
                    >
                        <span data-history-total>{{
                            plural(
                                conversations.meta.total,
                                'conversación',
                                'conversaciones',
                            )
                        }}</span>
                    </p>
                </div>
                <ActiveFilters
                    v-if="pills.length > 0 || hasFilters"
                    :pills="pills"
                    :clearable="hasFilters"
                    @clear="clear"
                />
            </div>

            <div class="relative" :aria-busy="filtering || !conversations">
                <div
                    v-if="filtering"
                    class="absolute inset-x-0 top-0 z-10 pane-progress h-0.5"
                    aria-hidden="true"
                />

                <div
                    v-if="!conversations"
                    role="status"
                    aria-label="Cargando historial"
                >
                    <TableSkeleton :rows="6" />
                </div>
                <FilteredEmpty
                    v-else-if="conversations.data.length === 0"
                    :narrowed="hasFilters"
                    narrowed-title="Sin resultados para estos filtros"
                    narrowed-description="Cambia o quita los filtros para ver otras conversaciones."
                    :icon="MessagesSquare"
                    title="Todavía no hay historial importado"
                    description="Cuando se confirme una importación de Whaticket, sus conversaciones aparecerán aquí."
                    @clear="clear"
                />
                <ul
                    v-else
                    class="grid divide-y"
                    :class="filtering && 'admin-loading'"
                    aria-label="Conversaciones importadas"
                >
                    <li
                        v-for="conversation in conversations.data"
                        :key="conversation.id"
                    >
                        <Link
                            :href="conversationShow(conversation.id)"
                            class="group/row grid gap-x-4 gap-y-1 px-3 py-2.5 outline-none hover:bg-muted/50 focus-visible:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset sm:px-4 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center"
                            :data-history-conversation="conversation.id"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <ListAvatar
                                    :name="contactName(conversation)"
                                    channel="whatsapp"
                                />
                                <span class="min-w-0 space-y-0.5">
                                    <span
                                        class="flex flex-wrap items-center gap-2 font-medium"
                                    >
                                        <span class="wrap-anywhere">{{
                                            contactName(conversation)
                                        }}</span>
                                        <StatusPill
                                            :label="
                                                conversation.destination ===
                                                'archive'
                                                    ? 'Archivada'
                                                    : 'Importada'
                                            "
                                            :tone="
                                                conversation.destination ===
                                                'archive'
                                                    ? 'info'
                                                    : 'success'
                                            "
                                        />
                                    </span>
                                    <span
                                        v-if="
                                            conversation.contact?.name &&
                                            conversation.contact.phone
                                        "
                                        class="block text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{
                                            displayPhone(
                                                conversation.contact.phone,
                                            )
                                        }}
                                    </span>
                                </span>
                            </span>
                            <span
                                class="text-xs text-muted-foreground max-lg:pl-13"
                            >
                                <span class="block">
                                    {{ formatDate(conversation.started_at) }}
                                    –
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
                            <span
                                class="text-xs text-muted-foreground max-lg:pl-13"
                            >
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
                                    class="mt-1 flex flex-wrap gap-1"
                                >
                                    <span
                                        v-for="(
                                            tag, tagIndex
                                        ) in conversation.tags"
                                        :key="tagIndex"
                                        class="rounded-full bg-muted px-1.5 py-px text-[0.6875rem] text-muted-foreground"
                                        >{{ tag.name }}</span
                                    >
                                </span>
                            </span>
                            <span
                                class="inline-flex items-center gap-1 text-sm font-medium text-primary max-lg:pl-13"
                            >
                                Ver historial
                                <ChevronRight
                                    class="size-4 transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/row:translate-x-0.5"
                                    aria-hidden="true"
                                />
                            </span>
                        </Link>
                    </li>
                </ul>
            </div>
            <nav
                v-if="meta && meta.last_page > 1"
                class="flex items-center justify-between gap-2 border-t px-4 py-2.5"
                aria-label="Paginación del historial"
            >
                <Button
                    variant="outline"
                    size="sm"
                    class="pointer-coarse:h-11 max-sm:pointer-coarse:w-11"
                    :disabled="meta.current_page <= 1 || filtering"
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
                    :disabled="meta.current_page >= meta.last_page || filtering"
                    @click="apply(meta.current_page + 1)"
                >
                    <span class="max-sm:sr-only">Siguiente</span>
                    <ChevronRight />
                </Button>
            </nav>
        </SurfaceCard>
    </div>
</template>
