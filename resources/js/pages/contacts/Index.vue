<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import {
    ArrowDownUp,
    ChevronDown,
    Contact as ContactIcon,
    Ellipsis,
    Eye,
    MessageSquarePlus,
    Pencil,
    Plus,
    ShieldQuestion,
    Tags,
    Upload,
    UserRoundSearch,
} from "@lucide/vue";
import { useMediaQuery, watchDebounced } from "@vueuse/core";
import { computed, onUnmounted, reactive, ref } from "vue";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
import ContactFormDialog from "@/components/contacts/ContactFormDialog.vue";
import ContactOriginBadge from "@/components/contacts/ContactOriginBadge.vue";
import ContactTagList from "@/components/contacts/ContactTagList.vue";
import UnsavedContactBadge from "@/components/contacts/UnsavedContactBadge.vue";
import DataPagination from "@/components/DataPagination.vue";
import DeletedBadge from "@/components/DeletedBadge.vue";
import DepartmentTag from "@/components/DepartmentTag.vue";
import DirectoryPanel from "@/components/directory/DirectoryPanel.vue";
import DirectoryRow from "@/components/directory/DirectoryRow.vue";
import ExportMenu from "@/components/ExportMenu.vue";
import ActiveFilters from "@/components/filters/ActiveFilters.vue";
import FilteredEmpty from "@/components/filters/FilteredEmpty.vue";
import FilterSearch from "@/components/filters/FilterSearch.vue";
import FilterTabs from "@/components/filters/FilterTabs.vue";
import FilterToggle from "@/components/filters/FilterToggle.vue";
import HighlightMatch from "@/components/filters/HighlightMatch.vue";
import ListAvatar from "@/components/filters/ListAvatar.vue";
import type {
    ActiveFilterPill,
    FilterTabOption,
} from "@/components/filters/types";
import PageHeader from "@/components/PageHeader.vue";
import StartChatDialog from "@/components/conversations/StartChatDialog.vue";
import RecordActions from "@/components/RecordActions.vue";
import RecordCard from "@/components/RecordCard.vue";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import ViewModeToggle from "@/components/ViewModeToggle.vue";
import { useLiveReload } from "@/composables/useLiveReload";
import { useRecordDeletion } from "@/composables/useRecordDeletion";
import {
    inboxChannelNames,
    userChannelName,
} from "@/composables/useUserChannel";
import { useViewMode } from "@/composables/useViewMode";
import { contactDeletionCopy } from "@/lib/deletionCopy";
import { formatDateTime, formatRelative } from "@/lib/format";
import { usePermissions } from "@/composables/usePermissions";
import { formatPhone } from "@/lib/phone";
import { recordActions } from "@/lib/recordActions";
import {
    destroy as destroyContact,
    index as contactsIndex,
    restore as restoreContact,
    show,
} from "@/routes/contacts";
import { create as importCreate } from "@/routes/contacts/import";
import { index as reviewsIndex } from "@/routes/contacts/reviews";
import type {
    ConnectionChannel,
    Contact,
    ContactDepartmentOption,
    ContactEnumOption,
    ContactFilters,
    ContactOriginTypeValue,
    ContactSort,
    ContactSourceValue,
    ContactTag,
    Paginated,
    RecordAction,
    RecordStateOption,
    RecordStateValue,
} from "@/types";

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Contactos", href: contactsIndex() }],
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
    /** Departments the contact form offers. */
    departments: ContactDepartmentOption[];
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
                events: ["inbox.changed"],
            })),
            ...(user
                ? [
                      {
                          name: user,
                          events: ["inbox.changed", "transfer.changed"],
                      },
                  ]
                : []),
        ];
    },
    only: ["contacts", "openReviews"],
    filter: (event, payload) =>
        event === "transfer.changed" ||
        payload.reason === "created" ||
        payload.reason === "message",
    debounceMs: 2000,
    fallbackIntervalMs: 30_000,
    safetyIntervalMs: 60_000,
});

