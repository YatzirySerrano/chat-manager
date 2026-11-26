<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ChevronRight, ScrollText, Trash2 } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import AuditDetailSheet from '@/components/admin/AuditDetailSheet.vue';
import AuditPurgeDialog from '@/components/admin/AuditPurgeDialog.vue';
import AuditRestrictedBadge from '@/components/admin/AuditRestrictedBadge.vue';
import '@/components/admin/admin.css';
import DataPagination from '@/components/DataPagination.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import type { ActiveFilterPill } from '@/components/filters/types';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { DateRangePicker, formatDate } from '@/components/ui/date-picker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { index as auditIndex } from '@/routes/admin/audit';
import type {
    AuditFilters,
    AuditLogEntry,
    AuditTone,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Auditoría', href: auditIndex() }],
    },
});

const props = defineProps<{
    logs: Paginated<AuditLogEntry>;
    filters: AuditFilters;
    canExport?: boolean;
    canPurge: boolean;
    actions: { value: string; label: string }[];
    actors: { id: number; name: string }[];
    scope: 'all' | 'own';
}>();

const ALL = 'all';

const filterState = reactive({
    action: props.filters.action ?? ALL,
    actor: props.filters.actor === null ? ALL : String(props.filters.actor),
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const hasFilters = computed(
    () =>
        filterState.action !== ALL ||
        filterState.actor !== ALL ||
        filterState.from !== '' ||
        filterState.to !== '',
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.action !== ALL) {
        query.action = filterState.action;
    }

    if (filterState.actor !== ALL) {
        query.actor = filterState.actor;
    }

    if (filterState.from !== '') {
        query.from = filterState.from;
    }

    if (filterState.to !== '') {
        query.to = filterState.to;
    }

    const visit = ++visitId;

    router.get(auditIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['logs', 'filters'],
        onStart: () => (loading.value = true),
        // A cancelled older visit finishes after the new one started.
        onFinish: () => {
            if (visit === visitId) {
                loading.value = false;
            }
        },
    });
}

/* A filter request is on its way: the rows dim and a bar runs on top. */
const loading = ref(false);
let visitId = 0;

/* The applied filters as removable pills. */
const pills = computed<ActiveFilterPill[]>(() => {
    const result: ActiveFilterPill[] = [];

    if (filterState.action !== ALL) {
        result.push({
            key: 'action',
            label: `Acción: ${props.actions.find((action) => action.value === filterState.action)?.label ?? filterState.action}`,
            remove: () => {
                filterState.action = ALL;
                applyFilters();
            },
        });
    }

    if (filterState.actor !== ALL) {
        result.push({
            key: 'actor',
            label: `Responsable: ${props.actors.find((actor) => String(actor.id) === filterState.actor)?.name ?? 'No disponible'}`,
            remove: () => {
                filterState.actor = ALL;
                applyFilters();
            },
        });
    }

    if (filterState.from !== '' || filterState.to !== '') {
        const from =
            filterState.from !== '' ? formatDate(filterState.from) : null;
        const to = filterState.to !== '' ? formatDate(filterState.to) : null;

        result.push({
            key: 'dates',
            label:
                from && to
                    ? `Del ${from} al ${to}`
                    : from
                      ? `Desde el ${from}`
                      : `Hasta el ${to}`,
            remove: () => {
                filterState.from = '';
                filterState.to = '';
                applyFilters();
            },
        });
    }

    return result;
});

function clearFilters(): void {
    filterState.action = ALL;
    filterState.actor = ALL;
    filterState.from = '';
    filterState.to = '';
    applyFilters();
}

const toneClasses: Record<AuditTone, string> = {
    success: 'bg-success text-success',
    warning: 'bg-warning text-warning',
    danger: 'bg-destructive text-destructive',
    neutral: 'bg-muted-foreground/60 text-muted-foreground',
};

