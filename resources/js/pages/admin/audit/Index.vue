<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ScrollText, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import AuditDetailSheet from '@/components/admin/AuditDetailSheet.vue';
import AuditRestrictedBadge from '@/components/admin/AuditRestrictedBadge.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-picker';
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

    router.get(auditIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['logs', 'filters'],
    });
}

function clearFilters(): void {
    filterState.action = ALL;
    filterState.actor = ALL;
    filterState.from = '';
    filterState.to = '';
    applyFilters();
}

const toneClasses: Record<AuditTone, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    neutral: 'bg-muted-foreground/60',
};

const selectedEntry = ref<AuditLogEntry | null>(null);
const detailOpen = ref(false);

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
            <template v-if="canExport" #actions>
                <span class="inline-flex" data-tour="audit.export">
                    <ExportMenu report="audit" :filters="filters" />
                </span>
            </template>
        </PageHeader>

        <div
            class="grid grid-cols-1 gap-2 rounded-xl border bg-card p-3 sm:grid-cols-2 lg:flex lg:items-center"
            data-tour="audit.filters"
        >
            <Select
                v-model="filterState.action"
                @update:model-value="applyFilters"
            >
                <SelectTrigger
                    class="w-full lg:w-64 lg:shrink-0"
                    aria-label="Filtrar por acción"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">Todas las acciones</SelectItem>
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
                    class="w-full lg:w-60 lg:shrink-0"
                    aria-label="Filtrar por responsable"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">Todos los responsables</SelectItem>
                    <SelectItem
                        v-for="actor in actors"
                        :key="actor.id"
                        :value="String(actor.id)"
                    >
                        {{ actor.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <DateRangePicker
                v-model:from="filterState.from"
                v-model:to="filterState.to"
                class="w-full lg:w-72 lg:shrink-0"
                placeholder="Cualquier fecha"
                aria-label="Filtrar por fecha"
                @change="applyFilters"
            />
            <Button
                v-if="hasFilters"
                variant="ghost"
                class="lg:ml-auto"
                @click="clearFilters"
            >
                <X />
                Limpiar
            </Button>
        </div>

        <div
            v-if="logs.data.length > 0"
            class="reveal overflow-hidden rounded-xl border bg-card"
            data-tour="audit.table"
        >
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="w-48 pl-5">Fecha</TableHead>
                        <TableHead>Acción</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Entidad</TableHead
                        >
                        <TableHead class="w-56">Responsable</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="(entry, position) in logs.data"
                        :key="entry.id"
                        :data-tour="position === 0 ? 'audit.row' : undefined"
                        class="cursor-pointer"
                        tabindex="0"
                        role="button"
                        :aria-label="`Ver detalle: ${entry.action.label}`"
                        @click="openDetail(entry)"
                        @keydown.enter.prevent="openDetail(entry)"
                        @keydown.space.prevent="openDetail(entry)"
                    >
                        <TableCell
                            class="pl-5 text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                        >
                            {{ formatDateTime(entry.created_at) }}
                        </TableCell>
                        <TableCell>
                            <span
                                class="inline-flex items-center gap-2 font-medium"
                            >
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="toneClasses[entry.action.tone]"
                                    aria-hidden="true"
                                />
                                {{ entry.action.label }}
                                <AuditRestrictedBadge v-if="entry.restricted" />
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
                        <TableCell>{{ entry.actor.name }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <EmptyState
            v-else
            :tone="hasFilters ? 'filtered' : 'neutral'"
            :icon="hasFilters ? undefined : ScrollText"
            :title="
                hasFilters
                    ? 'Sin resultados para estos filtros'
                    : 'Aún no hay eventos registrados'
            "
            :description="
                hasFilters
                    ? 'Ajusta los filtros para ver otros eventos.'
                    : 'Las acciones administrativas aparecerán aquí automáticamente.'
            "
        >
            <Button v-if="hasFilters" variant="outline" @click="clearFilters">
                Limpiar filtros
            </Button>
        </EmptyState>

        <DataPagination :meta="logs.meta" noun="eventos" />

        <AuditDetailSheet v-model:open="detailOpen" :entry="selectedEntry" />
    </div>
</template>
