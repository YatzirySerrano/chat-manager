<script setup lang="ts">
import {
    CircleCheck,
    Hourglass,
    Inbox,
    MailWarning,
    MessageCircleReply,
} from '@lucide/vue';
import { useDebounceFn, useMediaQuery, watchDebounced } from '@vueuse/core';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import AcceptBlockNotice from '@/components/conversations/AcceptBlockNotice.vue';
import InboxFilterPanel from '@/components/conversations/InboxFilterPanel.vue';
import InboxFilterSurface from '@/components/conversations/InboxFilterSurface.vue';
import {
    MAX_FILTER_TAGS,
    isClosedStatus,
} from '@/components/conversations/inboxFilters';
import type { InboxFilterState } from '@/components/conversations/inboxFilters';
import InboxListItem from '@/components/conversations/InboxListItem.vue';
import InboxTagsDialog from '@/components/conversations/InboxTagsDialog.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import FilterToggle from '@/components/filters/FilterToggle.vue';
import type {
    ActiveFilterPill,
    FilterTabOption,
} from '@/components/filters/types';
import ListSkeleton from '@/components/filters/ListSkeleton.vue';
import { useRowMotion } from '@/components/filters/useRowMotion';
import { useSelectionGlider } from '@/components/filters/useSelectionGlider';
import { Button } from '@/components/ui/button';
import { colorPresentations } from '@/lib/colors';
import { formatPhone } from '@/lib/phone';
import type {
    AppColor,
    ConnectionChannel,
    ConversationCategoryRef,
    ConversationFilters,
    ConversationInbox,
    ConversationList,
    ConversationSearchScope,
    ConversationSort,
    ConversationStatusFilter,
    ConversationSummary,
    InboxCounts,
    InboxView,
    InboxViewer,
    TagOption,
} from '@/types';

/**
 * Left panel of the inbox, built for someone who answers all day and has to
 * narrow the list in one or two clicks (shared pattern with the internal
 * chat, components/filters):
 *
 * 1. The search box, always visible: results follow the typing (debounced),
 *    «/» or Ctrl+K jumps to it, Escape clears it and Arrow down goes to the
 *    first row. While searching, «Contacto / Mensajes» says where it looks.
 * 2. The views as tabs with live counters (FilterTabs): the base inbox
 *    («Bandeja» or «Todas»), «Pendientes» (attention tone while any waits),
 *    «En atención» (team inboxes), «Mías» and «Cerradas». They are the
 *    view + status pairs the server already offers.
 * 3. Quick toggles that combine with the tab: «Sin leer» and «Esperando
 *    respuesta» (customer wrote last), each with its counter; under
 *    «Cerradas», «Solo completadas» (closed as «Solicitud completada»).
 * 4. Everything else (department, agent, channel, connection, category,
 *    tags with OR/AND, order) lives in the «Filtros» panel
 *    (InboxFilterSurface: a popover, or a bottom sheet on phones) that edits
 *    a draft applied with «Aplicar»; applied values show as removable pills
 *    next to the toggles, with «Limpiar filtros».
 *
 * The URL stays the source of truth (query(), replace visits, so a reload
 * keeps the filters), the list is loaded with one keyset cursor and filters
 * only ever narrow what the server already authorized. The last filters are
 * not restored by themselves on a clean visit: an agent who opens the inbox
 * must always see their pending work (the server remembers the list for the
 * way back from a conversation).
 */
const props = withDefaults(
    defineProps<{
        conversations: ConversationList;
        filters: ConversationFilters;
        counts: InboxCounts;
        inbox: ConversationInbox;
        departments: {
            id: number;
            name: string;
            color?: AppColor | null;
            is_active: boolean;
        }[];
        agents: { id: number; name: string; is_active: boolean }[];
        connections: {
            id: number;
            name: string;
            display_phone_number: string | null;
        }[];
        /** Conversations with unread messages in this view and filters. */
        unreadCount: number;
        /** Conversations where the customer waits for an answer (same filters). */
        awaitingCount?: number;
        /** Channels of the viewer's connections (the filter needs more than one). */
        filterOptions: {
            channels: { value: ConnectionChannel; label: string }[];
        };
        tags: TagOption[];
        /** Active request categories (filter). */
        categories?: ConversationCategoryRef[];
        canSeeUnrouted: boolean;
        /** The viewer may see closed conversations (history permission). */
        canSeeClosed: boolean;
        canExport: boolean;
        viewer: InboxViewer;
        selectedId: number | null;
        loading: boolean;
        /** The loading in course only brings more rows (not a new filter). */
        loadingMore?: boolean;
        /** Conversation being accepted from the list, if any. */
        acceptingId: number | null;
        /** Rows offer "Asignar alias personal" (PERSONALIZATION). */
        canEditAlias?: boolean;
    }>(),
    {
        canEditAlias: false,
        categories: () => [],
        awaitingCount: 0,
        loadingMore: false,
    },
);

const emit = defineEmits<{
    select: [id: number];
    filter: [query: Record<string, string | string[]>];
    loadMore: [];
    accept: [id: number];
    markRead: [id: number];
    editAlias: [conversation: ConversationSummary];
    preview: [conversation: ConversationSummary];
}>();

const ALL = '';

