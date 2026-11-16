<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    FolderTree,
    MessagesSquare,
    Pencil,
    Plus,
    SlidersHorizontal,
    Trash2,
    TrendingUp,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, reactive, ref } from 'vue';
import CategoryFormDialog from '@/components/categories/CategoryFormDialog.vue';
import CategoryRulesDialog from '@/components/categories/CategoryRulesDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import PageHeader from '@/components/PageHeader.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import '@/components/tags/tags.css';
import { Button } from '@/components/ui/button';
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { useFeatures } from '@/composables/useFeatures';
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

/* Category service rules are phase two (their endpoint answers 404 while it is off); the catalog is phase one. */
const { phaseTwo } = useFeatures();

const filterState = reactive({
    search: props.filters.search ?? '',
    record: props.filters.record,
});

/* Ranking of the categories on screen by conversations classified. */
const usage = computed(() => {
    const rows = props.categories.data
        .filter((category) => !category.is_deleted)
        .map((category) => ({
            id: category.id,
            name: category.name,
            total: category.conversations_count ?? 0,
        }))
        .filter((row) => row.total > 0)
        .sort((a, b) => b.total - a.total)
        .slice(0, 8);
    const top = rows[0]?.total ?? 0;

    return rows.map((row) => ({
        ...row,
        share: Math.max(4, Math.round((row.total / top) * 100)),
    }));
});

const hasFilters = computed(
    () => filterState.search !== '' || filterState.record !== 'active',
);

/* A filter request on its way: the rows dim and a line runs under the toolbar. */
const loading = ref(false);
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.method === 'get' && !event.detail.visit.async) {
        loading.value = true;
    }
});
const stopFinish = router.on('finish', () => (loading.value = false));

onUnmounted(() => {
    stopStart();
    stopFinish();
});

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
        phaseTwo.value &&
            category.can.update && {
                key: 'rules',
                label: 'Reglas de servicio',
                icon: SlidersHorizontal,
                onSelect: () => openRules(category),
            },
        ...deletion.actionsFor(category),
        category.can.purge && {
            key: 'purge',
            label: 'Eliminar definitivamente',
            icon: Trash2,
            destructive: true,
            onSelect: () => {
                purging.value = category;
            },
        },
    );
}