/* The system (no person) acted: no initials avatar. */
function isSystem(entry: AuditLogEntry): boolean {
    return entry.actor.id === null;
}

const selectedEntry = ref<AuditLogEntry | null>(null);
const detailOpen = ref(false);

const purgeOpen = ref(false);

function openDetail(entry: AuditLogEntry): void {
    selectedEntry.value = entry;
    detailOpen.value = true;
}
</script>

<template>
    <Head title="Auditoría" />

    <div class="page-shell">
        <PageHeader
            help="auditoria"
            title="Auditoría"
            :description="
                scope === 'own'
                    ? 'Operaciones que realizaste. Tu rol solo permite consultar tus propios registros.'
                    : 'Registro inalterable de las acciones administrativas. No incluye contraseñas ni secretos.'
            "
        >
            <template v-if="canExport || canPurge" #actions>
                <span v-if="canExport" class="inline-flex" data-tour="audit.export">
                    <ExportMenu report="audit" :filters="filters" />
                </span>
                <Button
                    v-if="canPurge"
                    variant="outline"
                    class="text-destructive hover:bg-destructive/10 hover:text-destructive dark:hover:bg-destructive/20"
                    @click="purgeOpen = true"
                >
                    <Trash2 />
                    Eliminar registros
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard class="admin-list reveal overflow-clip">
            <div
                class="admin-toolbar grid gap-2 px-3 py-2.5"
                data-tour="audit.filters"
            >
                <div
                    class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center"
                >
                    <Select
                        v-model="filterState.action"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full bg-card lg:w-56 lg:shrink-0"
                            aria-label="Filtrar por acción"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL"
                                >Todas las acciones</SelectItem
                            >
                            <SelectItem
                                v-for="action in actions"
                                :key="action.value"
                                :value="action.value"
                            >
                                {{ action.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        v-model="filterState.actor"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full bg-card lg:w-56 lg:shrink-0"
                            aria-label="Filtrar por responsable"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL"
                                >Todos los responsables</SelectItem
                            >
                            <SelectItem
                                v-for="actor in actors"
                                :key="actor.id"
                                :value="String(actor.id)"
                            >
                                {{ actor.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <div class="sm:col-span-2 lg:w-64 lg:shrink-0">
                        <DateRangePicker
                            v-model:from="filterState.from"
                            v-model:to="filterState.to"
                            class="bg-card"
                            placeholder="Cualquier fecha"
                            aria-label="Filtrar por fecha"
                            @change="applyFilters"
                        />
                    </div>
                </div>
                <ActiveFilters
                    v-if="hasFilters"
                    :pills="pills"
                    :clearable="hasFilters"
                    @clear="clearFilters"
                />
            </div>

            <div class="relative" :aria-busy="loading">
                <div
                    v-if="loading"
                    class="absolute inset-x-0 top-0 z-10 pane-progress h-0.5"
                    aria-hidden="true"
                />

                <div v-if="logs.data.length > 0" data-tour="audit.table">
                    <Table :class="loading && 'admin-loading'">
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead
                                    class="hidden w-48 pl-5 sm:table-cell"
                                    >Fecha</TableHead
                                >
                                <TableHead class="pl-5 sm:pl-3"
                                    >Acción</TableHead
                                >
                                <TableHead class="hidden md:table-cell"
                                    >Entidad</TableHead
                                >
                                <TableHead class="hidden w-56 sm:table-cell"
                                    >Responsable</TableHead
                                >
                                <TableHead class="w-px pr-4">
                                    <span class="sr-only">Detalle</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="(entry, position) in logs.data"
                                :key="entry.id"
                                :data-tour="
                                    position === 0 ? 'audit.row' : undefined
                                "
                                class="group/row cursor-pointer outline-none focus-visible:bg-primary-soft/45 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                                tabindex="0"
                                role="button"
                                :aria-label="`Ver detalle: ${entry.action.label}`"
                                @click="openDetail(entry)"
                                @keydown.enter.prevent="openDetail(entry)"
                                @keydown.space.prevent="openDetail(entry)"
                            >
                                <TableCell
                                    class="hidden pl-5 text-xs whitespace-nowrap text-muted-foreground tabular-nums sm:table-cell"
                                >
                                    {{ formatDateTime(entry.created_at) }}
                                </TableCell>
                                <TableCell
                                    class="w-full max-w-0 py-3 pl-5 sm:w-auto sm:max-w-none sm:pl-3"
                                >
                                    <span
                                        class="flex min-w-0 items-center gap-2 font-medium"
                                    >
                                        <span
                                            class="size-2 shrink-0 rounded-full"
                                            :class="
                                                toneClasses[entry.action.tone]
                                            "
                                            aria-hidden="true"
                                        />
                                        <span class="truncate">{{
                                            entry.action.label
                                        }}</span>
                                        <AuditRestrictedBadge
                                            v-if="entry.restricted"
                                        />
                                    </span>
                                    <!-- Phones: the hidden columns, in one line. -->
                                    <span
                                        class="mt-0.5 block truncate pl-4 text-xs text-muted-foreground sm:hidden"
                                    >
                                        {{ entry.actor.name }} ·
                                        {{ formatDateTime(entry.created_at) }}
                                    </span>
                                    <span
                                        class="mt-0.5 block truncate pl-4 text-xs text-muted-foreground md:hidden"
                                    >
                                        {{
                                            entry.subject.type_label
                                                ? `${entry.subject.type_label} · ${entry.subject.label ?? ''}`
                                                : 'Plataforma'
                                        }}
                                    </span>
                                </TableCell>
                                <TableCell
                                    class="hidden w-full max-w-0 truncate md:table-cell"
                                >
                                    <template v-if="entry.subject.type_label">
                                        <span class="text-muted-foreground"
                                            >{{ entry.subject.type_label }} ·
                                        </span>
                                        {{ entry.subject.label }}
                                    </template>
                                    <template v-else>Plataforma</template>
                                </TableCell>
                                <TableCell class="hidden sm:table-cell">
                                    <span
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <ListAvatar
                                            v-if="!isSystem(entry)"
                                            :name="entry.actor.name"
                                            :user-id="entry.actor.id"
                                            class="size-7! text-[0.6875rem]!"
                                        />
                                        <span
                                            v-else
                                            class="admin-icon-tile size-7 rounded-full"
                                            aria-hidden="true"
                                        >
                                            <ScrollText class="size-3.5" />
                                        </span>
                                        <span class="truncate">{{
                                            entry.actor.name
                                        }}</span>
                                    </span>
                                </TableCell>
                                <TableCell class="pr-4">
                                    <ChevronRight
                                        class="size-4 text-muted-foreground opacity-40 transition-[opacity,translate] duration-(--duration-base) ease-(--ease-spring) group-hover/row:translate-x-0.5 group-hover/row:opacity-100 group-focus-visible/row:opacity-100"
                                        aria-hidden="true"
                                    />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <FilteredEmpty
                    v-else
                    :narrowed="hasFilters"
                    narrowed-title="Sin resultados para estos filtros"
                    narrowed-description="Ajusta los filtros para ver otros eventos."
                    :icon="ScrollText"
                    title="Aún no hay eventos registrados"
                    description="Las acciones administrativas aparecerán aquí automáticamente."
                    @clear="clearFilters"
                />
            </div>

            <div
                v-if="logs.data.length > 0 || logs.meta.last_page > 1"
                class="border-t px-4 py-3"
            >
                <DataPagination :meta="logs.meta" noun="eventos" />
            </div>
        </SurfaceCard>

        <AuditDetailSheet v-model:open="detailOpen" :entry="selectedEntry" />
        <AuditPurgeDialog v-if="canPurge" v-model:open="purgeOpen" />
    </div>
</template>
