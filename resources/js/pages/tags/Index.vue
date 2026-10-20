<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    MessagesSquare,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Search,
    Tag as TagIcon,
    UsersRound,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import TagFormDialog from '@/components/tags/TagFormDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { index as tagsIndex } from '@/routes/tags';
import { update as updateStatus } from '@/routes/tags/status';
import type { ColorSwatch, Paginated, TagFilters, TagSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Etiquetas', href: tagsIndex() }],
    },
});

const props = defineProps<{
    tags: Paginated<TagSummary>;
    filters: TagFilters;
    colors: ColorSwatch[];
    can: { manage: boolean };
}>();

const ALL = 'all';

const filterState = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? ALL,
});

const hasFilters = computed(
    () => filterState.search !== '' || filterState.status !== ALL,
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    if (filterState.status !== ALL) {
        query.status = filterState.status;
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
    filterState.status = ALL;
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

const pendingDeactivation = ref<TagSummary | null>(null);
const confirmOpen = ref(false);
const processingId = ref<number | null>(null);

function showFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function changeStatus(tag: TagSummary, active: boolean): void {
    router.visit(updateStatus(tag.id), {
        data: { is_active: active },
        preserveScroll: true,
        onStart: () => (processingId.value = tag.id),
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (processingId.value = null),
        onError: showFirstError,
    });
}

function askDeactivation(tag: TagSummary): void {
    pendingDeactivation.value = tag;
    confirmOpen.value = true;
}

function confirmDeactivation(): void {
    if (pendingDeactivation.value) {
        changeStatus(pendingDeactivation.value, false);
    }
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
            <template v-if="can.manage" #actions>
                <Button @click="openCreate">
                    <Plus />
                    Nueva etiqueta
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard>
            <div
                class="flex flex-col gap-2 border-b p-4 sm:flex-row sm:items-center"
                role="search"
            >
                <div class="relative flex-1">
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
                <div class="flex gap-2">
                    <Select
                        v-model="filterState.status"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full sm:w-36"
                            aria-label="Filtrar por estado"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Todas</SelectItem>
                            <SelectItem value="active">Activas</SelectItem>
                            <SelectItem value="inactive">Inactivas</SelectItem>
                        </SelectContent>
                    </Select>
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

            <Table v-if="tags.data.length > 0">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Etiqueta</TableHead>
                        <TableHead class="hidden md:table-cell">Uso</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead v-if="can.manage" class="w-14 pr-5">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="tag in tags.data"
                        :key="tag.id"
                        :class="!tag.is_active && 'text-muted-foreground'"
                    >
                        <TableCell class="py-3 pl-5">
                            <div class="grid gap-1">
                                <TagBadge
                                    :name="tag.name"
                                    :color="tag.color"
                                    :inactive="!tag.is_active"
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
                            <StatusBadge
                                :active="tag.is_active"
                                active-label="Activa"
                                inactive-label="Inactiva"
                            />
                        </TableCell>
                        <TableCell v-if="can.manage" class="pr-5 text-right">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        :disabled="processingId === tag.id"
                                        :aria-label="`Acciones para ${tag.name}`"
                                    >
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-44">
                                    <DropdownMenuItem @select="openEdit(tag)">
                                        <Pencil />
                                        Editar
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!tag.is_active"
                                        @select="changeStatus(tag, true)"
                                    >
                                        <Power />
                                        Activar
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-else
                                        @select="askDeactivation(tag)"
                                    >
                                        <PowerOff />
                                        Desactivar
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

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
            v-model:open="confirmOpen"
            :title="`¿Desactivar la etiqueta ${pendingDeactivation?.name ?? ''}?`"
            description="Ya no podrá asignarse a nuevos contactos ni conversaciones. Los registros que ya la tienen la conservan."
            confirm-label="Desactivar"
            destructive
            :processing="processingId !== null"
            @confirm="confirmDeactivation"
        />
    </div>
</template>