function fromFilters(filters: ConversationFilters): InboxFilterState {
    return {
        view: filters.view,
        status: filters.status,
        department: filters.department ?? ALL,
        agent: filters.agent ?? ALL,
        connection:
            filters.connection === null ? ALL : String(filters.connection),
        channel: filters.channel ?? ALL,
        tags: [...(filters.tags ?? [])],
        tagMatch: filters.tag_match ?? 'any',
        sort: filters.sort,
        searchIn: filters.search_in,
        unread: filters.unread,
        awaiting: filters.awaiting ?? false,
        category:
            filters.category === null || filters.category === undefined
                ? ALL
                : String(filters.category),
        readiness: filters.readiness ?? ALL,
    };
}

const state = reactive<InboxFilterState>(fromFilters(props.filters));

/** What the "Filtros" panel edits; it reaches the list only on «Aplicar». */
const draft = reactive<InboxFilterState>(fromFilters(props.filters));

function copyOf(source: InboxFilterState): InboxFilterState {
    return { ...source, tags: [...source.tags] };
}
const search = ref(props.filters.search);

/* Follow the server when it normalizes or changes the filters. */
watch(
    () => props.filters,
    (filters) => Object.assign(state, fromFilters(filters)),
);

const defaultView = computed(() => props.inbox.views[0]);
/*
 * The view the tabs narrow by status: the default one. Supervisors (and
 * whoever does not attend) open on every authorized conversation; other
 * people who attend conversations on their general inbox.
 */
const baseView = computed<InboxView>(() =>
    defaultView.value === 'general' ? 'general' : 'all',
);
const isGeneral = computed(() => baseView.value === 'general');

/*
 * The order each view opens with (ConversationInboxQuery::defaultSort()):
 * the newest request on top, except in «Mías», where each agent works their
 * own ones oldest first.
 */
function defaultSort(view: InboxView): ConversationSort {
    return view === 'mine' ? 'oldest' : 'recent';
}
const hasMine = computed(() => props.inbox.views.includes('mine'));
const channels = computed(() => props.filterOptions.channels);
const showChannels = computed(() => channels.value.length > 1);
const showAgents = computed(
    () => props.viewer.can_supervise && !props.inbox.personal,
);

/*
 * "all" is named after the viewer's effective reach, never a vague
 * "visible": the whole organization, their departments, or only their own.
 */
const allLabel = computed(() =>
    props.inbox.scope === 'global'
        ? 'Todas las conversaciones'
        : props.inbox.scope === 'departments'
          ? 'Conversaciones de mis departamentos'
          : 'Mis conversaciones',
);

const viewLabels = computed<Record<InboxView, string>>(() => ({
    general: isGeneral.value ? 'Bandeja general' : 'Por atender',
    mine: 'Mis conversaciones',
    all: allLabel.value,
    queue: isGeneral.value ? 'Bandeja general' : 'Por atender',
}));

/* What the base tab holds, in the viewer's reach. */
const baseTitle = computed(() => {
    if (isGeneral.value) {
        return 'Todo lo que espera por ti: las pendientes que puedes aceptar y las que atiendes.';
    }

    if (props.inbox.scope === 'global') {
        return 'Las conversaciones activas de toda la organización, sin importar quién las atienda, su departamento o su conexión.';
    }

    if (props.inbox.scope === 'departments') {
        return props.inbox.supervises
            ? 'Las conversaciones activas de tus departamentos y las que pasaron por ellos, sin importar quién las atienda.'
            : 'Las conversaciones activas de tus departamentos, sin importar quién las atienda.';
    }

    return 'Las conversaciones activas que tienes asignadas.';
});

const pendingTitle = computed(() => {
    if (isGeneral.value) {
        return 'Conversaciones que esperan a que alguien de tu departamento las acepte. No incluye las que atienden tus compañeros.';
    }

    return props.inbox.scope === 'global'
        ? 'Conversaciones de toda la organización que esperan a que alguien las acepte.'
        : props.inbox.scope === 'departments'
          ? 'Conversaciones de tus departamentos que esperan a que alguien las acepte.'
          : 'Conversaciones que esperan a que alguien las acepte.';
});

const statusLabels: Record<string, string> = {
    active: 'Todas las activas',
    pending: 'Pendientes',
    open: 'En atención',
    closed: 'Cerradas',
    completed: 'Solicitudes completadas',
    all: 'Todos los estados',
};

/* Estado options; "Mis conversaciones" never holds pending ones. */
const statusOptions = computed<
    { value: ConversationStatusFilter; label: string }[]
>(() => {
    const values: ConversationStatusFilter[] = ['active'];

    if (draft.view !== 'mine') {
        values.push('pending');
    }

    values.push('open');

    if (props.canSeeClosed) {
        values.push('closed', 'completed');
    }

    return values.map((value) => ({ value, label: statusLabels[value] }));
});

const sortOptions: { value: ConversationSort; label: string }[] = [
    { value: 'recent', label: 'Actividad más reciente' },
    { value: 'priority', label: 'Pendientes primero' },
    { value: 'oldest', label: 'Esperando hace más tiempo' },
];

const searchScopes: { value: ConversationSearchScope; label: string }[] = [
    { value: 'contact', label: 'Nombre, teléfono o correo del contacto' },
    { value: 'messages', label: 'Contenido de los mensajes' },
];

