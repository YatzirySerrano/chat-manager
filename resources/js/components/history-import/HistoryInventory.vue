<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    FileWarning,
    Inbox,
    ListFilter,
    Paperclip,
    Users,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { cn } from '@/lib/utils';
import {
    bulk as bulkRoute,
    index as inventoryRoute,
    update as rowUpdate,
} from '@/routes/admin/history-import/inventory';
import type {
    HistoryDecision,
    HistoryFacets,
    HistoryFilters,
    HistoryInventoryPage,
    HistoryInventoryRow,
    HistoryPageMeta,
    HistorySummary,
} from '@/types/history-import';
import {
    compactFilters,
    errorMessage,
    filterQuery,
    historyRequest,
} from './api';
import HistoryFilterFields from './HistoryFilterFields.vue';
import HistoryRowDetail from './HistoryRowDetail.vue';
import {
    DECISION_ORDER,
    DECISION_SOURCES,
    DECISIONS,
    KIND_LABELS,
    ROW_STATES,
    describeFilters,
    formatNumber,
    plural,
} from './labels';

/**
 * Step 3: the inventory of the batch. A paginated list (cards on phones)
 * with the data the files actually have, filters, a destination per
 * conversation, multi-select with «Aplicar destino» to the selection or to
 * everything the filter matches, and the detail of one conversation.
 * Recommendations are only suggestions; the administrator decides.
 */
const props = defineProps<{
    batchId: number;
    facets: HistoryFacets | null;
    editable: boolean;
    perPage: number;
    /** Bumped by the page when decisions changed elsewhere (rules). */
    refreshKey: number;
}>();

const emit = defineEmits<{
    summary: [summary: HistorySummary];
    /** The inventory is being rebuilt in the background (participants changed). */
    rebuilding: [];
}>();

const filters = defineModel<HistoryFilters>('filters', { required: true });

const rows = ref<HistoryInventoryRow[]>([]);
const meta = ref<HistoryPageMeta | null>(null);
const page = ref(1);
const loading = ref(true);
const loadError = ref<string | null>(null);
const showMoreFilters = ref(false);
let requestSerial = 0;
/**
 * Rows saved by hand, with the serial of the last load that started before
 * the save: an answer of that load (or an older one) would show the
 * previous decision, so the saved row wins over it.
 */
const savedRows = new Map<
    number,
    { row: HistoryInventoryRow; staleUpTo: number }
>();

async function load(): Promise<void> {
    const serial = ++requestSerial;
    loading.value = true;
    loadError.value = null;

    try {
        const response = await historyRequest<HistoryInventoryPage>(
            'GET',
            inventoryRoute.url(props.batchId, {
                query: {
                    ...filterQuery(filters.value),
                    page: page.value,
                    per_page: props.perPage,
                },
            }),
        );

        if (serial !== requestSerial) {
            return;
        }

        for (const [id, saved] of savedRows) {
            if (saved.staleUpTo < serial) {
                savedRows.delete(id);
            }
        }

        rows.value = response.data.map(
            (row) => savedRows.get(row.id)?.row ?? row,
        );
        meta.value = response.meta;

        if (
            response.meta.last_page < page.value &&
            response.meta.last_page > 0
        ) {
            page.value = response.meta.last_page;
        }
    } catch (error) {
        if (serial === requestSerial) {
            loadError.value = errorMessage(error);
        }
    } finally {
        if (serial === requestSerial) {
            loading.value = false;
        }
    }
}

onMounted(load);

/* True between a filter change and the start of its load (the debounce). */
const filtersPending = ref(false);

watch(filters, () => (filtersPending.value = true), { deep: true });

watchDebounced(
    filters,
    () => {
        filtersPending.value = false;
        page.value = 1;
        selected.value = new Set();
        void load();
    },
    { debounce: 350, deep: true },
);

watch(page, () => void load());
watch(
    () => props.refreshKey,
    () => void load(),
);

const activeFilters = computed(() => describeFilters(filters.value));
const hasFilters = computed(() => activeFilters.value.length > 0);

function clearFilters(): void {
    filters.value = {};
}

/* Selection. */

const selected = ref(new Set<number>());

