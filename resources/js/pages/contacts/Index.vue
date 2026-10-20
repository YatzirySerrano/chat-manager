<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    ChevronDown,
    Contact as ContactIcon,
    Plus,
    Search,
    ShieldQuestion,
    Tags,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, reactive, ref } from 'vue';
import ContactFormDialog from '@/components/contacts/ContactFormDialog.vue';
import ContactOriginBadge from '@/components/contacts/ContactOriginBadge.vue';
import ContactTagList from '@/components/contacts/ContactTagList.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
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
import { getInitials } from '@/composables/useInitials';
import { formatDateTime, formatRelative } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { index as contactsIndex, show } from '@/routes/contacts';
import { index as reviewsIndex } from '@/routes/contacts/reviews';
import type {
    Contact,
    ContactEnumOption,
    ContactFilters,
    ContactOriginTypeValue,
    ContactSort,
    ContactSourceValue,
    ContactTag,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Contactos', href: contactsIndex() }],
    },
});

const props = defineProps<{
    contacts: Paginated<Contact>;
    filters: ContactFilters;
    tags: ContactTag[] | null;
    sources: ContactEnumOption<ContactSourceValue>[];
    originTypes: ContactEnumOption<ContactOriginTypeValue>[];
    can: { create: boolean; reviewDuplicates: boolean };
    openReviews: number | null;
}>();

/**
 * Contacts registered automatically from inbound messages appear without any
 * action: the list is reloaded periodically with the current filters, search
 * and page (Inertia slows polling down while the tab is in the background).
 */
usePoll(30_000, { only: ['contacts', 'openReviews'] });

const ALL = 'all';

const SORT_OPTIONS: { value: ContactSort; label: string }[] = [
    { value: 'recent', label: 'Última interacción' },
    { value: 'name', label: 'Nombre (A-Z)' },
    { value: 'newest', label: 'Más recientes' },
];

const filterState = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'active',
    tags: [...(props.filters.tags ?? [])] as number[],
    source: props.filters.source ?? ALL,
    origin: props.filters.origin ?? ALL,
    sort: props.filters.sort ?? 'recent',
});

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.status !== 'active' ||
        filterState.tags.length > 0 ||
        filterState.source !== ALL ||
        filterState.origin !== ALL,
);