const plural = (count: number, one: string, many: string): string =>
    `${count} ${count === 1 ? one : many}`;

/* ---- Tabs: the view + status pairs the server offers. ---- */

type InboxTab = 'base' | 'pending' | 'attending' | 'mine' | 'closed';

/** The tab the filters belong to, or null (a combination of the panel). */
function tabOf(filters: InboxFilterState): InboxTab | null {
    const base = filters.view === baseView.value;

    if (base && filters.status === 'active') {
        return 'base';
    }

    if (base && filters.status === 'pending') {
        return 'pending';
    }

    if (
        !isGeneral.value &&
        filters.view === 'all' &&
        filters.status === 'open'
    ) {
        return 'attending';
    }

    if (
        hasMine.value &&
        filters.view === 'mine' &&
        filters.status === 'active'
    ) {
        return 'mine';
    }

    if (props.canSeeClosed && base && isClosedStatus(filters.status)) {
        return 'closed';
    }

    return null;
}

const activeTab = computed(() => tabOf(state));
const pendingCount = computed(() => props.counts.pending);
const pendingActive = computed(() => activeTab.value === 'pending');
const generalActive = computed(() => activeTab.value === 'base');
const closedActive = computed(() => isClosedStatus(state.status));

const tabs = computed<FilterTabOption<InboxTab>[]>(() => {
    const options: FilterTabOption<InboxTab>[] = [
        {
            value: 'pending',
            label: 'Pendientes',
            icon: pendingCount.value > 0 ? Hourglass : undefined,
            count: pendingCount.value,
            attention: pendingCount.value > 0,
            title: pendingTitle.value,
            srLabel: `${pendingCount.value} por aceptar`,
            attrs: {
                'data-inbox-chip': 'pending',
                'data-tour': 'inbox.pending',
            },
        },
        {
            value: 'base',
            label: isGeneral.value ? 'Bandeja' : 'Todas',
            count: props.counts.general,
            title: `${viewLabels.value[baseView.value]}. ${baseTitle.value}`,
            srLabel: plural(
                props.counts.general,
                'conversación activa',
                'conversaciones activas',
            ),
            attrs: { 'data-inbox-chip': 'base', 'data-tour': 'inbox.general' },
        },
    ];

    if (!isGeneral.value) {
        options.push({
            value: 'attending',
            label: 'En atención',
            count: props.counts.attending,
            title: 'Conversaciones que alguien del equipo ya atiende.',
            srLabel: plural(
                props.counts.attending,
                'conversación en atención',
                'conversaciones en atención',
            ),
            attrs: { 'data-inbox-chip': 'attending' },
        });
    }

    if (hasMine.value) {
        options.push({
            value: 'mine',
            label: 'Mías',
            count: props.counts.mine,
            title: 'Las conversaciones que tienes asignadas.',
            srLabel: plural(
                props.counts.mine,
                'conversación asignada a ti',
                'conversaciones asignadas a ti',
            ),
            attrs: { 'data-inbox-chip': 'mine' },
        });
    }

    if (props.canSeeClosed) {
        options.push({
            value: 'closed',
            label: 'Cerradas',
            title: 'El historial de conversaciones cerradas, incluidas las solicitudes completadas.',
            attrs: { 'data-inbox-chip': 'closed' },
        });
    }

    return options;
});

function chooseTab(tab: InboxTab): void {
    const patches: Record<InboxTab, Partial<InboxFilterState>> = {
        base: {
            view: baseView.value,
            status: 'active',
            sort: defaultSort(baseView.value),
        },
        pending: {
            view: baseView.value,
            status: 'pending',
            sort: defaultSort(baseView.value),
        },
        attending: { view: 'all', status: 'open', sort: defaultSort('all') },
        // Their own requests, the one waiting the longest first.
        mine: { view: 'mine', status: 'active', sort: defaultSort('mine') },
        // Closed conversations are never unread nor waiting for an answer.
        closed: {
            view: baseView.value,
            status: 'closed',
            sort: defaultSort(baseView.value),
            unread: false,
            awaiting: false,
        },
    };

    // A second click on the chosen tab goes back to the default one
    // («Pendientes»).
    if (tab === activeTab.value) {
        if (tab !== 'pending') {
            change(patches.pending);
        }

        return;
    }

    change(patches[tab]);
}

/*
 * Pending conversations that arrive while the list is on screen are
 * announced politely (role=status), only when the counter goes up after a
 * live reload: a filter change (which also brings new filters) or a
 * decrease never speaks. Several increases close together become one
 * message. The region is emptied before each message, so a text equal to
 * the previous one is still a change the screen reader announces.
 */
const pendingAnnouncement = ref('');
let pendingIncrease = 0;

const announcePending = useDebounceFn(async () => {
    if (pendingIncrease > 0) {
        const message =
            pendingIncrease === 1
                ? '1 conversación pendiente nueva'
                : `${pendingIncrease} conversaciones pendientes nuevas`;

        pendingIncrease = 0;
        pendingAnnouncement.value = '';
        await nextTick();
        pendingAnnouncement.value = message;
    }
}, 1500);

watch(
    () => [props.counts.pending, props.filters] as const,
    ([pending, filters], [previousPending, previousFilters]) => {
        if (filters === previousFilters && pending > previousPending) {
            pendingIncrease += pending - previousPending;
            void announcePending();
        }
    },
);