/** «Eliminar definitivamente» of a record that is already deleted. */
const purging = ref<ConversationCategorySummary | null>(null);
const purgeOpen = computed({
    get: () => purging.value !== null,
    set: (open: boolean) => {
        if (!open) {
            purging.value = null;
        }
    },
});

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
                <Button
                    class="accent-gradient glow max-sm:w-full"
                    data-tour="categories.new"
                    @click="openCreate"
                >
                    <Plus />
                    Nueva categoría
                </Button>
            </template>
        </PageHeader>

        <div
            :class="
                usage.length > 0
                    ? 'split-layout reveal items-start [--aside-w:22rem]'
                    : 'grid reveal'
            "
        >
            <DirectoryPanel :busy="loading">
                <template #toolbar>
                    <div
                        class="flex flex-wrap items-center gap-2"
                        data-tour="categories.search"
                    >
                        <FilterSearch
                            v-model="filterState.search"
                            label="Buscar categorías"
                            placeholder="Buscar por nombre o descripción"
                            :loading="loading"
                            class="basis-full sm:basis-auto"
                        />
                        <RecordStateFilter
                            v-model="filterState.record"
                            :options="recordStates"
                            class="h-10 flex-1 rounded-xl sm:w-36 sm:flex-none"
                            @change="applyFilters"
                        />
                        <ViewModeToggle v-model="viewMode" />
                    </div>
                </template>

                <ul
                    v-if="categories.data.length > 0 && viewMode === 'table'"
                    class="dir-rows"
                    role="list"
                >
                    <DirectoryRow
                        v-for="(category, position) in categories.data"
                        :key="category.id"
                        :muted="category.is_deleted"
                        class="enter-fade"
                        :style="{ '--i': Math.min(position, 10) }"
                        :data-tour="
                            position === 0 ? 'categories.list' : undefined
                        "
                    >
                        <template #leading>
                            <DirectoryAvatar
                                :name="category.name"
                                :icon="FolderTree"
                                :color="category.color"
                                :tone="category.is_deleted ? 'muted' : 'auto'"
                                shape="square"
                            />
                        </template>
                        <template #title>
                            <TagBadge
                                :name="category.name"
                                :color="category.color"
                                :deleted="category.is_deleted"
                                size="md"
                                class="max-w-full font-sans"
                            />
                        </template>
                        <template v-if="category.description" #subtitle>
                            <span class="line-clamp-2 whitespace-normal">{{
                                category.description
                            }}</span>
                        </template>
                        <template #badges>
                            <DeletedBadge v-if="category.is_deleted" feminine />
                            <span
                                class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground md:hidden"
                            >
                                <span
                                    v-if="phaseTwo"
                                    class="inline-flex items-center gap-1"
                                >
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
                                    {{
                                        usageLabel(category.conversations_count)
                                    }}
                                </span>
                            </span>
                        </template>
                        <template #aside>
                            <span
                                v-if="phaseTwo"
                                class="inline-flex w-48 items-center gap-1.5"
                                :class="
                                    category.overrides_count > 0 &&
                                    'font-medium text-foreground'
                                "
                            >
                                <SlidersHorizontal
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ rulesLabel(category.overrides_count) }}
                            </span>
                            <span
                                class="inline-flex w-40 items-center gap-1.5 tabular-nums"
                            >
                                <MessagesSquare
                                    class="size-4 shrink-0"
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
                    </DirectoryRow>
                </ul>

                <ul
                    v-else-if="categories.data.length > 0"
                    class="dir-grid"
                    role="list"
                >
                    <li
                        v-for="(category, position) in categories.data"
                        :key="category.id"
                        class="enter-spring"
                        :style="{ '--i': Math.min(position, 10) }"
                        :data-tour="
                            position === 0 ? 'categories.list' : undefined
                        "
                    >
                        <RecordCard
                            class="dir-card"
                            :muted="category.is_deleted"
                        >
                            <template #leading>
                                <DirectoryAvatar
                                    :name="category.name"
                                    :icon="FolderTree"
                                    :color="category.color"
                                    :tone="
                                        category.is_deleted ? 'muted' : 'auto'
                                    "
                                    shape="square"
                                />
                            </template>
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
                                <span
                                    v-if="phaseTwo"
                                    class="inline-flex items-center gap-1"
                                >
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
                                    {{
                                        usageLabel(category.conversations_count)
                                    }}
                                </span>
                            </template>
                            <template #actions>
                                <RecordActions
                                    :actions="actionsFor(category)"
                                    :record="`la categoría ${category.name}`"
                                    :busy="
                                        deletion.processingId === category.id
                                    "
                                />
                            </template>
                        </RecordCard>
                    </li>
                </ul>

                <FilteredEmpty
                    v-else
                    :narrowed="hasFilters"
                    narrowed-title="Ninguna categoría coincide con los filtros"
                    narrowed-description="Prueba con otra búsqueda o cambia qué categorías mostrar."
                    title="Aún no hay categorías"
                    :description="
                        can.manage
                            ? 'Crea la primera categoría para clasificar las solicitudes y, si hace falta, darle reglas de servicio propias.'
                            : 'Cuando se creen categorías aparecerán aquí.'
                    "
                    :icon="FolderTree"
                    @clear="clearFilters"
                >
                    <Button
                        v-if="can.manage"
                        class="rounded-full"
                        @click="openCreate"
                    >
                        <Plus />
                        Nueva categoría
                    </Button>
                </FilteredEmpty>

                <template v-if="categories.data.length > 0" #footer>
                    <DataPagination :meta="categories.meta" noun="categorías" />
                </template>
            </DirectoryPanel>

            <aside
                v-if="usage.length > 0"
                class="overflow-clip surface-float lg:sticky lg:top-20"
                aria-labelledby="category-usage-title"
            >
                <header class="flex items-start gap-3 px-5 pt-5 pb-4">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl accent-gradient"
                        aria-hidden="true"
                    >
                        <TrendingUp class="size-4" />
                    </span>
                    <div class="min-w-0">
                        <h2
                            id="category-usage-title"
                            class="font-display text-lg leading-snug font-semibold"
                        >
                            Más frecuentes
                        </h2>
                        <p class="text-sm text-pretty text-muted-foreground">
                            Conversaciones clasificadas con cada categoría de
                            esta lista.
                        </p>
                    </div>
                </header>
                <ol class="grid gap-3.5 px-5 pb-5">
                    <li
                        v-for="(item, position) in usage"
                        :key="item.id"
                        class="grid gap-1.5"
                    >
                        <div
                            class="flex items-baseline justify-between gap-3 text-sm"
                        >
                            <span class="flex min-w-0 items-baseline gap-2">
                                <span
                                    class="w-4 shrink-0 text-xs text-muted-foreground tabular-nums"
                                    aria-hidden="true"
                                    >{{ position + 1 }}</span
                                >
                                <span class="min-w-0 truncate font-medium">{{
                                    item.name
                                }}</span>
                            </span>
                            <span
                                class="shrink-0 font-display font-semibold tabular-nums"
                                >{{ item.total }}</span
                            >
                        </div>
                        <span
                            class="ml-6 h-2 overflow-hidden rounded-full bg-surface-sunken"
                            aria-hidden="true"
                        >
                            <span
                                class="tag-usage-bar block h-full rounded-full"
                                :style="{
                                    width: `${item.share}%`,
                                    '--i': position,
                                }"
                            />
                        </span>
                    </li>
                </ol>
            </aside>
        </div>

        <CategoryFormDialog
            v-if="can.manage"
            v-model:open="formOpen"
            :category="editingCategory"
            :colors="colors"
        />

        <CategoryRulesDialog
            v-if="phaseTwo && can.manage && rulesCategory"
            v-model:open="rulesOpen"
            :category="rulesCategory"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />

        <PermanentDeleteDialog
            v-if="purging"
            v-model:open="purgeOpen"
            title="Eliminar categoría definitivamente"
            :description="`Vas a borrar para siempre la categoría ${purging.name}.`"
            :erased="[
                'La categoría y sus reglas de servicio propias',
                'La clasificación de las conversaciones que la tenían: quedan sin categoría',
            ]"
            :kept="[
                'Las conversaciones y sus mensajes',
                'El registro en Auditoría',
            ]"
            :url="`/categories/${purging.id}/permanent`"
        />
    </div>
</template>