const allOnPageSelected = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((row) => selected.value.has(row.id)),
);

/** How many selected rows are not on the page shown. */
const selectedElsewhere = computed(
    () =>
        [...selected.value].filter(
            (id) => !rows.value.some((row) => row.id === id),
        ).length,
);

function clearSelection(): void {
    selected.value = new Set();
}

const someOnPageSelected = computed(
    () =>
        !allOnPageSelected.value &&
        rows.value.some((row) => selected.value.has(row.id)),
);

function toggleRow(id: number, checked: boolean | 'indeterminate'): void {
    const next = new Set(selected.value);

    if (checked === true) {
        next.add(id);
    } else {
        next.delete(id);
    }

    selected.value = next;
}

function togglePage(checked: boolean | 'indeterminate'): void {
    const next = new Set(selected.value);

    for (const row of rows.value) {
        if (checked === true) {
            next.add(row.id);
        } else {
            next.delete(row.id);
        }
    }

    selected.value = next;
}

/* Bulk destination. */

type BulkChoice = HistoryDecision | 'undecided';

const bulkDecision = ref<BulkChoice>('import');
const bulkProcessing = ref(false);
const filterConfirmOpen = ref(false);

const BULK_LABELS: Record<BulkChoice, string> = {
    import: DECISIONS.import.label,
    archive: DECISIONS.archive.label,
    exclude: DECISIONS.exclude.label,
    undecided: 'Volver a «Sin decidir»',
};

async function applyBulk(target: 'selection' | 'filter'): Promise<void> {
    bulkProcessing.value = true;

    try {
        const response = await historyRequest<{
            result: { matched: number; updated: number; not_allowed: number };
            summary: HistorySummary;
        }>('POST', bulkRoute.url(props.batchId), {
            decision: bulkDecision.value,
            ...(target === 'selection'
                ? { ids: [...selected.value] }
                : { filters: compactFilters(filters.value) }),
        });
        const { matched, updated, not_allowed: notAllowed } = response.result;
        emit('summary', response.summary);
        toast.success(
            `${plural(updated, 'conversación actualizada', 'conversaciones actualizadas')} de ${formatNumber(matched)}.` +
                (notAllowed > 0
                    ? ` ${plural(notAllowed, 'no admite', 'no admiten')} ese destino y no cambió.`
                    : ''),
        );
        selected.value = new Set();
        filterConfirmOpen.value = false;
        await load();
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        bulkProcessing.value = false;
    }
}

/* One conversation. */

const UNDECIDED = '__undecided';
const savingRows = ref(new Set<number>());

function setSaving(id: number, saving: boolean): void {
    const next = new Set(savingRows.value);

    if (saving) {
        next.add(id);
    } else {
        next.delete(id);
    }

    savingRows.value = next;
}

async function decide(row: HistoryInventoryRow, value: string): Promise<void> {
    const decision = value === UNDECIDED ? null : (value as HistoryDecision);

    if (decision === row.decision) {
        return;
    }

    setSaving(row.id, true);

    try {
        const response = await historyRequest<{
            conversation: HistoryInventoryRow;
            summary: HistorySummary;
        }>('PATCH', rowUpdate.url({ transfer: props.batchId, row: row.id }), {
            decision,
        });
        savedRows.set(row.id, {
            row: response.conversation,
            staleUpTo: requestSerial,
        });
        replaceRow(response.conversation);
        emit('summary', response.summary);
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        setSaving(row.id, false);
    }
}

function replaceRow(updated: HistoryInventoryRow): void {
    rows.value = rows.value.map((row) =>
        row.id === updated.id ? updated : row,
    );

    if (detailRow.value?.id === updated.id) {
        detailRow.value = updated;
    }
}

const detailRow = ref<HistoryInventoryRow | null>(null);
const detailOpen = ref(false);

function openDetail(row: HistoryInventoryRow): void {
    detailRow.value = row;
    detailOpen.value = true;
}

function onDetailUpdated(
    row: HistoryInventoryRow,
    summary: HistorySummary,
): void {
    replaceRow(row);
    emit('summary', summary);
}

/* Presentation. */