/*
 * The server says when the message search has no word it can look for
 * (fewer than 3 letters or digits, or only very common words).
 */
const searchTooShort = computed(
    () =>
        state.searchIn === 'messages' &&
        props.filters.search_ignored &&
        props.filters.search === search.value.trim(),
);

/*
 * Filters as removable pills (of the applied state, or of the draft to
 * count them). The tabs and the toggles already show the view, the status,
 * «Sin leer», «Esperando respuesta» and the search scope, so they get no
 * pill unless the panel chose a pair no tab holds.
 */
function chipsOf(filters: InboxFilterState): ActiveFilterPill[] {
    const chips: ActiveFilterPill[] = [];
    const inTab = tabOf(filters) !== null;

    if (!inTab && filters.view !== baseView.value) {
        chips.push({
            key: 'view',
            label: `Vista: ${viewLabels.value[filters.view]}`,
            remove: () => (filters.view = baseView.value),
        });
    }

    if (!inTab && filters.status !== 'pending') {
        chips.push({
            key: 'status',
            label: `Estado: ${statusLabels[filters.status] ?? filters.status}`,
            remove: () => (filters.status = 'pending'),
        });
    }

    if (filters.department !== ALL) {
        const department = props.departments.find(
            (option) => String(option.id) === filters.department,
        );

        chips.push({
            key: 'department',
            label: `Departamento: ${filters.department === 'none' ? 'Sin departamento' : (department?.name ?? 'No disponible')}`,
            remove: () => (filters.department = ALL),
        });
    }

    if (filters.agent !== ALL && !props.inbox.personal) {
        const agent = props.agents.find(
            (option) => String(option.id) === filters.agent,
        );

        chips.push({
            key: 'agent',
            label: `Responsable: ${filters.agent === 'none' ? 'Sin agente' : (agent?.name ?? 'No disponible')}`,
            remove: () => (filters.agent = ALL),
        });
    }

    if (filters.channel !== ALL) {
        chips.push({
            key: 'channel',
            label: `Canal: ${channels.value.find((option) => option.value === filters.channel)?.label ?? filters.channel}`,
            remove: () => (filters.channel = ALL),
        });
    }

    if (filters.category !== ALL) {
        const category = props.categories.find(
            (option) => String(option.id) === filters.category,
        );

        chips.push({
            key: 'category',
            label: `Categoría: ${category?.name ?? 'No disponible'}`,
            remove: () => (filters.category = ALL),
        });
    }

    if (filters.readiness !== ALL) {
        chips.push({
            key: 'readiness',
            label:
                filters.readiness === 'needs_info'
                    ? 'Necesita información'
                    : 'Lista para atención',
            remove: () => (filters.readiness = ALL),
        });
    }

    if (filters.connection !== ALL) {
        const connection = props.connections.find(
            (option) => String(option.id) === filters.connection,
        );

        chips.push({
            key: 'connection',
            label: `Conexión: ${connection ? `${connection.name}${connection.display_phone_number ? ` · ${formatPhone(connection.display_phone_number)}` : ''}` : 'No disponible'}`,
            remove: () => (filters.connection = ALL),
        });
    }

    for (const id of filters.tags) {
        chips.push({
            key: `tag-${id}`,
            label: `Etiqueta: ${props.tags.find((tag) => tag.id === id)?.name ?? 'No disponible'}`,
            remove: () =>
                (filters.tags = filters.tags.filter((tag) => tag !== id)),
        });
    }

    // "At least one tag" is the default; only the AND mode is a pill.
    if (filters.tags.length > 1 && filters.tagMatch === 'all') {
        chips.push({
            key: 'tag-match',
            label: 'Tiene todas las etiquetas elegidas',
            remove: () => (filters.tagMatch = 'any'),
        });
    }

    if (filters.sort !== defaultSort(filters.view)) {
        chips.push({
            key: 'sort',
            label: `Orden: ${sortOptions.find((option) => option.value === filters.sort)?.label ?? filters.sort}`,
            remove: () => (filters.sort = defaultSort(filters.view)),
        });
    }

    return chips;
}

/* Applied pills: removing one applies at once. */
const appliedChips = computed<ActiveFilterPill[]>(() =>
    chipsOf(state).map((chip) => ({
        ...chip,
        remove: () => {
            chip.remove();
            apply();
            void announceFilters();
        },
    })),
);

/*
 * What the «Filtros» button counts: the panel's own filters (the pills),
 * plus «Sin leer» and «Esperando respuesta» that it also holds. The tag
 * combination mode refines the tags: it is not one more filter.
 */
const countOf = (filters: InboxFilterState): number =>
    chipsOf(filters).filter((chip) => chip.key !== 'tag-match').length +
    (filters.unread ? 1 : 0) +
    (filters.awaiting ? 1 : 0);

const activeFilterCount = computed(() => countOf(state));
const draftFilterCount = computed(() => countOf(draft));

/**
 * A search, a pill or a toggle narrows the list: an empty result then means
 * "nothing matches", never "your inbox is up to date".
 */
const isNarrowed = computed(
    () =>
        appliedChips.value.length > 0 ||
        state.unread ||
        state.awaiting ||
        state.status === 'completed' ||
        search.value.trim() !== '',
);