const ALL = "all";

const SORT_OPTIONS: { value: ContactSort; label: string }[] = [
    { value: "recent", label: "Última interacción" },
    { value: "name", label: "Nombre (A-Z)" },
    { value: "newest", label: "Más recientes" },
];

const filterState = reactive({
    search: props.filters.search ?? "",
    record: props.filters.record,
    tags: [...(props.filters.tags ?? [])] as number[],
    source: props.filters.source ?? ALL,
    origin: props.filters.origin ?? ALL,
    sort: props.filters.sort ?? "recent",
    includeProvisional: props.filters.include_provisional ?? false,
});

const hasFilters = computed(
    () =>
        filterState.search !== "" ||
        filterState.record !== "active" ||
        filterState.tags.length > 0 ||
        filterState.source !== ALL ||
        filterState.origin !== ALL ||
        filterState.includeProvisional,
);

const loading = ref(false);
const stopStart = router.on("start", (event) => {
    // Background refreshes (polling, async reloads) do not dim the table.
    if (event.detail.visit.method === "get" && !event.detail.visit.async) {
        loading.value = true;
    }
});
const stopFinish = router.on("finish", () => (loading.value = false));

onUnmounted(() => {
    stopStart();
    stopFinish();
});

let lastRequested: string | null = null;

function applyFilters(): void {
    const query: Record<string, string | number[]> = {};

    if (filterState.search.trim() !== "") {
        query.search = filterState.search.trim();
    }

    if (filterState.record !== "active") {
        query.record = filterState.record;
    }

    if (filterState.tags.length > 0) {
        query.tags = filterState.tags;
    }

    for (const key of ["source", "origin"] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    if (filterState.sort !== "recent") {
        query.sort = filterState.sort;
    }

    if (filterState.includeProvisional) {
        query.include_provisional = "1";
    }

    const url = contactsIndex.url({ query });

    // Clearing the search applies at once and again when the debounce fires:
    // the same list is never requested twice in a row.
    if (url === lastRequested) {
        return;
    }

    lastRequested = url;

    router.get(url, undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ["contacts", "filters"],
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
    filterState.search = "";
    filterState.record = "active";
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
        return "Etiquetas";
    }

    if (filterState.tags.length === 1) {
        return (
            props.tags?.find((tag) => tag.id === filterState.tags[0])?.name ??
            "1 etiqueta"
        );
    }

    return `${filterState.tags.length} etiquetas`;
});

/* Activos / Eliminados / Todos as tabs (only when deleted ones may be seen). */
const recordTabs = computed<FilterTabOption<RecordStateValue>[]>(() =>
    props.recordStates.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);

function setRecord(value: RecordStateValue): void {
    filterState.record = value;
    applyFilters();
}

/* The chosen tags, as removable pills under the toolbar. */
const tagPills = computed<ActiveFilterPill[]>(() =>
    filterState.tags.map((id) => ({
        key: `tag-${id}`,
        label: props.tags?.find((tag) => tag.id === id)?.name ?? "Etiqueta",
        remove: () => toggleTag(id, false),
    })),
);

/* The search the list on screen answers, to mark it in the names. */
const appliedSearch = computed(() => props.filters.search ?? "");

const CHANNELS: ConnectionChannel[] = [
    "whatsapp",
    "messenger",
    "instagram",
    "telegram",
    "web_chat",
];

function channelOf(contact: Contact): ConnectionChannel | null {
    const channel = contact.identities?.[0]?.channel;

    return CHANNELS.includes(channel as ConnectionChannel)
        ? (channel as ConnectionChannel)
        : null;
}

const { mode: viewMode } = useViewMode("contacts");

/**
 * Phones always get the cards presentation: a table squeezed to one column
 * hides most of the data. The remembered choice still applies from `sm` up.
 */
