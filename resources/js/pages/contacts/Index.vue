<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    Contact as ContactIcon,
    Ellipsis,
    Eye,
    Pencil,
    Plus,
    Search,
    ShieldQuestion,
    Tags,
    Upload,
    X,
} from '@lucide/vue';
import { useMediaQuery, watchDebounced } from '@vueuse/core';
import { computed, onUnmounted, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ContactFormDialog from '@/components/contacts/ContactFormDialog.vue';
import ContactOriginBadge from '@/components/contacts/ContactOriginBadge.vue';
import ContactTagList from '@/components/contacts/ContactTagList.vue';
import UnsavedContactBadge from '@/components/contacts/UnsavedContactBadge.vue';
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
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
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
import ViewModeToggle from '@/components/ViewModeToggle.vue';
import { getInitials } from '@/composables/useInitials';
import { useLiveReload } from '@/composables/useLiveReload';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import {
    inboxChannelNames,
    userChannelName,
} from '@/composables/useUserChannel';
import { useViewMode } from '@/composables/useViewMode';
import { contactDeletionCopy } from '@/lib/deletionCopy';
import { formatDateTime, formatRelative } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { recordActions } from '@/lib/recordActions';
import {
    destroy as destroyContact,
    index as contactsIndex,
    restore as restoreContact,
    show,
} from '@/routes/contacts';
import { create as importCreate } from '@/routes/contacts/import';
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
    RecordAction,
    RecordStateOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Contactos', href: contactsIndex() }],
    },
});

const props = defineProps<{
    contacts: Paginated<Contact>;
    filters: ContactFilters;
    recordStates: RecordStateOption[];
    tags: ContactTag[] | null;
    sources: ContactEnumOption<ContactSourceValue>[];
    originTypes: ContactEnumOption<ContactOriginTypeValue>[];
    can: { create: boolean; reviewDuplicates: boolean; import?: boolean };
    canExport?: boolean;
    openReviews: number | null;
}>();

/**
 * The directory lists saved contacts; people who wrote in and nobody saved
 * yet (provisional contacts) only appear with "Incluir no guardados". The
 * list refreshes without any action: new conversations and messages in the inboxes the user can see
 * (and finished imports) reload the list with the current filters, search
 * and page. Without real-time the list refreshes every 30 seconds.
 */
useLiveReload({
    channels: () => {
        const user = userChannelName();

        return [
            ...inboxChannelNames().map((name) => ({
                name,
                events: ['inbox.changed'],
            })),
            ...(user
                ? [
                      {
                          name: user,
                          events: ['inbox.changed', 'transfer.changed'],
                      },
                  ]
                : []),
        ];
    },
    only: ['contacts', 'openReviews'],
    filter: (event, payload) =>
        event === 'transfer.changed' ||
        payload.reason === 'created' ||
        payload.reason === 'message',
    debounceMs: 2000,
    fallbackIntervalMs: 30_000,
    safetyIntervalMs: 60_000,
});

const ALL = 'all';

const SORT_OPTIONS: { value: ContactSort; label: string }[] = [
    { value: 'recent', label: 'Última interacción' },
    { value: 'name', label: 'Nombre (A-Z)' },
    { value: 'newest', label: 'Más recientes' },
];