/* «Limpiar filtros»: anything but the default tab with nothing else. */
const hasFilters = computed(
    () => isNarrowed.value || activeTab.value !== tabOf(defaults()),
);

/*
 * The export follows the screen's filters except the personal "unread" one,
 * which depends on each person's read markers and is not part of the report.
 */
const exportFilters = computed(() => ({ ...props.filters, unread: false }));

function query(): Record<string, string | string[]> {
    const result: Record<string, string | string[]> = {};

    if (state.view !== defaultView.value) {
        result.view = state.view;
    }

    // No status is the default «Pendientes» list.
    if (state.status !== 'pending') {
        result.status = state.status;
    }

    for (const key of [
        'department',
        'agent',
        'connection',
        'channel',
        'category',
        'readiness',
    ] as const) {
        if (state[key] !== ALL && !(key === 'agent' && props.inbox.personal)) {
            result[key] = state[key];
        }
    }

    if (state.tags.length > 0) {
        result.tags = state.tags.map(String);

        if (state.tagMatch === 'all' && state.tags.length > 1) {
            result.tag_match = 'all';
        }
    }

    if (state.sort !== defaultSort(state.view)) {
        result.sort = state.sort;
    }

    if (state.unread) {
        result.unread = '1';
    }

    if (state.awaiting) {
        result.awaiting = '1';
    }

    if (search.value.trim() !== '') {
        result.search = search.value.trim();

        if (state.searchIn !== 'contact') {
            result.search_in = state.searchIn;
        }
    }

    return result;
}

function apply(): void {
    emit('filter', query());
}

/*
 * The applied filters are announced politely, in their own region (never
 * mixed with the pending conversations announcement).
 */
const filtersAnnouncement = ref('');

async function announceFilters(): Promise<void> {
    const count = activeFilterCount.value;

    filtersAnnouncement.value = '';
    await nextTick();
    filtersAnnouncement.value =
        count === 0 ? 'Sin filtros aplicados' : `Filtros aplicados: ${count}`;
}

watchDebounced(search, apply, { debounce: 300 });

function change(patch: Partial<InboxFilterState>): void {
    Object.assign(state, patch);

    // "Mis conversaciones" never holds pending ones.
    if (state.view === 'mine' && state.status === 'pending') {
        state.status = 'active';
    }

    if (
        'searchIn' in patch &&
        Object.keys(patch).length === 1 &&
        search.value.trim() === ''
    ) {
        return;
    }

    apply();
}

/* "Mis conversaciones" never holds pending ones (the draft follows it too). */
function changeDraft(patch: Partial<InboxFilterState>): void {
    Object.assign(draft, patch);

    if (draft.view === 'mine' && draft.status === 'pending') {
        draft.status = 'active';
    }
}

/* «Aplicar»: the draft becomes the list's filters, in one request. */
function applyDraft(): void {
    Object.assign(state, copyOf(draft));
    filtersOpen.value = false;
    apply();
    void announceFilters();
}

/*
 * One-click department filter for whoever sees more than one department
 * (administrators, supervisors): a chip per department above the list.
 * An agent of a single department never sees it.
 */
const departmentChips = computed(() =>
    (props.inbox.scope === 'global' || props.inbox.scope === 'departments') &&
    props.departments.length > 1
        ? props.departments.filter((department) => department.is_active)
        : [],
);

function chooseDepartment(id: number | null): void {
    const value = id === null ? ALL : String(id);

    change({ department: state.department === value ? ALL : value });
}

function toggleUnread(): void {
    change({ unread: !state.unread });
}

function toggleAwaiting(): void {
    change({ awaiting: !state.awaiting });
}

function toggleCompleted(): void {
    change({ status: state.status === 'completed' ? 'closed' : 'completed' });
}

function defaults(): InboxFilterState {
    return {
        view: defaultView.value,
        status: 'pending',
        department: ALL,
        agent: ALL,
        connection: ALL,
        channel: ALL,
        tags: [],
        tagMatch: 'any',
        sort: defaultSort(defaultView.value),
        searchIn: 'contact',
        unread: false,
        awaiting: false,
        category: ALL,
        readiness: ALL,
    };
}

function clearFilters(): void {
    Object.assign(state, defaults());
    search.value = '';
    filtersOpen.value = false;
    apply();
    void announceFilters();
}

/* Shortcuts of the empty chat pane (pages/conversations/Index). */
function showPending(): void {
    change({ view: baseView.value, status: 'pending' });
}

function showUnread(): void {
    change({ unread: true });
}

defineExpose({ query, showPending, showUnread });

/*
 * "Filtros": a popover on wide screens, a bottom sheet on phones and on
 * very short windows (200 % zoom and up), where a popover has no room.
 */
const compact = useMediaQuery('(max-width: 639px), (max-height: 499px)');
const filtersOpen = ref(false);

/*
 * The applied filters the draft was started from. Closing the panel by
 * accident (a click outside, Tab, rotating the phone) keeps an edited draft
 * and the panel says «Tienes cambios sin aplicar» when it opens again; the
 * draft only restarts from what is applied when it holds no edits, or when
 * the applied filters changed meanwhile (a pill removed, «Limpiar»…).
 */