const isPhone = useMediaQuery("(max-width: 639px)");
const effectiveViewMode = computed(() =>
    isPhone.value ? "cards" : viewMode.value,
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

/* «Iniciar chat»: write first to the contact (or a number) by WhatsApp. */
const { can: holds } = usePermissions();
const canStartChat = computed(() => holds("conversations.reply"));
const startChatOpen = ref(false);
const startChatContact = ref<{
    id: number;
    name: string;
    phone: string | null;
} | null>(null);

function openStartChat(contact: Contact | null): void {
    startChatContact.value = contact
        ? {
              id: contact.id,
              name: contact.name,
              phone: contact.phone_e164
                  ? formatPhone(contact.phone_e164)
                  : null,
          }
        : null;
    startChatOpen.value = true;
}

function actionsFor(contact: Contact): RecordAction[] {
    return recordActions(
        contact.can?.view && {
            key: "view",
            label: "Ver",
            icon: Eye,
            href: show(contact.id),
        },
        canStartChat.value &&
            !contact.is_deleted &&
            contact.phone_e164 !== null && {
                key: "start-chat",
                label: "Iniciar chat",
                icon: MessageSquarePlus,
                onSelect: () => openStartChat(contact),
            },
        contact.can?.update && {
            key: "edit",
            label: "Editar",
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

    <div class="page-shell">
        <PageHeader
            help="contactos"
            title="Contactos"
            description="Personas que atiende tu equipo, con su origen, etiquetas e historial."
        >
            <template
                v-if="
                    canStartChat ||
                    can.create ||
                    can.reviewDuplicates ||
                    can.import ||
                    canExport
                "
                #actions
            >
                <Button
                    v-if="canStartChat"
                    variant="outline"
                    class="max-sm:w-full"
                    data-start-chat
                    @click="openStartChat(null)"
                >
                    <MessageSquarePlus />
                    Iniciar chat
                </Button>
                <Button
                    v-if="can.create"
                    class="max-sm:w-full lg:order-last"
                    data-tour="contacts.new"
                    @click="openCreate"
                >
                    <Plus />
                    Nuevo contacto
                </Button>
                <div
                    v-if="canExport || hasSecondaryActions"
                    data-tour="contacts.more"
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
                                class="rounded-full bg-warning-soft px-1.5 text-xs font-semibold text-warning-soft-foreground tabular-nums"
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
                            <Button
                                variant="outline"
                                class="lg:hidden"
                                data-tour="contacts.more-mobile"
                            >
                                <Ellipsis />
                                Más acciones
                                <span
                                    v-if="can.reviewDuplicates && openReviews"
                                    class="rounded-full bg-warning-soft px-1.5 text-xs font-semibold text-warning-soft-foreground tabular-nums"
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
                                        class="ml-auto rounded-full bg-warning-soft px-1.5 text-xs font-semibold text-warning-soft-foreground tabular-nums"
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

        <DirectoryPanel class="reveal" :busy="loading">
            <template #toolbar>
                <div class="grid gap-2" data-tour="contacts.search">
                    <div class="flex flex-wrap items-center gap-2">
                        <FilterSearch
                            v-model="filterState.search"
                            label="Buscar contactos"
                            placeholder="Buscar por nombre, teléfono o correo"
                            :loading="loading"
                            class="max-sm:basis-full"
                        />

                        <FilterTabs
                            v-if="recordTabs.length > 0"
                            :model-value="filterState.record"
                            :options="recordTabs"
                            label="Mostrar activos o eliminados"
                            class="max-sm:w-full"
                            @update:model-value="setRecord"
                        />

                        <Select
                            v-model="filterState.sort"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="border-transparent bg-transparent shadow-none hover:bg-accent *:data-[slot=select-value]:block! *:data-[slot=select-value]:truncate max-sm:flex-1 sm:w-auto"
                                aria-label="Ordenar por"
                            >
                                <ArrowDownUp
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent align="end">
                                <SelectItem
                                    v-for="option in SORT_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <ViewModeToggle
                            v-model="viewMode"
                            class="max-sm:hidden"
                        />
                    </div>

                    <div
                        class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center"
                    >
                        <DropdownMenu v-if="tags !== null">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="outline"
                                    class="w-full justify-between font-normal sm:w-auto sm:max-w-48"
                                    :class="
                                        filterState.tags.length > 0 &&
                                        'border-primary/40 text-foreground'
                                    "
                                    aria-label="Filtrar por etiquetas"
                                >
                                    <span
                                        class="flex min-w-0 items-center gap-2"
                                    >
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
                                align="start"
                                class="max-h-72 w-60 overflow-y-auto"
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
                                    :model-value="
                                        filterState.tags.includes(tag.id)
                                    "
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
                                class="w-full *:data-[slot=select-value]:block! *:data-[slot=select-value]:truncate sm:w-auto sm:max-w-52"
                                :class="
                                    filterState.source !== ALL &&
                                    'border-primary/40'
                                "
                                aria-label="Cómo se agregó (a mano, importación, al escribir)"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Cualquier forma de alta</SelectItem
                                >
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
                                class="w-full *:data-[slot=select-value]:block! *:data-[slot=select-value]:truncate sm:w-auto sm:max-w-52"
                                :class="
                                    filterState.origin !== ALL &&
                                    'border-primary/40'
                                "
                                aria-label="Cómo nos conoció (anuncio, sitio web, QR…)"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Cualquier procedencia</SelectItem
                                >
                                <SelectItem
                                    v-for="option in originTypes"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{
                                        option.value === "unknown"
                                            ? "No identificado"
                                            : option.label
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <FilterToggle
                            :pressed="filterState.includeProvisional"
                            label="Incluir no guardados"
                            :icon="UserRoundSearch"
                            title="Muestra también a quienes escribieron y se registraron automáticamente, pero nadie ha guardado en el directorio."
                            class="justify-center max-sm:h-9"
                            @toggle="toggleProvisional"
                        />
                    </div>

                    <ActiveFilters
                        v-if="hasFilters"
                        :pills="tagPills"
                        clearable
                        @clear="clearFilters"
                    />
                </div>
            </template>

            <ul
                v-if="contacts.data.length > 0 && effectiveViewMode === 'table'"
                role="list"
                class="dir-rows"
                aria-label="Contactos"
            >
                <DirectoryRow
                    v-for="(contact, position) in contacts.data"
                    :key="contact.id"
                    :href="
                        contact.can?.view === false
                            ? undefined
                            : show(contact.id)
                    "
                    :label="`Ver contacto ${contact.name}`"
                    :muted="contact.is_deleted"
                    :data-tour="position === 0 ? 'contacts.list' : undefined"
                >
                    <template #leading>
                        <ListAvatar
                            :name="contact.name"
                            :channel="channelOf(contact)"
                            :deleted="contact.is_deleted"
                            :dimmed="!contact.is_saved"
                        />
                    </template>
                    <template #title>
                        <HighlightMatch
                            :text="contact.name"
                            :query="appliedSearch"
                        />
                    </template>
                    <template #subtitle>
                        <span
                            v-if="contact.phone_e164"
                            class="font-mono tabular-nums"
                            >{{ formatPhone(contact.phone_e164) }}</span
                        >
                        <template v-if="contactSubtitle(contact)">
                            <span
                                v-if="contact.phone_e164"
                                class="px-1.5"
                                aria-hidden="true"
                                >·</span
                            >{{ contactSubtitle(contact) }}
                        </template>
                    </template>
                    <template
                        v-if="
                            contact.is_deleted ||
                            !contact.is_saved ||
                            contact.origin ||
                            contact.department ||
                            (contact.tags ?? []).length > 0
                        "
                        #badges
                    >
                        <DeletedBadge v-if="contact.is_deleted" />
                        <UnsavedContactBadge v-else-if="!contact.is_saved" />
                        <ContactOriginBadge
                            v-if="contact.origin"
                            :origin="contact.origin"
                        />
                        <DepartmentTag
                            v-if="contact.department"
                            :name="contact.department.name"
                            :color="contact.department.color"
                        />
                        <ContactTagList
                            v-if="(contact.tags ?? []).length > 0"
                            :tags="contact.tags ?? []"
                            :limit="3"
                        />
                    </template>
                    <template #aside>
                        <span class="w-32 truncate text-right text-xs">
                            <span class="sr-only">Última interacción: </span>
                            <time
                                v-if="contact.last_interaction_at"
                                class="text-foreground/80 tabular-nums"
                                :datetime="contact.last_interaction_at"
                                :title="`Última interacción: ${formatDateTime(contact.last_interaction_at)}`"
                                >{{
                                    formatRelative(contact.last_interaction_at)
                                }}</time
                            >
                            <span v-else>Sin interacción</span>
                        </span>
                    </template>
                    <template #actions>
                        <RecordActions
                            :actions="actionsFor(contact)"
                            :record="contact.name"
                            :busy="deletion.processingId === contact.id"
                        />
                    </template>
                </DirectoryRow>
            </ul>

            <ul
                v-else-if="contacts.data.length > 0"
                role="list"
                class="dir-grid"
                aria-label="Contactos"
            >
                <li
                    v-for="(contact, position) in contacts.data"
                    :key="contact.id"
                    class="enter-spring"
                    :style="{ '--i': Math.min(position, 10) }"
                    :data-tour="position === 0 ? 'contacts.list' : undefined"
                >
                    <RecordCard
                        class="dir-card"
                        :muted="contact.is_deleted"
                        :href="
                            contact.can?.view === false
                                ? undefined
                                : show(contact.id)
                        "
                        :label="`Ver contacto ${contact.name}`"
                    >
                        <template #leading>
                            <ListAvatar
                                :name="contact.name"
                                :channel="channelOf(contact)"
                                :deleted="contact.is_deleted"
                                :dimmed="!contact.is_saved"
                            />
                        </template>
                        <template #title>
                            <span class="block truncate font-display"
                                ><HighlightMatch
                                    :text="contact.name"
                                    :query="appliedSearch"
                            /></span>
                        </template>
                        <template #subtitle>
                            <span class="font-mono tabular-nums">{{
                                formatPhone(contact.phone_e164)
                            }}</span>
                        </template>
                        <template
                            v-if="
                                contact.is_deleted ||
                                !contact.is_saved ||
                                contact.origin
                            "
                            #badges
                        >
                            <DeletedBadge v-if="contact.is_deleted" />
                            <UnsavedContactBadge
                                v-else-if="!contact.is_saved"
                            />
                            <ContactOriginBadge
                                v-if="contact.origin"
                                :origin="contact.origin"
                            />
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
            </ul>

            <FilteredEmpty
                v-else
                :narrowed="hasFilters"
                narrowed-title="Ningún contacto coincide con los filtros"
                narrowed-description="Prueba con otros criterios o limpia los filtros."
                title="Aún no hay contactos"
                description="Aparecerán cuando los registres o cuando te escriban por WhatsApp, Messenger, Instagram o el chat web."
                :icon="ContactIcon"
                @clear="clearFilters"
            >
                <Button v-if="can.create" @click="openCreate">
                    <Plus />
                    Nuevo contacto
                </Button>
            </FilteredEmpty>

            <template v-if="contacts.data.length > 0" #footer>
                <DataPagination :meta="contacts.meta" noun="contactos" />
            </template>
        </DirectoryPanel>

        <ContactFormDialog
            v-if="canEditAny"
            v-model:open="formOpen"
            :contact="editingContact"
            :departments="departments"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
        <StartChatDialog
            v-if="canStartChat"
            v-model:open="startChatOpen"
            :contact="startChatContact"
        />
    </div>
</template>