const filterState = reactive({
    search: props.filters.search ?? '',
    record: props.filters.record,
    tags: [...(props.filters.tags ?? [])] as number[],
    source: props.filters.source ?? ALL,
    origin: props.filters.origin ?? ALL,
    sort: props.filters.sort ?? 'recent',
    includeProvisional: props.filters.include_provisional ?? false,
});

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.record !== 'active' ||
        filterState.tags.length > 0 ||
        filterState.source !== ALL ||
        filterState.origin !== ALL ||
        filterState.includeProvisional,
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

    if (filterState.record !== 'active') {
        query.record = filterState.record;
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

    if (filterState.includeProvisional) {
        query.include_provisional = '1';
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
    filterState.record = 'active';
    filterState.tags = [];
    filterState.source = ALL;
    filterState.origin = ALL;
    filterState.includeProvisional = false;
    applyFilters();
}

function toggleProvisional(): void {
    filterState.includeProvisional = !filterState.includeProvisional;
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

const { mode: viewMode } = useViewMode('contacts');

/**
 * Phones always get the cards presentation: a table squeezed to one column
 * hides most of the data. The remembered choice still applies from `sm` up.
 */
const isPhone = useMediaQuery('(max-width: 639px)');
const effectiveViewMode = computed(() =>
    isPhone.value ? 'cards' : viewMode.value,
);

/** Secondary page actions, grouped in "Más acciones" below `lg`. */
const hasSecondaryActions = computed(
    () => props.can.reviewDuplicates || Boolean(props.can.import),
);

const formOpen = ref(false);
const editingContact = ref<Contact | null>(null);

function openCreate(): void {
    editingContact.value = null;
    formOpen.value = true;
}

function openEdit(contact: Contact): void {
    editingContact.value = contact;
    formOpen.value = true;
}

const canEditAny = computed(
    () =>
        props.can.create ||
        props.contacts.data.some((contact) => contact.can?.update),
);

const deletion = useRecordDeletion<Contact>({
    destroy: (contact) => destroyContact(contact.id),
    restore: (contact) => restoreContact(contact.id),
    copy: contactDeletionCopy,
});

function actionsFor(contact: Contact): RecordAction[] {
    return recordActions(
        contact.can?.view && {
            key: 'view',
            label: 'Ver',
            icon: Eye,
            href: show(contact.id),
        },
        contact.can?.update && {
            key: 'edit',
            label: 'Editar',
            icon: Pencil,
            onSelect: () => openEdit(contact),
        },
        ...deletion.actionsFor(contact),
    );
}

function contactSubtitle(contact: Contact): string | null {
    if (contact.profile_name && contact.profile_name !== contact.name) {
        return `WhatsApp: ${contact.profile_name}`;
    }

    return contact.email;
}
</script>

<template>
    <Head title="Contactos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            help="contactos"
            title="Contactos"
            description="Personas que atiende tu equipo, con su origen, etiquetas e historial."
        >
            <template
                v-if="
                    can.create ||
                    can.reviewDuplicates ||
                    can.import ||
                    canExport
                "
                #actions
            >
                <Button
                    v-if="can.create"
                    class="max-sm:w-full lg:order-last"
                    @click="openCreate"
                >
                    <Plus />
                    Nuevo contacto
                </Button>
                <div
                    v-if="canExport || hasSecondaryActions"
                    class="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full max-sm:*:flex-1"
                >
                    <ExportMenu
                        v-if="canExport"
                        report="contacts"
                        :filters="filters"
                    />
                    <Button
                        v-if="can.reviewDuplicates"
                        variant="outline"
                        class="hidden lg:inline-flex"
                        as-child
                    >
                        <Link :href="reviewsIndex()">
                            <ShieldQuestion />
                            Revisión de duplicados
                            <span
                                v-if="openReviews"
                                class="rounded-full bg-warning/15 px-1.5 text-xs font-medium text-foreground tabular-nums"
                                aria-hidden="true"
                                >{{ openReviews }}</span
                            >
                            <span v-if="openReviews" class="sr-only"
                                >, {{ openReviews }} pendientes</span
                            >
                        </Link>
                    </Button>
                    <Button
                        v-if="can.import"
                        variant="outline"
                        class="hidden lg:inline-flex"
                        as-child
                    >
                        <Link :href="importCreate()">
                            <Upload />
                            Importar
                        </Link>
                    </Button>
                    <DropdownMenu v-if="hasSecondaryActions">
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" class="lg:hidden">
                                <Ellipsis />
                                Más acciones
                                <span
                                    v-if="can.reviewDuplicates && openReviews"
                                    class="rounded-full bg-warning/15 px-1.5 text-xs font-medium text-foreground tabular-nums"
                                    aria-hidden="true"
                                    >{{ openReviews }}</span
                                >
                                <span
                                    v-if="can.reviewDuplicates && openReviews"
                                    class="sr-only"
                                    >, {{ openReviews }} duplicados
                                    pendientes</span
                                >
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <DropdownMenuLabel
                                class="text-xs text-muted-foreground"
                            >
                                Más acciones de contactos
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-if="can.reviewDuplicates"
                                as-child
                                class="min-h-11 sm:min-h-0"
                            >
                                <Link :href="reviewsIndex()">
                                    <ShieldQuestion aria-hidden="true" />
                                    Revisión de duplicados
                                    <span
                                        v-if="openReviews"
                                        class="ml-auto rounded-full bg-warning/15 px-1.5 text-xs font-medium text-foreground tabular-nums"
                                        aria-hidden="true"
                                        >{{ openReviews }}</span
                                    >
                                    <span v-if="openReviews" class="sr-only"
                                        >, {{ openReviews }} pendientes</span
                                    >
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="can.import"
                                as-child
                                class="min-h-11 sm:min-h-0"
                            >
                                <Link :href="importCreate()">
                                    <Upload aria-hidden="true" />
                                    Importar contactos
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
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
                        placeholder="Buscar por nombre, teléfono o correo"
                        class="pl-9 pointer-coarse:h-10"
                        aria-label="Buscar contactos"
                    />
                </div>
                <ViewModeToggle
                    v-model="viewMode"
                    class="order-2 max-sm:hidden"
                />
                <div
                    class="order-3 grid w-full grid-cols-2 gap-2 sm:grid-cols-3 lg:flex lg:flex-wrap"
                >
                    <RecordStateFilter
                        v-model="filterState.record"
                        :options="recordStates"
                        @change="applyFilters"
                    />

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
                            class="w-full *:data-[slot=select-value]:block! *:data-[slot=select-value]:truncate lg:w-40"
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
                            class="w-full *:data-[slot=select-value]:block! *:data-[slot=select-value]:truncate lg:w-44"
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
                            class="w-full *:data-[slot=select-value]:block! *:data-[slot=select-value]:truncate max-sm:col-span-2 lg:w-44"
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
                        variant="outline"
                        class="w-full lg:w-auto"
                        :class="
                            filterState.includeProvisional &&
                            'border-primary bg-primary-soft text-primary-soft-foreground'
                        "
                        :aria-pressed="filterState.includeProvisional"
                        title="Muestra también a quienes escribieron y se registraron automáticamente, pero nadie ha guardado en el directorio."
                        @click="toggleProvisional"
                    >
                        Incluir no guardados
                    </Button>

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
                v-if="contacts.data.length > 0 && effectiveViewMode === 'table'"
                class="transition-opacity"
                :class="loading && 'opacity-60'"
                :aria-busy="loading"
            >
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-5">Contacto</TableHead>
                        <TableHead class="hidden lg:table-cell"
                            >Teléfono</TableHead
                        >
                        <TableHead class="hidden xl:table-cell"
                            >Etiquetas</TableHead
                        >
                        <TableHead class="hidden 2xl:table-cell"
                            >Origen</TableHead
                        >
                        <TableHead class="hidden lg:table-cell"
                            >Última interacción</TableHead
                        >
                        <TableHead class="hidden sm:table-cell"
                            >Estado</TableHead
                        >
                        <TableHead class="pr-5 text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="contact in contacts.data"
                        :key="contact.id"
                        :class="contact.is_deleted && 'text-muted-foreground'"
                    >
                        <TableCell class="w-full max-w-0 py-3 pl-5">
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
                                            class="lg:hidden"
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
                            <DeletedBadge
                                v-if="contact.is_deleted"
                                class="mt-1 ml-12 sm:hidden"
                            />
                            <UnsavedContactBadge
                                v-else-if="!contact.is_saved"
                                class="mt-1 ml-12 sm:hidden"
                            />
                        </TableCell>
                        <TableCell
                            class="hidden font-mono text-sm tabular-nums lg:table-cell"
                        >
                            {{ formatPhone(contact.phone_e164) }}
                        </TableCell>
                        <TableCell class="hidden xl:table-cell">
                            <ContactTagList
                                :tags="contact.tags ?? []"
                                :limit="3"
                            />
                        </TableCell>
                        <TableCell class="hidden 2xl:table-cell">
                            <ContactOriginBadge :origin="contact.origin" />
                        </TableCell>
                        <TableCell class="hidden lg:table-cell">
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
                        <TableCell class="hidden sm:table-cell">
                            <DeletedBadge v-if="contact.is_deleted" />
                            <UnsavedContactBadge
                                v-else-if="!contact.is_saved"
                            />
                            <span v-else class="text-xs text-muted-foreground"
                                >Activo</span
                            >
                        </TableCell>
                        <TableCell class="w-px pr-5 text-right">
                            <RecordActions
                                :actions="actionsFor(contact)"
                                :record="contact.name"
                                :busy="deletion.processingId === contact.id"
                            />
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <RecordGrid
                v-else-if="contacts.data.length > 0"
                class="transition-opacity"
                :class="loading && 'opacity-60'"
                :aria-busy="loading"
            >
                <li v-for="contact in contacts.data" :key="contact.id">
                    <RecordCard
                        :muted="contact.is_deleted"
                        :href="show(contact.id)"
                        :label="`Ver contacto ${contact.name}`"
                    >
                        <template #leading>
                            <Avatar class="size-9 rounded-lg">
                                <AvatarFallback
                                    class="rounded-lg bg-primary-soft text-xs font-semibold text-primary-soft-foreground"
                                >
                                    {{ getInitials(contact.name) }}
                                </AvatarFallback>
                            </Avatar>
                        </template>
                        <template #title>
                            <span class="block truncate">{{
                                contact.name
                            }}</span>
                        </template>
                        <template #subtitle>
                            <span class="font-mono tabular-nums">{{
                                formatPhone(contact.phone_e164)
                            }}</span>
                        </template>
                        <template #badges>
                            <DeletedBadge v-if="contact.is_deleted" />
                            <UnsavedContactBadge
                                v-else-if="!contact.is_saved"
                            />
                            <ContactOriginBadge :origin="contact.origin" />
                        </template>
                        <p
                            v-if="contactSubtitle(contact)"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ contactSubtitle(contact) }}
                        </p>
                        <ContactTagList
                            v-if="(contact.tags ?? []).length > 0"
                            :tags="contact.tags ?? []"
                            :limit="3"
                        />
                        <template #meta>
                            <span
                                v-if="contact.last_interaction_at"
                                :title="
                                    formatDateTime(contact.last_interaction_at)
                                "
                                >Última interacción
                                {{
                                    formatRelative(contact.last_interaction_at)
                                }}</span
                            >
                            <span v-else>Sin interacción</span>
                        </template>
                        <template #actions>
                            <RecordActions
                                :actions="actionsFor(contact)"
                                :record="contact.name"
                                :busy="deletion.processingId === contact.id"
                            />
                        </template>
                    </RecordCard>
                </li>
            </RecordGrid>

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
                <Button v-else-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo contacto
                </Button>
            </EmptyState>

            <div v-if="contacts.data.length > 0" class="border-t px-4 py-3">
                <DataPagination :meta="contacts.meta" noun="contactos" />
            </div>
        </SurfaceCard>

        <ContactFormDialog
            v-if="canEditAny"
            v-model:open="formOpen"
            :contact="editingContact"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