const sameFilters = (one: InboxFilterState, two: InboxFilterState): boolean =>
    JSON.stringify(copyOf(one)) === JSON.stringify(copyOf(two));
let draftBase: InboxFilterState = copyOf(state);
const draftPending = computed(() => !sameFilters(draft, state));

/* Escape or «Cerrar»: closed on purpose, the draft goes back to what is applied. */
function discardDraft(): void {
    Object.assign(draft, copyOf(state));
    draftBase = copyOf(state);
}

watch(filtersOpen, (open) => {
    if (!open) {
        return;
    }

    if (sameFilters(draft, draftBase) || !sameFilters(state, draftBase)) {
        Object.assign(draft, copyOf(state));
        draftBase = copyOf(state);
    }
});

/* Row "Administrar etiquetas": one dialog for the whole list. */
const taggedConversation = ref<ConversationSummary | null>(null);
const tagsDialogOpen = ref(false);

function manageTags(conversation: ConversationSummary): void {
    taggedConversation.value = conversation;
    tagsDialogOpen.value = true;
}

/* Keyboard: arrows move between rows, Home/End jump to the ends. */
const listElement = ref<{ $el: HTMLElement } | null>(null);

function rowElements(): HTMLElement[] {
    return [
        ...(listElement.value?.$el.querySelectorAll<HTMLElement>(
            '[data-inbox-item]',
        ) ?? []),
    ];
}

function focusFirstRow(): void {
    rowElements()[0]?.focus();
}

function onListKeydown(event: KeyboardEvent): void {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const items = rowElements();
    const index = items.indexOf(document.activeElement as HTMLElement);

    if (items.length === 0 || index === -1) {
        return;
    }

    event.preventDefault();

    // Arrow up from the first row goes back to the search box.
    if (event.key === 'ArrowUp' && index === 0) {
        searchBox.value?.focus();

        return;
    }

    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? items.length - 1
              : event.key === 'ArrowDown'
                ? Math.min(items.length - 1, index + 1)
                : Math.max(0, index - 1);

    items[next]?.focus();
}

const searchBox = ref<InstanceType<typeof FilterSearch> | null>(null);

/* The header casts a shadow while rows pass under it. */
const scrolled = ref(false);

/* The selected row sits on one glider that springs between rows. */
const rail = ref<HTMLElement | null>(null);
const glider = useSelectionGlider(rail, () => [
    props.selectedId,
    props.conversations.data.map((row) => row.id),
    props.acceptingId,
]);

/* Load more when the end of the list comes into view. */
function onScroll(event: Event): void {
    const element = event.target as HTMLElement;

    scrolled.value = element.scrollTop > 2;

    if (
        props.conversations.has_more &&
        !props.loading &&
        element.scrollHeight - element.scrollTop - element.clientHeight < 160
    ) {
        emit('loadMore');
    }
}

/* A new filter is loading (not just one more page). */
const filtering = computed(() => props.loading && !props.loadingMore);

/*
 * The rows slide in once, when the inbox opens; afterwards only small live
 * changes animate (a conversation arrives, leaves or moves), never a new
 * filter or a new page (useRowMotion).
 */
const firstPaint = ref(true);
const rowMotion = useRowMotion(
    () => props.conversations.data,
    () => props.filters,
);

onMounted(() => globalThis.setTimeout(() => (firstPaint.value = false), 900));

/* The applied contact search, marked in the rows' names. */
const highlight = computed(() =>
    props.filters.search_in === 'contact' ? props.filters.search : '',
);

const emptyCopy = computed(() => {
    if (state.status === 'completed') {
        return {
            title: 'Aún no hay solicitudes completadas',
            description: 'Aquí verás las cerradas como completadas.',
        };
    }

    if (closedActive.value) {
        return {
            title: 'No hay conversaciones cerradas',
            description: 'Aquí queda el historial de las cerradas.',
        };
    }

    if (pendingActive.value) {
        return {
            title: 'No hay pendientes por aceptar',
            description: isGeneral.value
                ? 'Las asignadas a ti llegarán a tu bandeja.'
                : 'Los clientes nuevos aparecerán aquí.',
        };
    }

    if (generalActive.value && isGeneral.value) {
        return {
            title: 'Tu bandeja está al día',
            description: 'Sin pendientes ni conversaciones en atención.',
        };
    }

    return {
        title: 'No hay conversaciones aquí',
        description:
            props.inbox.scope === 'global'
                ? 'Llegan cuando un cliente escribe a cualquier conexión.'
                : 'Llegan cuando un cliente escribe a tus conexiones.',
    };
});

const searchPlaceholder = computed(() =>
    state.searchIn === 'messages'
        ? 'Buscar en los mensajes'
        : 'Buscar nombre, teléfono o correo',
);