const loading = ref(false);
const stopStart = router.on('start', (event) => {
    // Background refreshes (polling, async reloads) do not dim the table.
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
    const query: Record<string, string | number[]> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    if (filterState.status !== 'active') {
        query.status = filterState.status;
    }

    if (filterState.tags.length > 0) {
        query.tags = filterState.tags;
    }

    for (const key of ['source', 'origin'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.sort !== 'recent') {
        query.sort = filterState.sort;
    }

    router.get(contactsIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['contacts', 'filters'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function toggleTag(tagId: number, checked: boolean): void {
    filterState.tags = checked
        ? [...filterState.tags, tagId]
        : filterState.tags.filter((id) => id !== tagId);
    applyFilters();
}

function clearFilters(): void {
    filterState.search = '';
    filterState.status = 'active';
    filterState.tags = [];
    filterState.source = ALL;
    filterState.origin = ALL;
    applyFilters();
}

const selectedTagsLabel = computed(() => {
    if (filterState.tags.length === 0) {
        return 'Etiquetas';
    }

    if (filterState.tags.length === 1) {
        return (
            props.tags?.find((tag) => tag.id === filterState.tags[0])?.name ??
            '1 etiqueta'
        );
    }

    return `${filterState.tags.length} etiquetas`;
});

const formOpen = ref(false);
</script>

<template>
    <Head title="Contactos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Contactos"
            description="Personas que atiende tu equipo, con su origen, etiquetas e historial."
        >
            <template v-if="can.create || can.reviewDuplicates" #actions>
                <Button v-if="can.reviewDuplicates" variant="outline" as-child>
                    <Link :href="reviewsIndex()">
                        <ShieldQuestion />
                        Revisión de duplicados
                        <span
                            v-if="openReviews"
                            class="rounded-full bg-warning/15 px-1.5 text-xs font-medium text-foreground tabular-nums"
                            :aria-label="`${openReviews} pendientes`"
                            >{{ openReviews }}</span
                        >
                    </Link>
                </Button>
                <Button v-if="can.create" @click="formOpen = true">
                    <Plus />
                    Nuevo contacto
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard>
            <div
                class="flex flex-col gap-2 border-b p-4 lg:flex-row lg:items-center"
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
                        placeholder="Buscar por nombre, teléfono o correo"
                        class="pl-9"
                        aria-label="Buscar contactos"
                    />
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:flex">
                    <Select
                        v-model="filterState.status"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-32"
                            aria-label="Filtrar por estado"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Activos</SelectItem>
                            <SelectItem value="archived">Archivados</SelectItem>
                            <SelectItem :value="ALL">Todos</SelectItem>
                        </SelectContent>
                    </Select>

                    <DropdownMenu v-if="tags !== null">
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                class="w-full justify-between font-normal lg:w-40"
                                aria-label="Filtrar por etiquetas"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <Tags
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{
                                        selectedTagsLabel
                                    }}</span>
                                </span>
                                <ChevronDown class="opacity-50" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            class="max-h-72 w-56 overflow-y-auto"
                        >
                            <DropdownMenuLabel>
                                Con cualquiera de estas etiquetas
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <p
                                v-if="tags.length === 0"
                                class="px-2 py-1.5 text-sm text-muted-foreground"
                            >
                                Aún no hay etiquetas.
                            </p>
                            <DropdownMenuCheckboxItem
                                v-for="tag in tags"
                                :key="tag.id"
                                :model-value="filterState.tags.includes(tag.id)"
                                @update:model-value="
                                    (checked: boolean) =>
                                        toggleTag(tag.id, checked)
                                "
                                @select.prevent
                            >
                                <span
                                    class="truncate"
                                    :class="
                                        !tag.is_active &&
                                        'text-muted-foreground'
                                    "
                                    >{{ tag.name }}</span
                                >
                            </DropdownMenuCheckboxItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Select
                        v-model="filterState.source"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-40"
                            aria-label="Filtrar por fuente de registro"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Toda fuente</SelectItem>
                            <SelectItem
                                v-for="option in sources"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-model="filterState.origin"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-44"
                            aria-label="Filtrar por origen"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL">Todo origen</SelectItem>
                            <SelectItem
                                v-for="option in originTypes"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{
                                    option.value === 'unknown'
                                        ? 'No identificado'
                                        : option.label
                                }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        v-model="filterState.sort"
                        @update:model-value="applyFilters"
                    >
                        <SelectTrigger
                            class="w-full lg:w-44"
                            aria-label="Ordenar por"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in SORT_OPTIONS"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </SelectItem>
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

            <Table
                v-if="contacts.data.length > 0"
                class="transition-opacity"
                :class="loading && 'opacity-60'"
                :aria-busy="loading"
            >
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Contacto</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Teléfono</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Etiquetas</TableHead
                        >
                        <TableHead class="hidden xl:table-cell"
                            >Origen</TableHead
                        >
                        <TableHead class="hidden sm:table-cell"
                            >Última interacción</TableHead
                        >
                        <TableHead class="pr-5">Estado</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="contact in contacts.data"
                        :key="contact.id"
                        :class="
                            contact.status === 'archived' &&
                            'text-muted-foreground'
                        "
                    >
                        <TableCell class="py-3 pl-5">
                            <Link
                                :href="show(contact.id)"
                                class="group flex items-center gap-3 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <Avatar class="size-9 rounded-lg">
                                    <AvatarFallback
                                        class="rounded-lg bg-primary-soft text-xs font-semibold text-primary-soft-foreground"
                                    >
                                        {{ getInitials(contact.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <p
                                        class="truncate font-medium text-foreground group-hover:underline"
                                    >
                                        {{ contact.name }}
                                    </p>
                                    <p
                                        class="truncate text-xs text-muted-foreground"
                                    >
                                        <template
                                            v-if="
                                                contact.profile_name &&
                                                contact.profile_name !==
                                                    contact.name
                                            "
                                            >WhatsApp:
                                            {{ contact.profile_name }}
                                        </template>
                                        <template v-else-if="contact.email">{{
                                            contact.email
                                        }}</template>
                                        <span
                                            v-if="contact.phone_e164"
                                            class="md:hidden"
                                            ><template
                                                v-if="
                                                    (contact.profile_name &&
                                                        contact.profile_name !==
                                                            contact.name) ||
                                                    contact.email
                                                "
                                                >&nbsp;·&nbsp;</template
                                            >{{
                                                formatPhone(contact.phone_e164)
                                            }}</span
                                        >
                                    </p>
                                </div>
                            </Link>
                        </TableCell>
                        <TableCell
                            class="hidden font-mono text-sm tabular-nums md:table-cell"
                        >
                            {{ formatPhone(contact.phone_e164) }}
                        </TableCell>
                        <TableCell class="hidden lg:table-cell">
                            <ContactTagList
                                :tags="contact.tags ?? []"
                                :limit="3"
                            />
                        </TableCell>
                        <TableCell class="hidden xl:table-cell">
                            <ContactOriginBadge :origin="contact.origin" />
                        </TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <span
                                v-if="contact.last_interaction_at"
                                class="text-sm"
                                :title="
                                    formatDateTime(contact.last_interaction_at)
                                "
                            >
                                {{
                                    formatRelative(contact.last_interaction_at)
                                }}
                            </span>
                            <span v-else class="text-xs text-muted-foreground"
                                >Sin interacción</span
                            >
                        </TableCell>
                        <TableCell class="pr-5">
                            <StatusBadge
                                :active="contact.status === 'active'"
                                inactive-label="Archivado"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <EmptyState
                v-else
                class="m-4 border-none"
                :icon="ContactIcon"
                :title="
                    hasFilters
                        ? 'Ningún contacto coincide con los filtros'
                        : 'Aún no hay contactos'
                "
                :description="
                    hasFilters
                        ? 'Prueba con otros criterios o limpia los filtros.'
                        : 'Los contactos aparecerán cuando los registres o cuando te escriban por WhatsApp.'
                "
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    @click="clearFilters"
                    >Limpiar filtros</Button
                >
                <Button v-else-if="can.create" @click="formOpen = true">
                    <Plus />
                    Nuevo contacto
                </Button>
            </EmptyState>

            <div v-if="contacts.data.length > 0" class="border-t px-4 py-3">
                <DataPagination :meta="contacts.meta" noun="contactos" />
            </div>
        </SurfaceCard>

        <ContactFormDialog
            v-if="can.create"
            v-model:open="formOpen"
            :contact="null"
        />
    </div>
</template>