function contactTitle(row: HistoryInventoryRow): string {
    return (
        row.contact.name ??
        displayPhone(row.contact.phone) ??
        row.contact.identifier ??
        'Sin nombre'
    );
}

function displayPhone(phone: string | null): string | null {
    return phone ? (phone.startsWith('+') ? formatPhone(phone) : phone) : null;
}

function contactLine(row: HistoryInventoryRow): string {
    const parts = [
        row.contact.name ? displayPhone(row.contact.phone) : null,
        row.contact.phone ? null : row.contact.identifier,
        `${KIND_LABELS[row.kind] ?? row.kind}${row.ticket_id ? ` ${row.ticket_id}` : ''}`,
    ];

    return parts.filter(Boolean).join(' · ');
}

function isDuplicate(row: HistoryInventoryRow): boolean {
    return (
        row.already_imported ||
        row.issues.some((issue) => issue.code === 'already_imported')
    );
}

function validation(row: HistoryInventoryRow): {
    label: string;
    badge: 'danger' | 'warning' | 'success';
} {
    if (row.issues.some((issue) => issue.severity === 'error')) {
        return { label: 'Requiere revisión', badge: 'danger' };
    }

    if (row.issues.length > 0) {
        return {
            label: plural(row.issues.length, 'aviso'),
            badge: 'warning',
        };
    }

    return { label: 'Sin problemas', badge: 'success' };
}

function allowed(row: HistoryInventoryRow, decision: HistoryDecision): boolean {
    return row.allowed_decisions.includes(decision);
}

function decisionValue(row: HistoryInventoryRow): string {
    return row.decision ?? UNDECIDED;
}

const pageLabel = computed(() =>
    meta.value
        ? `Página ${meta.value.current_page} de ${Math.max(1, meta.value.last_page)}`
        : '',
);

const total = computed(() => meta.value?.total ?? 0);
</script>