const scopeButton =
    'h-6 rounded-md px-2 text-xs font-medium outline-none transition-colors duration-(--duration-fast) focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-8';
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col bg-background"
        aria-label="Lista de conversaciones"
    >
        <header
            class="list-header grid min-w-0 grid-cols-1 gap-2 border-b border-border/60 bg-background px-3 pt-2.5 pb-2"
            :data-scrolled="scrolled ? '' : undefined"
        >
            <!-- The page header already names the module: this title is for screen readers. -->
            <h1 class="sr-only">Conversaciones</h1>

            <div class="flex items-center gap-1.5">
                <FilterSearch
                    ref="searchBox"
                    v-model="search"
                    :placeholder="searchPlaceholder"
                    label="Buscar conversaciones"
                    :title="
                        state.searchIn === 'messages'
                            ? 'Busca palabras dentro de los mensajes. Atajo: / o Ctrl+K'
                            : 'Busca por nombre, teléfono o correo del cliente. Atajo: / o Ctrl+K'
                    "
                    :loading="filtering"
                    data-tour="inbox.search"
                    @next="focusFirstRow"
                />

                <InboxFilterSurface
                    v-model:open="filtersOpen"
                    :compact="compact"
                    :applied-count="activeFilterCount"
                    :draft-count="draftFilterCount"
                    :draft-pending="draftPending"
                    @apply="applyDraft"
                    @discard="discardDraft"
                    @clear="clearFilters"
                >
                    <InboxFilterPanel
                        :state="draft"
                        :views="inbox.views.filter((view) => view !== 'queue')"
                        :view-labels="viewLabels"
                        :status-options="statusOptions"
                        :unread-count="unreadCount"
                        :awaiting-count="awaitingCount"
                        :departments="departments"
                        :can-see-unrouted="canSeeUnrouted"
                        :show-agents="showAgents"
                        :agents="agents"
                        :channels="channels"
                        :connections="connections"
                        :tags="tags"
                        :categories="categories"
                        :max-tags="MAX_FILTER_TAGS"
                        :sort-options="sortOptions"
                        :search-scopes="searchScopes"
                        @change="changeDraft"
                    />
                </InboxFilterSurface>

                <ExportMenu
                    v-if="canExport"
                    report="conversations"
                    :filters="exportFilters"
                    label="Exportar"
                    compact
                />
            </div>

            <!-- Where the search looks, only while searching. -->
            <div
                v-if="search.trim() !== '' || state.searchIn === 'messages'"
                class="flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
                role="group"
                aria-label="Buscar en"
                data-inbox-search-scope
            >
                <span class="mr-0.5">Buscar en</span>
                <button
                    v-for="scope in [
                        { value: 'contact', label: 'Contacto' },
                        { value: 'messages', label: 'Mensajes' },
                    ] as const"
                    :key="scope.value"
                    type="button"
                    :class="[
                        scopeButton,
                        state.searchIn === scope.value
                            ? 'bg-foreground/[0.07] text-foreground'
                            : 'hover:text-foreground',
                    ]"
                    :aria-pressed="state.searchIn === scope.value"
                    @click="change({ searchIn: scope.value })"
                >
                    {{ scope.label }}
                </button>
            </div>

            <p
                v-if="searchTooShort"
                class="text-xs text-muted-foreground"
                role="status"
            >
                Escribe al menos una palabra de 3 letras o números para buscar
                en los mensajes. Las palabras muy comunes (como «the» o «for»)
                no se buscan.
            </p>

            <!-- One strip: the views as tabs, then the quick toggles. -->
            <nav aria-label="Bandejas" class="-mx-1 min-w-0">
                <FilterTabs
                    :options="tabs"
                    :model-value="activeTab"
                    label="Filtrar la bandeja"
                    wrap
                    class="px-1"
                    @update:model-value="chooseTab"
                >
                    <template #after>
                        <span
                            class="mx-0.5 h-4 w-px shrink-0 bg-border"
                            aria-hidden="true"
                        />
                        <div
                            class="flex shrink-0 items-center gap-1"
                            role="group"
                            aria-label="Filtros rápidos"
                        >
                            <FilterToggle
                                v-if="closedActive"
                                :pressed="state.status === 'completed'"
                                label="Solo completadas"
                                :icon="CircleCheck"
                                tone="success"
                                title="Solo las conversaciones cerradas como «Solicitud completada»"
                                data-inbox-completed
                                @toggle="toggleCompleted"
                            />
                            <template v-else>
                                <FilterToggle
                                    :pressed="state.unread"
                                    label="Sin leer"
                                    :icon="MailWarning"
                                    :count="unreadCount"
                                    tone="primary"
                                    :sr-label="
                                        plural(
                                            unreadCount,
                                            'conversación sin leer',
                                            'conversaciones sin leer',
                                        )
                                    "
                                    title="Solo las conversaciones con mensajes del cliente que aún no has leído"
                                    data-inbox-unread
                                    data-tour="inbox.unread"
                                    @toggle="toggleUnread"
                                />
                                <FilterToggle
                                    :pressed="state.awaiting"
                                    label="Esperando respuesta"
                                    :icon="MessageCircleReply"
                                    :count="awaitingCount"
                                    tone="warning"
                                    :sr-label="
                                        plural(
                                            awaitingCount,
                                            'conversación espera respuesta',
                                            'conversaciones esperan respuesta',
                                        )
                                    "
                                    title="Solo las conversaciones donde el cliente escribió al último y nadie le ha respondido"
                                    data-inbox-awaiting
                                    @toggle="toggleAwaiting"
                                />
                            </template>
                            <button
                                v-if="hasFilters && appliedChips.length === 0"
                                type="button"
                                class="inline-flex h-7 shrink-0 items-center rounded-md px-1.5 text-xs font-medium text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-9"
                                data-inbox-clear
                                data-filter-clear
                                @click="clearFilters"
                            >
                                Limpiar
                            </button>
                        </div>
                    </template>
                </FilterTabs>
            </nav>

            <!-- Departments as one-click chips (admins and supervisors). -->
            <div
                v-if="departmentChips.length > 0"
                class="-mx-1 flex flex-wrap items-center gap-1.5 px-1"
                role="group"
                aria-label="Filtrar por departamento"
                data-inbox-departments
            >
                <button
                    type="button"
                    class="inline-flex h-7 items-center rounded-full border px-2.5 text-xs font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-9"
                    :class="
                        state.department === ''
                            ? 'border-foreground/20 bg-foreground text-background'
                            : 'border-border text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="state.department === ''"
                    @click="chooseDepartment(null)"
                >
                    Todos
                </button>
                <button
                    v-for="department in departmentChips"
                    :key="department.id"
                    type="button"
                    class="inline-flex h-7 max-w-44 items-center gap-1.5 rounded-full border px-2.5 text-xs font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-9"
                    :class="
                        state.department === String(department.id)
                            ? 'border-primary/50 bg-primary-soft text-foreground'
                            : 'border-border text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="state.department === String(department.id)"
                    :title="`Solo las conversaciones de ${department.name}`"
                    @click="chooseDepartment(department.id)"
                >
                    <span
                        class="size-2 shrink-0 rounded-full"
                        v-bind="colorPresentations(department.color).dot"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ department.name }}</span>
                </button>
            </div>

            <p class="sr-only" role="status" aria-live="polite">
                {{ pendingAnnouncement }}
            </p>
            <p class="sr-only" role="status" aria-live="polite">
                {{ filtersAnnouncement }}
            </p>

            <!-- The panel's own filters as removable pills, only when there are some. -->
            <ActiveFilters
                v-if="appliedChips.length > 0"
                :pills="appliedChips"
                :clearable="hasFilters"
                @clear="clearFilters"
            />

            <AcceptBlockNotice @select="(id) => emit('select', id)" />
        </header>

        <div
            class="relative min-h-0 flex-1 overflow-y-auto overscroll-contain"
            :aria-busy="loading"
            @scroll.passive="onScroll"
        >
            <div
                v-if="filtering && conversations.data.length > 0"
                class="sticky top-0 z-10 -mb-0.5 pane-progress h-0.5"
                aria-hidden="true"
                data-inbox-progress
            />
            <div
                v-if="conversations.data.length > 0"
                ref="rail"
                class="list-rail p-1.5"
            >
                <span
                    class="list-glider"
                    :data-placed="glider.placed.value ? '' : undefined"
                    :style="glider.style.value"
                    aria-hidden="true"
                    data-list-glider
                />
                <TransitionGroup
                    ref="listElement"
                    tag="ul"
                    :name="rowMotion"
                    class="relative z-[1] grid grid-cols-1 gap-px motion-safe:transition-opacity motion-safe:duration-(--duration-fast)"
                    :class="[
                        filtering && 'opacity-60',
                        firstPaint && 'reveal-stagger',
                    ]"
                    @keydown="onListKeydown"
                >
                    <li
                        v-for="(conversation, position) in conversations.data"
                        :key="conversation.id"
                        :data-tour="position === 0 ? 'inbox.item' : undefined"
                    >
                        <InboxListItem
                            :conversation="conversation"
                            :selected="conversation.id === selectedId"
                            :unread="
                                conversation.id === selectedId
                                    ? 0
                                    : (conversation.unread_count ?? 0)
                            "
                            :show-operational="viewer.can_supervise"
                            :show-assignee="showAgents"
                            :show-channel="showChannels"
                            :accepting-id="acceptingId"
                            :can-edit-alias="canEditAlias"
                            :highlight="highlight"
                            @select="(id) => emit('select', id)"
                            @accept="(id) => emit('accept', id)"
                            @mark-read="(id) => emit('markRead', id)"
                            @manage-tags="manageTags"
                            @edit-alias="(row) => emit('editAlias', row)"
                            @preview="(row) => emit('preview', row)"
                        />
                    </li>
                </TransitionGroup>
            </div>

            <ListSkeleton v-else-if="loading" :rows="8" chips />

            <FilteredEmpty
                v-else
                :narrowed="isNarrowed"
                narrowed-title="No hay conversaciones con estos filtros"
                :narrowed-description="
                    search.trim() !== ''
                        ? 'Revisa la búsqueda o limpia los filtros.'
                        : 'Quita algún filtro para ver más.'
                "
                :icon="Inbox"
                :title="emptyCopy.title"
                :description="emptyCopy.description"
                @clear="clearFilters"
            >
                <Button
                    v-if="hasFilters"
                    variant="outline"
                    size="sm"
                    @click="clearFilters"
                    >Volver a la bandeja</Button
                >
            </FilteredEmpty>

            <div v-if="conversations.has_more" class="flex justify-center p-3">
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="loading"
                    @click="emit('loadMore')"
                    :loading="loadingMore"
                >
                    Cargar más conversaciones
                </Button>
            </div>
        </div>

        <InboxTagsDialog
            v-model:open="tagsDialogOpen"
            :conversation="taggedConversation"
            :available="tags"
        />
    </section>
</template>
