<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    FolderTree,
    MessagesSquare,
    Pencil,
    Plus,
    Search,
    SlidersHorizontal,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import CategoryFormDialog from '@/components/categories/CategoryFormDialog.vue';
import CategoryRulesDialog from '@/components/categories/CategoryRulesDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordGrid from '@/components/RecordGrid.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
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
    destroy as destroyCategory,
    index as categoriesIndex,
    restore as restoreCategory,
} from '@/routes/categories';
import type {
    ColorSwatch,
    ConversationCategoryFilters,
    ConversationCategorySummary,
    Paginated,
    RecordAction,
    RecordStateOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Categorías de solicitud', href: categoriesIndex() },
        ],
    },
});

const props = defineProps<{
    categories: Paginated<ConversationCategorySummary>;
    filters: ConversationCategoryFilters;
    recordStates: RecordStateOption[];
    colors: ColorSwatch[];
    can: { manage: boolean };
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

    router.get(categoriesIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['categories', 'filters'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    filterState.search = '';
    filterState.record = 'active';
    applyFilters();
}

const formOpen = ref(false);
const editingCategory = ref<ConversationCategorySummary | null>(null);

function openCreate(): void {
    editingCategory.value = null;
    formOpen.value = true;
}

function openEdit(category: ConversationCategorySummary): void {
    editingCategory.value = category;
    formOpen.value = true;
}

const rulesOpen = ref(false);
const rulesCategoryId = ref<number | null>(null);

/** Read from the page so the dialog follows the saved rules. */
const rulesCategory = computed(
    () =>
        props.categories.data.find(
            (category) => category.id === rulesCategoryId.value,
        ) ?? null,
);

function openRules(category: ConversationCategorySummary): void {
    rulesCategoryId.value = category.id;
    rulesOpen.value = true;
}

const deletion = useRecordDeletion<ConversationCategorySummary>({
    destroy: (category) => destroyCategory(category.id),
    restore: (category) => restoreCategory(category.id),
    copy: (category, step) =>
        step === 'delete'
            ? {
                  title: `¿Eliminar la categoría ${category.name}?`,
                  description:
                      'Ya no podrá elegirse y sus reglas propias dejarán de aplicarse, pero se conserva en las conversaciones que ya la tienen. Podrás restaurarla.',
              }
            : {
                  title: `¿Restaurar la categoría ${category.name}?`,
                  description:
                      'Podrá elegirse de nuevo y sus reglas propias volverán a aplicarse.',
              },
});

const { mode: viewMode } = useViewMode('categories');

function actionsFor(category: ConversationCategorySummary): RecordAction[] {
    return recordActions(
        category.can.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openEdit(category),
        },
        category.can.update && {
            key: 'rules',
            label: 'Reglas de servicio',
            icon: SlidersHorizontal,
            onSelect: () => openRules(category),
        },
        ...deletion.actionsFor(category),
    );
}

function usageLabel(count: number | undefined): string {
    const value = count ?? 0;

    return `${value} ${value === 1 ? 'conversación' : 'conversaciones'}`;
}

function rulesLabel(count: number): string {
    return count === 0
        ? 'Usa los valores generales'
        : `${count} ${count === 1 ? 'regla propia' : 'reglas propias'}`;
}
</script>

<template>
    <Head title="Categorías de solicitud" />

    <div class="page-shell">
        <PageHeader
            help="categorias"
            title="Categorías de solicitud"
            description="Tipos de solicitud de los clientes. Cada categoría puede tener reglas de servicio propias, que tienen prioridad sobre las del departamento y las generales."
        >
            <template v-if="can.manage" #actions>
                <Button data-tour="categories.new" @click="openCreate">
                    <Plus />
                    Nueva categoría
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard>
            <div
                class="flex flex-wrap items-center gap-2 border-b p-4"
                role="search"
                data-tour="categories.search"
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
                        aria-label="Buscar categorías"
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

            <Table v-if="categories.data.length > 0 && viewMode === 'table'">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Categoría</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Reglas de servicio</TableHead
                        >
                        <TableHead class="hidden md:table-cell">Uso</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead class="pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="(category, position) in categories.data"
                        :key="category.id"
                        :data-tour="
                            position === 0 ? 'categories.list' : undefined
                        "
                        :class="category.is_deleted && 'text-muted-foreground'"
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
                            <div class="grid gap-1">
                                <TagBadge
                                    :name="category.name"
                                    :color="category.color"
                                    :deleted="category.is_deleted"
                                    size="md"
                                    class="w-fit"
                                />
                                <p
                                    v-if="category.description"
                                    class="line-clamp-2 max-w-md text-xs whitespace-normal text-muted-foreground"
                                >
                                    {{ category.description }}
                                </p>
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground md:table-cell"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <SlidersHorizontal
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ rulesLabel(category.overrides_count) }}
                            </span>
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground md:table-cell"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <MessagesSquare
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ usageLabel(category.conversations_count) }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <DeletedBadge v-if="category.is_deleted" feminine />
                            <span v-else class="text-xs text-muted-foreground"
                                >Activa</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(category)"
                                :record="`la categoría ${category.name}`"
                                :busy="deletion.processingId === category.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid v-else-if="categories.data.length > 0">
                <li
                    v-for="(category, position) in categories.data"
                    :key="category.id"
                    :data-tour="position === 0 ? 'categories.list' : undefined"
                >
                    <RecordCard :muted="category.is_deleted">
                        <template #title>
                            <TagBadge
                                :name="category.name"
                                :color="category.color"
                                :deleted="category.is_deleted"
                                size="md"
                                class="max-w-full"
                            />
                        </template>
                        <template v-if="category.is_deleted" #badges>
                            <DeletedBadge feminine />
                        </template>
                        <p class="line-clamp-2 text-muted-foreground">
                            {{ category.description || 'Sin descripción.' }}
                        </p>
                        <template #meta>
                            <span class="inline-flex items-center gap-1">
                                <SlidersHorizontal
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ rulesLabel(category.overrides_count) }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <MessagesSquare
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ usageLabel(category.conversations_count) }}
                            </span>
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(category)"
                                :record="`la categoría ${category.name}`"
                                :busy="deletion.processingId === category.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>

            <EmptyState
                v-else
                class="m-4 border-none"
                :icon="FolderTree"
                :title="
                    hasFilters
                        ? 'Ninguna categoría coincide con los filtros'
                        : 'Aún no hay categorías'
                "
                :description="
                    hasFilters
                        ? 'Prueba con otros criterios de búsqueda.'
                        : can.manage
                          ? 'Crea la primera categoría para clasificar las solicitudes y, si hace falta, darle reglas de servicio propias.'
                          : 'Cuando se creen categorías aparecerán aquí.'
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
                    Nueva categoría
                </Button>
            </EmptyState>

            <div
                v-if="
                    categories.meta.last_page > 1 || categories.data.length > 0
                "
                class="border-t px-4 py-3"
            >
                <DataPagination :meta="categories.meta" noun="categorías" />
            </div>
        </SurfaceCard>

        <CategoryFormDialog
            v-if="can.manage"
            v-model:open="formOpen"
            :category="editingCategory"
            :colors="colors"
        />

        <CategoryRulesDialog
            v-if="can.manage && rulesCategory"
            v-model:open="rulesOpen"
            :category="rulesCategory"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
