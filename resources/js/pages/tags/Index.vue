<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    MessagesSquare,
    Pencil,
    Plus,
    Search,
    Tag as TagIcon,
    UsersRound,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import TagFormDialog from '@/components/tags/TagFormDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { useViewMode } from '@/composables/useViewMode';
import { recordActions } from '@/lib/recordActions';
import {
    destroy as destroyTag,
    index as tagsIndex,
    restore as restoreTag,
} from '@/routes/tags';
import type {
    ColorSwatch,
    Paginated,
    RecordAction,
    RecordStateOption,
    TagFilters,
    TagSummary,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Etiquetas', href: tagsIndex() }],
    },
});

const props = defineProps<{
    tags: Paginated<TagSummary>;
    filters: TagFilters;
    recordStates: RecordStateOption[];
    colors: ColorSwatch[];
    can: { manage: boolean };
    canExport?: boolean;
}>();

const filterState = reactive({
    search: props.filters.search ?? '',
    record: props.filters.record,
});

const hasFilters = computed(
    () => filterState.search !== '' || filterState.record !== 'active',
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    if (filterState.record !== 'active') {
        query.record = filterState.record;
    }

    router.get(tagsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['tags', 'filters'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    filterState.search = '';
    filterState.record = 'active';
    applyFilters();
}

const formOpen = ref(false);
const editingTag = ref<TagSummary | null>(null);

function openCreate(): void {
    editingTag.value = null;
    formOpen.value = true;
}

function openEdit(tag: TagSummary): void {
    editingTag.value = tag;
    formOpen.value = true;
}

const deletion = useRecordDeletion<TagSummary>({
    destroy: (tag) => destroyTag(tag.id),
    restore: (tag) => restoreTag(tag.id),
    copy: (tag, step) =>
        step === 'delete'
            ? {
                  title: `¿Eliminar la etiqueta ${tag.name}?`,
                  description:
                      'Ya no podrá asignarse, pero se conserva en los contactos y conversaciones donde ya está aplicada. Podrás restaurarla.',
              }
            : {
                  title: `¿Restaurar la etiqueta ${tag.name}?`,
                  description: 'Podrá asignarse de nuevo.',
              },
});

const { mode: viewMode } = useViewMode('tags');

function actionsFor(tag: TagSummary): RecordAction[] {
    return recordActions(
        tag.can.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openEdit(tag),
        },
        ...deletion.actionsFor(tag),
    );
}

function usageLabel(
    count: number | undefined,
    singular: string,
    plural: string,
): string {
    const value = count ?? 0;

    return `${value} ${value === 1 ? singular : plural}`;
}
</script>

<template>
    <Head title="Etiquetas" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Etiquetas"
            description="Clasifica contactos y conversaciones con etiquetas de color. Solo clasifican: no otorgan permisos ni cambian estados."
        >
            <template v-if="can.manage || canExport" #actions>
                <ExportMenu v-if="canExport" report="tags" :filters="filters" />
                <Button v-if="can.manage" @click="openCreate">
                    <Plus />
                    Nueva etiqueta
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard>
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4"
                role="search"
            >
                <div class="relative order-1 min-w-0 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        v-model="filterState.search"
                        type="search"
                        placeholder="Buscar por nombre o descripción"
                        class="pl-9"
                        aria-label="Buscar etiquetas"
                    />
                </div>
                <ViewModeToggle v-model="viewMode" class="order-2 sm:order-3" />
                <div class="order-3 flex w-full gap-2 sm:order-2 sm:w-auto">
                    <RecordStateFilter
                        v-model="filterState.record"
                        :options="recordStates"
                        class="sm:w-36"
                        @change="applyFilters"
                    />
                    <Button
                        v-if="hasFilters"
                        variant="ghost"
                        @click="clearFilters"
                    >
                        <X />
                        Limpiar
                    </Button>
                </div>
            </div>

            <Table v-if="tags.data.length > 0 && viewMode === 'table'">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Etiqueta</TableHead>
                        <TableHead class="hidden md:table-cell">Uso</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="tag in tags.data"
                        :key="tag.id"
                        :class="tag.is_deleted && 'text-muted-foreground'"
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="grid gap-1">
                                <TagBadge
                                    :name="tag.name"
                                    :color="tag.color"
                                    :deleted="tag.is_deleted"
                                    size="md"
                                    class="w-fit"
                                />
                                <p
                                    v-if="tag.description"
                                    class="line-clamp-2 max-w-md text-xs whitespace-normal text-muted-foreground"
                                >
                                    {{ tag.description }}
                                </p>
                            </div>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            <div
                                class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    <UsersRound
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        usageLabel(
                                            tag.contacts_count,
                                            'contacto',
                                            'contactos',
                                        )
                                    }}
                                </span>
                                <span class="inline-flex items-center gap-1.5">
                                    <MessagesSquare
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{
                                        usageLabel(
                                            tag.conversations_count,
                                            'conversación',
                                            'conversaciones',
                                        )
                                    }}
                                </span>
                            </div>
                        </TableCell>
                        <TableCell>
                            <DeletedBadge v-if="tag.is_deleted" feminine />
                            <span v-else class="text-xs text-muted-foreground"
                                >Activa</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(tag)"
                                :record="`la etiqueta ${tag.name}`"
                                :busy="deletion.processingId === tag.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else-if="tags.data.length > 0">
                <li v-for="tag in tags.data" :key="tag.id">
                    <RecordCard :muted="tag.is_deleted">
                        <template #title>
                            <TagBadge
                                :name="tag.name"
                                :color="tag.color"
                                :deleted="tag.is_deleted"
                                size="md"
                                class="max-w-full"
                            />
                        </template>
                        <template v-if="tag.is_deleted" #badges>
                            <DeletedBadge feminine />
                        </template>
                        <p class="line-clamp-2 text-muted-foreground">
                            {{ tag.description || 'Sin descripción.' }}
                        </p>
                        <template #meta>
                            <span class="inline-flex items-center gap-1">
                                <UsersRound
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    usageLabel(
                                        tag.contacts_count,
                                        'contacto',
                                        'contactos',
                                    )
                                }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <MessagesSquare
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{
                                    usageLabel(
                                        tag.conversations_count,
                                        'conversación',
                                        'conversaciones',
                                    )
                                }}
                            </span>
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(tag)"
                                :record="`la etiqueta ${tag.name}`"
                                :busy="deletion.processingId === tag.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>

            <EmptyState
                v-else
                class="m-4 border-none"
                :icon="TagIcon"
                :title="
                    hasFilters
                        ? 'Ninguna etiqueta coincide con los filtros'
                        : 'Aún no hay etiquetas'
                "
                :description="
                    hasFilters
                        ? 'Prueba con otros criterios de búsqueda.'
                        : can.manage
                          ? 'Crea la primera etiqueta para clasificar contactos y conversaciones.'
                          : 'Cuando se creen etiquetas aparecerán aquí.'
                "
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    @click="clearFilters"
                    >Limpiar filtros</Button
                >
                <Button v-else-if="can.manage" @click="openCreate">
                    <Plus />
                    Nueva etiqueta
                </Button>
            </EmptyState>

            <div
                v-if="tags.meta.last_page > 1 || tags.data.length > 0"
                class="border-t px-4 py-3"
            >
                <DataPagination :meta="tags.meta" noun="etiquetas" />
            </div>
        </SurfaceCard>

        <TagFormDialog
            v-if="can.manage"
            v-model:open="formOpen"
            :tag="editingTag"
            :colors="colors"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