<template>
    <div class="grid gap-4">
        <SurfaceCard
            title="Filtrar conversaciones"
            body-class="grid gap-3 p-4 sm:p-5"
            data-tour="history-import.filters"
        >
            <template #actions>
                <Button
                    variant="ghost"
                    size="sm"
                    class="pointer-coarse:h-10"
                    :aria-expanded="showMoreFilters"
                    aria-controls="inventory-filters"
                    @click="showMoreFilters = !showMoreFilters"
                >
                    <ListFilter />
                    {{ showMoreFilters ? 'Menos filtros' : 'Más filtros' }}
                </Button>
                <Button
                    v-if="hasFilters"
                    variant="ghost"
                    size="sm"
                    class="pointer-coarse:h-10"
                    @click="clearFilters"
                >
                    <X />
                    Quitar filtros
                </Button>
            </template>
            <div id="inventory-filters">
                <HistoryFilterFields
                    v-if="showMoreFilters"
                    v-model="filters"
                    :facets="facets"
                    id-prefix="inventory"
                />
                <div v-else class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-muted-foreground">Mostrar:</span>
                    <Button
                        v-for="choice in [
                            { value: undefined, label: 'Todas' },
                            { value: 'undecided', label: 'Sin decidir' },
                            ...DECISION_ORDER.map((value) => ({
                                value,
                                label: DECISIONS[value].label,
                            })),
                        ]"
                        :key="choice.label"
                        size="sm"
                        :variant="
                            filters.decision === choice.value
                                ? 'default'
                                : 'outline'
                        "
                        class="rounded-full pointer-coarse:h-10"
                        :aria-pressed="filters.decision === choice.value"
                        @click="
                            filters = {
                                ...filters,
                                decision:
                                    choice.value as HistoryFilters['decision'],
                            }
                        "
                    >
                        {{ choice.label }}
                    </Button>
                </div>
            </div>
            <p
                v-if="hasFilters"
                class="text-xs text-muted-foreground"
                data-active-filters
            >
                Filtro: {{ activeFilters.join(' · ') }}
            </p>
        </SurfaceCard>

        <div
            v-if="editable"
            class="z-10 flex flex-col gap-2 rounded-xl border bg-card p-3 shadow-card sm:sticky sm:top-[4.5rem] sm:flex-row sm:flex-wrap sm:items-center [@media(max-height:32rem)]:static"
            data-tour="history-import.bulk"
            role="group"
            aria-label="Aplicar destino"
        >
            <span
                class="flex flex-wrap items-center gap-x-2 text-sm font-medium"
            >
                <span aria-live="polite" data-selection-count>
                    {{
                        selected.size > 0
                            ? plural(
                                  selected.size,
                                  'seleccionada',
                                  'seleccionadas',
                              )
                            : 'Ninguna seleccionada'
                    }}<template v-if="selectedElsewhere > 0">
                        (incluye
                        {{
                            plural(
                                selectedElsewhere,
                                'de otra página',
                                'de otras páginas',
                            )
                        }})</template
                    >
                </span>
                <Button
                    v-if="selected.size > 0"
                    variant="link"
                    size="sm"
                    class="h-auto px-0 pointer-coarse:min-h-11"
                    @click="clearSelection"
                >
                    Quitar selección
                </Button>
            </span>
            <div
                class="flex flex-1 flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end"
            >
                <Select v-model="bulkDecision">
                    <SelectTrigger
                        class="w-full sm:w-56"
                        aria-label="Destino a aplicar"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="(label, value) in BULK_LABELS"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    class="pointer-coarse:h-10"
                    :disabled="selected.size === 0 || bulkProcessing"
                    @click="applyBulk('selection')"
                >
                    <Spinner v-if="bulkProcessing" />
                    Aplicar destino a la selección
                </Button>
                <Button
                    variant="outline"
                    class="pointer-coarse:h-10"
                    :disabled="
                        total === 0 ||
                        bulkProcessing ||
                        loading ||
                        filtersPending
                    "
                    @click="filterConfirmOpen = true"
                >
                    <Spinner v-if="loading || filtersPending" />
                    Aplicar a las {{ formatNumber(total) }} del filtro
                </Button>
            </div>
        </div>

        <SurfaceCard data-tour="history-import.inventory">
            <StatusNote
                v-if="loadError"
                tone="danger"
                class="m-4"
                live="polite"
            >
                {{ loadError }}
                <template #actions>
                    <Button size="sm" variant="outline" @click="load">
                        Reintentar
                    </Button>
                </template>
            </StatusNote>

            <div
                v-else-if="loading && rows.length === 0"
                class="grid gap-2 p-4"
                aria-busy="true"
                aria-label="Cargando conversaciones"
            >
                <Skeleton v-for="n in 5" :key="n" class="h-16 w-full" />
            </div>

            <EmptyState
                v-else-if="rows.length === 0"
                class="border-none"
                :tone="hasFilters ? 'filtered' : 'neutral'"
                :icon="hasFilters ? undefined : Inbox"
                :title="
                    hasFilters
                        ? 'Ninguna conversación coincide con el filtro'
                        : 'El inventario está vacío'
                "
                :description="
                    hasFilters
                        ? 'Cambia o quita los filtros para ver otras conversaciones.'
                        : 'Sube el reporte de chats, los mensajes o un Chat.txt para ver sus conversaciones.'
                "
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    @click="clearFilters"
                >
                    Quitar filtros
                </Button>
            </EmptyState>

            <template v-else>
                <div
                    class="flex items-center justify-between gap-2 border-b px-4 py-2 text-sm sm:px-5"
                >
                    <label
                        v-if="editable"
                        class="flex min-h-9 cursor-pointer items-center gap-2 pointer-coarse:min-h-11"
                    >
                        <Checkbox
                            :model-value="
                                allOnPageSelected
                                    ? true
                                    : someOnPageSelected
                                      ? 'indeterminate'
                                      : false
                            "
                            aria-label="Seleccionar todas las de esta página"
                            @update:model-value="togglePage"
                        />
                        <span>Seleccionar esta página</span>
                    </label>
                    <span
                        class="text-muted-foreground tabular-nums"
                        data-inventory-total
                    >
                        {{ plural(total, 'conversación', 'conversaciones') }}
                    </span>
                    <Spinner
                        v-if="loading"
                        class="size-4"
                        aria-label="Actualizando"
                    />
                </div>

                <ul class="divide-y" aria-label="Conversaciones del inventario">
                    <li
                        v-for="row in rows"
                        :key="row.id"
                        :class="
                            cn(
                                'grid gap-3 px-4 py-3 sm:px-5 lg:grid-cols-[auto_minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1.1fr)_minmax(0,0.9fr)_minmax(12rem,auto)] lg:items-start',
                                selected.has(row.id) && 'bg-primary-soft/30',
                            )
                        "
                        :data-row="row.source_key"
                    >
                        <div
                            v-if="editable"
                            class="flex items-start pt-0.5 max-lg:hidden"
                        >
                            <Checkbox
                                class="relative pointer-coarse:after:absolute pointer-coarse:after:-inset-3.5 pointer-coarse:after:content-['']"
                                :model-value="selected.has(row.id)"
                                :aria-label="`Seleccionar ${contactTitle(row)}`"
                                @update:model-value="toggleRow(row.id, $event)"
                            />
                        </div>
                        <div v-else class="max-lg:hidden" />

                        <div class="flex min-w-0 items-start gap-3">
                            <Checkbox
                                v-if="editable"
                                class="relative mt-0.5 lg:hidden pointer-coarse:after:absolute pointer-coarse:after:-inset-3.5 pointer-coarse:after:content-['']"
                                :model-value="selected.has(row.id)"
                                :aria-label="`Seleccionar ${contactTitle(row)}`"
                                @update:model-value="toggleRow(row.id, $event)"
                            />
                            <div class="min-w-0 space-y-1">
                                <button
                                    type="button"
                                    class="text-left font-medium wrap-anywhere underline-offset-2 outline-none hover:underline focus-visible:underline focus-visible:ring-2 focus-visible:ring-ring"
                                    data-row-open
                                    @click="openDetail(row)"
                                >
                                    {{ contactTitle(row) }}
                                </button>
                                <p
                                    class="text-xs wrap-anywhere text-muted-foreground"
                                >
                                    {{ contactLine(row) }}
                                </p>
                                <p class="flex flex-wrap gap-1">
                                    <Badge v-if="row.is_group" variant="info">
                                        <Users aria-hidden="true" />
                                        Grupo
                                    </Badge>
                                    <Badge
                                        v-if="isDuplicate(row)"
                                        variant="warning"
                                    >
                                        Posible duplicado
                                    </Badge>
                                    <Badge
                                        v-if="row.state !== 'pending'"
                                        :variant="ROW_STATES[row.state].badge"
                                    >
                                        {{ ROW_STATES[row.state].label }}
                                    </Badge>
                                </p>
                            </div>
                        </div>

                        <dl
                            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2 text-xs"
                        >
                            <dt class="text-muted-foreground">
                                Primer mensaje
                            </dt>
                            <dd>
                                <time
                                    :datetime="
                                        row.first_message_at ?? undefined
                                    "
                                    :title="
                                        formatDateTime(row.first_message_at)
                                    "
                                >
                                    {{ formatDate(row.first_message_at) }}
                                </time>
                            </dd>
                            <dt class="text-muted-foreground">
                                Último mensaje
                            </dt>
                            <dd>
                                <time
                                    :datetime="row.last_message_at ?? undefined"
                                    :title="formatDateTime(row.last_message_at)"
                                >
                                    {{ formatDate(row.last_message_at) }}
                                </time>
                            </dd>
                            <dt class="text-muted-foreground">Mensajes</dt>
                            <dd class="tabular-nums">
                                {{ formatNumber(row.messages.total) }}
                            </dd>
                        </dl>

                        <dl
                            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2 text-xs"
                        >
                            <dt class="text-muted-foreground">
                                Agente anterior
                            </dt>
                            <dd class="wrap-anywhere">
                                {{ row.source.agent ?? '—' }}
                            </dd>
                            <dt class="text-muted-foreground">Departamento</dt>
                            <dd class="wrap-anywhere">
                                {{ row.source.department ?? '—' }}
                            </dd>
                            <dt class="text-muted-foreground">Estado</dt>
                            <dd class="wrap-anywhere">
                                {{ row.source.status ?? '—' }}
                            </dd>
                            <template v-if="row.source.tags.length > 0">
                                <dt class="text-muted-foreground">Etiquetas</dt>
                                <dd class="wrap-anywhere">
                                    {{ row.source.tags.join(', ') }}
                                </dd>
                            </template>
                        </dl>

                        <div class="grid content-start gap-1 text-xs">
                            <p
                                v-if="row.attachments.total > 0"
                                class="flex items-center gap-1"
                            >
                                <Paperclip
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ formatNumber(row.attachments.available) }} de
                                {{ formatNumber(row.attachments.total) }}
                                archivos
                            </p>
                            <p
                                v-if="row.attachments.missing > 0"
                                class="flex items-center gap-1 text-warning"
                            >
                                <FileWarning
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{
                                    plural(row.attachments.missing, 'faltante')
                                }}
                            </p>
                            <p>
                                <Badge :variant="validation(row).badge">
                                    {{ validation(row).label }}
                                </Badge>
                            </p>
                            <p
                                v-if="row.recommendation"
                                class="text-muted-foreground"
                            >
                                Sugerencia:
                                {{
                                    DECISIONS[row.recommendation.decision]
                                        ?.short ?? row.recommendation.decision
                                }}
                            </p>
                        </div>

                        <div class="grid gap-1">
                            <Select
                                :model-value="decisionValue(row)"
                                :disabled="!editable || savingRows.has(row.id)"
                                @update:model-value="
                                    decide(row, String($event))
                                "
                            >
                                <SelectTrigger
                                    class="w-full"
                                    :aria-label="`Destino de ${contactTitle(row)}`"
                                    data-row-decision
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="UNDECIDED"
                                        >Sin decidir</SelectItem
                                    >
                                    <SelectItem
                                        v-for="decision in DECISION_ORDER"
                                        :key="decision"
                                        :value="decision"
                                        :disabled="!allowed(row, decision)"
                                    >
                                        {{ DECISIONS[decision].label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p
                                v-if="row.decision_source"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    DECISION_SOURCES[row.decision_source] ??
                                    row.decision_source
                                }}
                            </p>
                            <p
                                v-else-if="row.allowed_decisions.length === 1"
                                class="text-xs text-muted-foreground"
                            >
                                Solo admite «{{
                                    DECISIONS[row.allowed_decisions[0]]?.label
                                }}».
                            </p>
                        </div>
                    </li>
                </ul>

                <nav
                    v-if="meta && meta.last_page > 1"
                    class="flex items-center justify-between gap-2 border-t px-4 py-3 sm:px-5"
                    aria-label="Paginación del inventario"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        class="pointer-coarse:h-11 max-sm:pointer-coarse:w-11"
                        :disabled="page <= 1 || loading"
                        @click="page -= 1"
                    >
                        <ChevronLeft />
                        <span class="max-sm:sr-only">Anterior</span>
                    </Button>
                    <span class="text-sm text-muted-foreground tabular-nums">
                        {{ pageLabel }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        class="pointer-coarse:h-11 max-sm:pointer-coarse:w-11"
                        :disabled="page >= meta.last_page || loading"
                        @click="page += 1"
                    >
                        <span class="max-sm:sr-only">Siguiente</span>
                        <ChevronRight />
                    </Button>
                </nav>
            </template>
        </SurfaceCard>

        <HistoryRowDetail
            v-model:open="detailOpen"
            :batch-id="batchId"
            :row="detailRow"
            :editable="editable"
            @updated="onDetailUpdated"
            @rebuilding="emit('rebuilding')"
        />

        <ConfirmDialog
            v-model:open="filterConfirmOpen"
            :title="`¿Aplicar «${BULK_LABELS[bulkDecision]}» a ${plural(total, 'conversación', 'conversaciones')}?`"
            :description="
                hasFilters
                    ? `Se aplica a todas las que coinciden con el filtro (${activeFilters.join(' · ')}), no solo a esta página. Las que no admiten ese destino se quedan como están.`
                    : 'No hay filtro: se aplica a todas las conversaciones del lote. Las que no admiten ese destino se quedan como están.'
            "
            confirm-label="Aplicar destino"
            :processing="bulkProcessing"
            @confirm="applyBulk('filter')"
        />
    </div>
</template>
