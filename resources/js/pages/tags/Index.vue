<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    MessagesSquare,
    Pencil,
    Plus,
    Tag as TagIcon,
    Trash2,
    UsersRound,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryPanel from '@/components/directory/DirectoryPanel.vue';
import DirectoryRow from '@/components/directory/DirectoryRow.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import PageHeader from '@/components/PageHeader.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import RecordActions from '@/components/RecordActions.vue';
import RecordCard from '@/components/RecordCard.vue';
import RecordStateFilter from '@/components/RecordStateFilter.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import TagFormDialog from '@/components/tags/TagFormDialog.vue';
import '@/components/tags/tags.css';
import { Button } from '@/components/ui/button';
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

/* Ranking of the tags on screen by how many records carry them. */
const usage = computed(() => {
    const rows = props.tags.data
        .filter((tag) => !tag.is_deleted)
        .map((tag) => ({
            id: tag.id,
            name: tag.name,
            total: (tag.contacts_count ?? 0) + (tag.conversations_count ?? 0),
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
        tag.can.purge && {
            key: 'purge',
            label: 'Eliminar definitivamente',
            icon: Trash2,
            destructive: true,
            onSelect: () => {
                purging.value = tag;
            },
        },
    );
}

/** «Eliminar definitivamente» of a record that is already deleted. */
const purging = ref<TagSummary | null>(null);
const purgeOpen = computed({
    get: () => purging.value !== null,
    set: (open: boolean) => {
        if (!open) {
            purging.value = null;
        }
    },
});

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

    <div class="page-shell">
        <PageHeader
            help="etiquetas"
            title="Etiquetas"
            description="Clasifica contactos y conversaciones con etiquetas de color. Solo clasifican: no otorgan permisos ni cambian estados."
        >
            <template v-if="can.manage || canExport" #actions>
                <ExportMenu v-if="canExport" report="tags" :filters="filters" />
                <Button
                    v-if="can.manage"
                    class="max-sm:w-full"
                    data-tour="tags.new"
                    @click="openCreate"
                >
                    <Plus />
                    Nueva etiqueta
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
                        data-tour="tags.search"
                    >
                        <FilterSearch
                            v-model="filterState.search"
                            label="Buscar etiquetas"
                            placeholder="Buscar por nombre o descripción"
                            :loading="loading"
                            class="basis-full sm:basis-auto"
                        />
                        <RecordStateFilter
                            v-model="filterState.record"
                            :options="recordStates"
                            class="flex-1 sm:w-36 sm:flex-none"
                            @change="applyFilters"
                        />
                        <ViewModeToggle v-model="viewMode" />
                    </div>
                </template>

                <ul
                    v-if="tags.data.length > 0 && viewMode === 'table'"
                    class="dir-rows"
                    role="list"
                >
                    <DirectoryRow
                        v-for="(tag, position) in tags.data"
                        :key="tag.id"
                        :muted="tag.is_deleted"
                        class="enter-fade"
                        :style="{ '--i': Math.min(position, 10) }"
                        :data-tour="position === 0 ? 'tags.list' : undefined"
                    >
                        <template #leading>
                            <DirectoryAvatar
                                :name="tag.name"
                                :icon="TagIcon"
                                :color="tag.color"
                                :tone="tag.is_deleted ? 'muted' : 'auto'"
                                shape="square"
                            />
                        </template>
                        <template #title>
                            <TagBadge
                                :name="tag.name"
                                :color="tag.color"
                                :deleted="tag.is_deleted"
                                size="md"
                                class="max-w-full font-sans"
                            />
                        </template>
                        <template v-if="tag.description" #subtitle>
                            <span class="line-clamp-2 whitespace-normal">{{
                                tag.description
                            }}</span>
                        </template>
                        <template #badges>
                            <DeletedBadge v-if="tag.is_deleted" feminine />
                            <span
                                class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground md:hidden"
                            >
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
                            </span>
                        </template>
                        <template #aside>
                            <span
                                class="inline-flex w-32 items-center gap-1.5 tabular-nums"
                            >
                                <UsersRound
                                    class="size-4 shrink-0"
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
                            <span
                                class="inline-flex w-40 items-center gap-1.5 tabular-nums"
                            >
                                <MessagesSquare
                                    class="size-4 shrink-0"
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
                    </DirectoryRow>
                </ul>

                <ul
                    v-else-if="tags.data.length > 0"
                    class="dir-grid"
                    role="list"
                >
                    <li
                        v-for="(tag, position) in tags.data"
                        :key="tag.id"
                        class="enter-spring"
                        :style="{ '--i': Math.min(position, 10) }"
                        :data-tour="position === 0 ? 'tags.list' : undefined"
                    >
                        <RecordCard class="dir-card" :muted="tag.is_deleted">
                            <template #leading>
                                <DirectoryAvatar
                                    :name="tag.name"
                                    :icon="TagIcon"
                                    :color="tag.color"
                                    :tone="tag.is_deleted ? 'muted' : 'auto'"
                                    shape="square"
                                />
                            </template>
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
                </ul>

                <FilteredEmpty
                    v-else
                    :narrowed="hasFilters"
                    narrowed-title="Ninguna etiqueta coincide con los filtros"
                    narrowed-description="Prueba con otra búsqueda o cambia qué etiquetas mostrar."
                    title="Aún no hay etiquetas"
                    :description="
                        can.manage
                            ? 'Crea la primera etiqueta para clasificar contactos y conversaciones.'
                            : 'Cuando se creen etiquetas aparecerán aquí.'
                    "
                    :icon="TagIcon"
                    @clear="clearFilters"
                >
                    <Button
                        v-if="can.manage"
                        class="rounded-full"
                        @click="openCreate"
                    >
                        <Plus />
                        Nueva etiqueta
                    </Button>
                </FilteredEmpty>

                <template v-if="tags.data.length > 0" #footer>
                    <DataPagination :meta="tags.meta" noun="etiquetas" />
                </template>
            </DirectoryPanel>

            <aside
                v-if="usage.length > 0"
                class="overflow-clip surface-float lg:sticky lg:top-20"
                aria-labelledby="tag-usage-title"
            >
                <header class="px-5 pt-4 pb-3">
                    <div class="min-w-0">
                        <h2
                            id="tag-usage-title"
                            class="font-display text-lg leading-snug font-semibold"
                        >
                            Más usadas
                        </h2>
                        <p class="text-sm text-pretty text-muted-foreground">
                            Contactos y conversaciones que llevan cada etiqueta
                            de esta lista.
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

        <PermanentDeleteDialog
            v-if="purging"
            v-model:open="purgeOpen"
            title="Eliminar etiqueta definitivamente"
            :description="`Vas a borrar para siempre la etiqueta ${purging.name}.`"
            :erased="[
                'La etiqueta',
                'Su asignación en todos los contactos y conversaciones que todavía la tenían',
            ]"
            :kept="[
                'Los contactos y las conversaciones',
                'El registro en Auditoría',
            ]"
            :url="`/tags/${purging.id}/permanent`"
        />
    </div>
</template>
