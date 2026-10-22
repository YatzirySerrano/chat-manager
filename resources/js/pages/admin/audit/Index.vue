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
import { Input } from '@/components/ui/input';
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
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-rose-500',
    neutral: 'bg-slate-400',
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

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Auditoría"
            :description="
                scope === 'own'
                    ? 'Operaciones que realizaste. Tu rol solo permite consultar tus propios registros.'
                    : 'Registro inalterable de las acciones administrativas. No incluye contraseñas ni secretos.'
            "
        >
            <template v-if="canExport" #actions>
                <ExportMenu report="audit" :filters="filters" />
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:items-end">
            <Select
                v-model="filterState.action"
                @update:model-value="applyFilters"
            >
                <SelectTrigger
                    class="w-full lg:w-64"
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
                    class="w-full lg:w-52"
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
            <label
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                Desde
                <Input
                    v-model="filterState.from"
                    type="date"
                    class="w-full lg:w-40"
                    @change="applyFilters"
                />
            </label>
            <label
                class="flex items-center gap-2 text-sm text-muted-foreground"
            >
                Hasta
                <Input
                    v-model="filterState.to"
                    type="date"
                    class="w-full lg:w-40"
                    :min="filterState.from || undefined"
                    @change="applyFilters"
                />
            </label>
            <Button v-if="hasFilters" variant="ghost" @click="clearFilters">
                <X />
                Limpiar
            </Button>
        </div>

        <div
            v-if="logs.data.length > 0"
            class="overflow-hidden rounded-xl border bg-card shadow-card"
        >
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Fecha</TableHead>
                        <TableHead>Acción</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Entidad</TableHead
                        >
                        <TableHead>Responsable</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="entry in logs.data"
                        :key="entry.id"
                        class="cursor-pointer"
                        tabindex="0"
                        role="button"
                        :aria-label="`Ver detalle: ${entry.action.label}`"
                        @click="openDetail(entry)"
                        @keydown.enter.prevent="openDetail(entry)"
                        @keydown.space.prevent="openDetail(entry)"
                    >
                        <TableCell class="text-muted-foreground tabular-nums">
                            {{ formatDateTime(entry.created_at) }}
                        </TableCell>
                        <TableCell>
                            <span class="inline-flex items-center gap-2">
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
                            class="hidden max-w-72 truncate md:table-cell"
                        >
                            {{ entry.subject.label ?? 'Plataforma' }}
                        </TableCell>
                        <TableCell>{{ entry.actor.name }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <EmptyState
            v-else
            :icon="ScrollText"
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
