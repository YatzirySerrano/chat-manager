<script setup lang="ts">
import { Head, Link, router, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import {
    BellRing,
    CheckCheck,
    ChevronRight,
    Clock,
    Hourglass,
    Inbox,
    Lock,
    RefreshCw,
    Send,
    ShieldAlert,
    UserRoundX,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AttentionAlertCard from '@/components/attention/AttentionAlertCard.vue';
import AttentionAlertComposer from '@/components/attention/AttentionAlertComposer.vue';
import { ALERT_SURFACE, PRIORITY_LOOK } from '@/components/attention/priority';
import DepartmentTag from '@/components/DepartmentTag.vue';
import ActiveFilters from '@/components/filters/ActiveFilters.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterSearch from '@/components/filters/FilterSearch.vue';
import FilterToggle from '@/components/filters/FilterToggle.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import type { ActiveFilterPill } from '@/components/filters/types';
import PageHeader from '@/components/PageHeader.vue';
import InsightTabs from '@/components/reports/InsightTabs.vue';
import KpiCard from '@/components/reports/KpiCard.vue';
import SlaMonitorPanel from '@/components/sla/SlaMonitorPanel.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useFeatures } from '@/composables/useFeatures';
import { useLiveReload } from '@/composables/useLiveReload';
import { syncServerClock } from '@/composables/useServerNow';
import { useTeamPresence } from '@/composables/useTeamPresence';
import {
    inboxChannelNames,
    userChannelName,
    useUserChannel,
} from '@/composables/useUserChannel';
import { formatDateTime, formatRelative } from '@/lib/format';
import type { LiveChannel } from '@/lib/realtime';
import { cn } from '@/lib/utils';
import { index as attentionIndex } from '@/routes/attention';
import '@/components/reports/insights.css';
import { show as showAlert } from '@/routes/attention/alerts';
import type {
    AttentionAgent,
    AttentionAlertAudience,
    AttentionAlertPriority,
    AttentionAlertRecipient,
    AttentionAlertSound,
    AttentionAlertSummary,
    AttentionOption,
    AttentionWaitingConversation,
    ColorSwatch,
    SlaBreachHistory,
    SlaMonitorData,
    SlaMonitorFilters,
    SlaMonitorOptions,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Seguimiento de atención', href: attentionIndex() },
        ],
    },
});

const props = defineProps<{
    agents: AttentionAgent[];
    waiting: AttentionWaitingConversation[];
    queue: { count: number; oldest_since: string | null };
    alerts: AttentionAlertSummary[];
    generatedAt: string;
    departments: { id: number; name: string }[];
    control: { enabled: boolean; tolerance_minutes: number };
    can: { send: boolean; settings: boolean };
    /** Section shown: the attention tracking or the SLA monitor (`?tab=sla`). */
    tab: 'attention' | 'sla';
    /** Deferred: open response cycles of the viewer's reach. */
    sla?: SlaMonitorData;
    /** Deferred: breach history of the chosen period. */
    slaBreaches?: SlaBreachHistory;
    /** Absent while phase two is off (no SLA monitor). */
    slaFilters?: SlaMonitorFilters;
    slaOptions?: SlaMonitorOptions;
    /** Server clock when the data was built (anchor of the countdowns). */
    serverTime: string;
    settingsUrl: string | null;
    options: {
        priorities: AttentionOption<AttentionAlertPriority>[];
        sounds: AttentionOption<AttentionAlertSound>[];
        colors: AttentionOption<ColorSwatch>[];
        title_max: number;
        body_max: number;
    };
}>();

/* The SLA monitor tab is phase two: the server answers 404 for it while it is off. */
const { phaseTwo } = useFeatures();

const LIVE_PROPS = ['agents', 'waiting', 'queue', 'generatedAt'];

/* Light refresh of the figures; the alert history follows its own signals. */
usePoll(30_000, { only: LIVE_PROPS });

useUserChannel({
    'attention.alert_status': () => router.reload({ only: ['alerts'] }),
});

/*
 * SLA monitor: refreshed by the same signals as the inboxes the viewer
 * reads (a message, an answer, a reassignment or a breach changes it), on
 * top of the periodic refresh above. Countdowns never need a request: they
 * run on the shared clock anchored to `serverTime`.
 */
const SLA_LIVE_PROPS = ['sla', 'slaBreaches', 'serverTime'];

watch(() => props.serverTime, syncServerClock, { immediate: true });

const slaChannels = computed<LiveChannel[]>(() => {
    const personal = userChannelName();

    return [...(personal ? [personal] : []), ...inboxChannelNames()].map(
        (name) => ({ name, events: ['.inbox.changed'] }),
    );
});

useLiveReload({
    channels: slaChannels,
    only: [...SLA_LIVE_PROPS, ...LIVE_PROPS],
    fallbackIntervalMs: 30_000,
    safetyIntervalMs: 60_000,
    debounceMs: 1_000,
});

/* Sections of the page; the choice lives in the URL (`?tab=sla`). */
const activeTab = ref<'attention' | 'sla'>(props.tab);

watch(
    () => props.tab,
    (tab) => (activeTab.value = tab),
);

const sections = computed(() => [
    {
        key: 'attention' as const,
        label: 'Seguimiento de agentes',
        icon: Users,
        count: awaitingTotal.value,
        attention: true,
        attrs: { 'data-attention-tab': 'attention' },
    },
    {
        key: 'sla' as const,
        label: 'Monitoreo de SLA',
        icon: ShieldAlert,
        attrs: { 'data-attention-tab': 'sla' },
    },
]);

function selectTab(tab: 'attention' | 'sla'): void {
    if (activeTab.value === tab) {
        return;
    }

    activeTab.value = tab;

    const params = new URLSearchParams(window.location.search);

    if (tab === 'sla') {
        params.set('tab', 'sla');
    } else {
        params.delete('tab');
    }

    const queryString = params.toString();

    router.get(
        `${attentionIndex.url()}${queryString === '' ? '' : `?${queryString}`}`,
        {},
        {
            only: ['tab'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

const refreshing = ref(false);

function refresh(): void {
    router.reload({
        only: [...LIVE_PROPS, ...SLA_LIVE_PROPS, 'alerts'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}

const { isOnline } = useTeamPresence();
const page = usePage();
const senderName = computed(
    () =>
        (page.props.auth?.user as { name?: string } | undefined)?.name ??
        'Administración',
);

/* Summary. */
const awaitingTotal = computed(() =>
    props.agents.reduce((sum, agent) => sum + agent.awaiting, 0),
);
const blockedCount = computed(
    () => props.agents.filter((agent) => agent.blocked).length,
);
const oldestWaiting = computed(() => props.waiting[0] ?? null);
const withWaiting = computed(
    () => props.agents.filter((agent) => agent.awaiting > 0).length,
);

/* Agents: search, department and «solo con clientes esperando». */
const search = ref('');
const department = ref('all');
const onlyWaiting = ref(false);

const shownAgents = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');

    return (
        [...props.agents]
            .filter(
                (agent) =>
                    (department.value === 'all' ||
                        agent.departments.some(
                            (item) => String(item.id) === department.value,
                        )) &&
                    (!onlyWaiting.value || agent.awaiting > 0) &&
                    (term === '' ||
                        agent.name.toLocaleLowerCase('es').includes(term) ||
                        agent.email.toLocaleLowerCase('es').includes(term)),
            )
            // Most backlog first: overdue, then waiting, then name.
            .sort(
                (a, b) =>
                    b.overdue - a.overdue ||
                    b.awaiting - a.awaiting ||
                    a.name.localeCompare(b.name),
            )
    );
});

const departmentOptions = computed(() => [
    { value: 'all', label: 'Todos los departamentos' },
    ...props.departments.map((item) => ({
        value: String(item.id),
        label: item.name,
    })),
]);

/* Applied filters as removable pills (the toggle shows its own state). */
const pills = computed<ActiveFilterPill[]>(() => {
    const list: ActiveFilterPill[] = [];
    const term = search.value.trim();

    if (term !== '') {
        list.push({
            key: 'search',
            label: `«${term}»`,
            remove: () => (search.value = ''),
        });
    }

    if (department.value !== 'all') {
        const name = props.departments.find(
            (item) => String(item.id) === department.value,
        )?.name;

        list.push({
            key: 'department',
            label: name ?? 'Departamento',
            remove: () => (department.value = 'all'),
        });
    }

    return list;
});

const narrowed = computed(() => pills.value.length > 0 || onlyWaiting.value);

function clearFilters(): void {
    search.value = '';
    department.value = 'all';
    onlyWaiting.value = false;
}

const selected = ref<number[]>([]);

/*
 * The list refreshes every 30 s: someone who left the team (or the
 * viewer's reach) must not stay counted in «N elegidos» nor receive the
 * alert.
 */
watch(
    () => props.agents,
    (agents) => {
        const ids = new Set(agents.map((agent) => agent.id));
        const kept = selected.value.filter((id) => ids.has(id));

        if (kept.length !== selected.value.length) {
            selected.value = kept;
        }
    },
);

function toggle(id: number, checked: boolean): void {
    selected.value = checked
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((item) => item !== id);
}

const allShownSelected = computed(
    () =>
        shownAgents.value.length > 0 &&
        shownAgents.value.every((agent) => selected.value.includes(agent.id)),
);

function toggleAllShown(checked: boolean): void {
    const ids = shownAgents.value.map((agent) => agent.id);
    selected.value = checked
        ? [...new Set([...selected.value, ...ids])]
        : selected.value.filter((id) => !ids.includes(id));
}

/* Composer. */
const composerOpen = ref(false);
const composerRecipients = ref<number[]>([]);
const composerAudience = ref<AttentionAlertAudience>('individual');

function compose(recipients: number[], audience: AttentionAlertAudience): void {
    composerRecipients.value = recipients;
    composerAudience.value = audience;
    composerOpen.value = true;
}

function onSent(): void {
    selected.value = [];
    router.reload({ only: ['alerts'] });
}

function presence(agent: AttentionAgent): { label: string; online: boolean } {
    if (isOnline(agent.id)) {
        return { label: 'En línea', online: true };
    }

    return {
        label: agent.last_activity_at
            ? `Última actividad ${formatRelative(agent.last_activity_at)}`
            : 'Sin actividad reciente registrada',
        online: false,
    };
}

/* History detail: who got and acknowledged an alert. */
type AlertDetail = {
    alert: AttentionAlertSummary;
    recipients: AttentionAlertRecipient[];
};
const detailHttp = useHttp<Record<string, never>, AlertDetail>({});
const detail = ref<AlertDetail | null>(null);
const detailOpen = ref(false);
const detailFailed = ref(false);

async function openDetail(alert: AttentionAlertSummary): Promise<void> {
    detail.value = { alert, recipients: [] };
    detailFailed.value = false;
    detailOpen.value = true;

    try {
        detail.value = await detailHttp.get(showAlert.url(alert.id));
    } catch {
        detail.value = { alert, recipients: [] };
        detailFailed.value = true;
    }
}

function ratio(part: number, total: number): number {
    return total === 0 ? 0 : Math.round((part / total) * 100);
}

function colorLabel(color: ColorSwatch): string {
    return (
        props.options.colors.find((item) => item.value === color)?.label ??
        color
    ).toLowerCase();
}

function recipientState(recipient: AttentionAlertRecipient): string {
    if (recipient.acknowledged_at) {
        return `Confirmada ${formatRelative(recipient.acknowledged_at)}`;
    }

    if (recipient.delivered_at) {
        return `Mostrada ${formatRelative(recipient.delivered_at)}, sin confirmar`;
    }

    return 'Disponible para su cuenta, todavía no se ha mostrado en su pantalla';
}
</script>

<template>
    <Head title="Seguimiento de atención" />

    <div class="page-shell">
        <PageHeader
            help="atencion"
            title="Seguimiento de atención"
            description="Qué atiende cada agente, qué clientes esperan respuesta y desde cuándo. Una conversación abierta no cuenta como desatendida si el agente ya respondió y espera al cliente."
        >
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                    :title="formatDateTime(generatedAt)"
                    ><span
                        class="live-dot size-1.5 rounded-full bg-current text-success"
                        aria-hidden="true"
                    />Actualizado {{ formatRelative(generatedAt) }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="refreshing"
                    @click="refresh"
                    :loading="refreshing"
                >
                    <RefreshCw aria-hidden="true" />
                    Actualizar
                </Button>
                <Button
                    v-if="can.send && agents.length > 0"
                    size="sm"
                    data-attention-alert-all
                    @click="compose([], 'everyone')"
                >
                    <BellRing aria-hidden="true" />
                    Alertar a todos
                </Button>
            </template>
        </PageHeader>

        <InsightTabs
            v-if="phaseTwo"
            :items="sections"
            :model-value="activeTab"
            label="Secciones del seguimiento"
            id-prefix="attention-tab"
            panel-prefix="attention-panel"
            @select="selectTab"
        />

        <SlaMonitorPanel
            v-if="phaseTwo && activeTab === 'sla' && slaFilters && slaOptions"
            id="attention-panel-sla"
            role="tabpanel"
            aria-labelledby="attention-tab-sla"
            :data="sla"
            :history="slaBreaches"
            :filters="slaFilters"
            :options="slaOptions"
            :settings-url="settingsUrl"
        />

        <div
            v-else
            id="attention-panel-attention"
            :role="phaseTwo ? 'tabpanel' : undefined"
            :aria-labelledby="phaseTwo ? 'attention-tab-attention' : undefined"
            class="flex flex-col gap-(--section-gap)"
        >
            <div
                class="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4"
                data-attention-summary
            >
                <KpiCard
                    label="Agentes"
                    :value="agents.length"
                    :icon="Users"
                    plain
                    hint="Que atienden clientes dentro de tu alcance"
                />
                <KpiCard
                    label="Clientes esperando respuesta"
                    :value="awaitingTotal"
                    :icon="Hourglass"
                    :tone="awaitingTotal > 0 ? 'warning' : 'success'"
                    live
                    hint="El cliente escribió y el agente aún no responde"
                />
                <KpiCard
                    label="Agentes sin poder aceptar"
                    :value="control.enabled ? blockedCount : null"
                    :icon="Lock"
                    :tone="
                        !control.enabled
                            ? 'muted'
                            : blockedCount > 0
                              ? 'destructive'
                              : 'primary'
                    "
                    plain
                    :hint="
                        control.enabled
                            ? `Con clientes esperando más de ${control.tolerance_minutes} min`
                            : undefined
                    "
                    unavailable-label="El control de pendientes está desactivado"
                />
                <KpiCard
                    label="En cola sin agente"
                    :value="queue.count"
                    :icon="Inbox"
                    :tone="queue.count > 0 ? 'warning' : 'primary'"
                    live
                    :hint="
                        queue.oldest_since
                            ? `La más antigua espera desde ${formatRelative(queue.oldest_since)}`
                            : 'Ninguna esperando'
                    "
                />
            </div>

            <div
                class="split-layout [--aside-w:30rem] min-[120rem]:[--aside-w:36rem]"
            >
                <div class="grid min-w-0 content-start gap-(--section-gap)">
                    <SurfaceCard
                        title="Agentes"
                        description="Ordenados por acumulación: primero quienes tienen clientes esperando más tiempo."
                    >
                        <div class="grid gap-3 px-4 pb-5 sm:px-5">
                            <!-- One dense row: search, department, the toggle; applied pills inline. -->
                            <div
                                class="flex flex-wrap items-center gap-2"
                                data-attention-filters
                            >
                                <div class="min-w-[12rem] flex-1 basis-60">
                                    <FilterSearch
                                        v-model="search"
                                        placeholder="Buscar agente por nombre o correo"
                                        label="Buscar agente"
                                    />
                                </div>
                                <div
                                    v-if="departments.length > 0"
                                    class="w-full sm:w-60 sm:shrink-0"
                                >
                                    <AppSelect
                                        v-model="department"
                                        :options="departmentOptions"
                                        size="sm"
                                        aria-label="Filtrar por departamento"
                                    />
                                </div>
                                <ActiveFilters
                                    :pills="pills"
                                    :clearable="narrowed"
                                    @clear="clearFilters"
                                >
                                    <FilterToggle
                                        :pressed="onlyWaiting"
                                        label="Solo con clientes esperando"
                                        :icon="Hourglass"
                                        :count="withWaiting"
                                        tone="warning"
                                        :sr-label="`${withWaiting} ${withWaiting === 1 ? 'agente' : 'agentes'}`"
                                        @toggle="onlyWaiting = !onlyWaiting"
                                    />
                                </ActiveFilters>
                            </div>

                            <div
                                v-if="can.send && shownAgents.length > 0"
                                class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border px-3 py-2 text-sm transition-[background-color,border-color,box-shadow] duration-(--duration-base) ease-(--ease-standard)"
                                :class="
                                    selected.length > 0
                                        ? 'border-primary/30 bg-primary-soft/70 shadow-(--shadow-control)'
                                        : 'border-transparent bg-surface-sunken'
                                "
                                data-attention-selection
                            >
                                <label
                                    class="flex min-h-9 cursor-pointer items-center gap-2"
                                >
                                    <Checkbox
                                        :model-value="allShownSelected"
                                        @update:model-value="
                                            (checked) =>
                                                toggleAllShown(checked === true)
                                        "
                                    />
                                    Elegir los de la lista
                                </label>
                                <span
                                    class="text-muted-foreground tabular-nums"
                                    aria-live="polite"
                                >
                                    <span
                                        :key="selected.length"
                                        class="inline-block font-semibold text-foreground"
                                        :class="selected.length > 0 && 'bump'"
                                        >{{ selected.length }}</span
                                    >
                                    {{
                                        selected.length === 1
                                            ? 'elegido'
                                            : 'elegidos'
                                    }}
                                </span>
                                <Button
                                    size="sm"
                                    class="ml-auto rounded-full"
                                    :disabled="selected.length === 0"
                                    data-attention-alert-selected
                                    @click="
                                        compose(
                                            selected,
                                            selected.length > 1
                                                ? 'selection'
                                                : 'individual',
                                        )
                                    "
                                >
                                    <Send aria-hidden="true" />
                                    Alertar a los elegidos
                                </Button>
                            </div>

                            <FilteredEmpty
                                v-if="shownAgents.length === 0"
                                :narrowed="agents.length > 0"
                                narrowed-title="Ningún agente coincide"
                                narrowed-description="Cambia la búsqueda o quita algún filtro para ver más agentes."
                                title="No hay agentes a tu cargo"
                                description="Aquí aparecen las personas activas que atienden clientes dentro de tu alcance."
                                :icon="UserRoundX"
                                @clear="clearFilters"
                            />

                            <TransitionGroup
                                v-else
                                tag="ul"
                                name="filter-row"
                                class="relative auto-grid [--col-min:27rem] [--grid-gap:0.625rem]"
                                aria-label="Agentes"
                            >
                                <li
                                    v-for="(agent, index) in shownAgents"
                                    :key="agent.id"
                                    :class="
                                        cn(
                                            'group/agent relative grid enter-spring gap-3 rounded-xl border bg-card p-3.5 shadow-(--shadow-control) transition-[border-color,box-shadow,background-color] duration-(--duration-base) ease-(--ease-out-quint) hover:border-border-strong hover:shadow-lift sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-start',
                                            selected.includes(agent.id) &&
                                                'border-primary/60 bg-[color-mix(in_oklch,var(--primary)_5%,var(--card))] shadow-[0_0_0_1px_var(--primary)]',
                                            agent.blocked &&
                                                !selected.includes(agent.id) &&
                                                'border-warning/50 bg-[color-mix(in_oklch,var(--warning)_6%,var(--card))]',
                                        )
                                    "
                                    :style="{ '--i': Math.min(index, 8) }"
                                    data-attention-agent
                                >
                                    <div class="flex items-center gap-3">
                                        <Checkbox
                                            v-if="can.send"
                                            class="hidden sm:flex"
                                            :model-value="
                                                selected.includes(agent.id)
                                            "
                                            :aria-label="`Elegir a ${agent.name}`"
                                            @update:model-value="
                                                (checked) =>
                                                    toggle(
                                                        agent.id,
                                                        checked === true,
                                                    )
                                            "
                                        />
                                        <ListAvatar
                                            :name="agent.name"
                                            :user-id="agent.id"
                                            :online="presence(agent).online"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <p
                                            class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                        >
                                            <span
                                                class="font-display text-base leading-6 font-semibold"
                                                data-attention-agent-name
                                                >{{ agent.name }}</span
                                            >
                                            <span
                                                v-if="agent.blocked"
                                                class="inline-flex items-center gap-1 rounded-full border border-warning/50 bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning-soft-foreground"
                                            >
                                                <Lock
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                No puede aceptar nuevas
                                            </span>
                                        </p>
                                        <p
                                            :class="
                                                cn(
                                                    'mt-0.5 inline-flex flex-wrap items-center gap-x-1.5 text-xs',
                                                    presence(agent).online
                                                        ? 'text-success'
                                                        : 'text-muted-foreground',
                                                )
                                            "
                                            :title="
                                                agent.last_activity_at
                                                    ? formatDateTime(
                                                          agent.last_activity_at,
                                                      )
                                                    : undefined
                                            "
                                        >
                                            <span
                                                :class="
                                                    cn(
                                                        'size-1.5 shrink-0 rounded-full',
                                                        presence(agent).online
                                                            ? 'live-dot bg-current'
                                                            : 'bg-muted-foreground/40',
                                                    )
                                                "
                                                aria-hidden="true"
                                            />
                                            {{ presence(agent).label }}
                                            <time
                                                v-if="
                                                    !presence(agent).online &&
                                                    agent.last_activity_at
                                                "
                                                :datetime="
                                                    agent.last_activity_at
                                                "
                                                class="text-muted-foreground"
                                                data-attention-agent-last-activity
                                                >({{
                                                    formatDateTime(
                                                        agent.last_activity_at,
                                                    )
                                                }})</time
                                            >
                                        </p>
                                        <p
                                            class="mt-0.5 truncate text-xs text-muted-foreground"
                                            :title="`${agent.role ?? 'Sin rol'} · ${agent.departments.map((item) => item.name).join(', ') || 'Sin departamento'}`"
                                        >
                                            {{ agent.role ?? 'Sin rol' }} ·
                                            {{
                                                agent.departments
                                                    .map((item) => item.name)
                                                    .join(', ') ||
                                                'Sin departamento'
                                            }}
                                        </p>
                                        <dl
                                            class="mt-2.5 grid grid-cols-3 gap-1.5 text-xs"
                                        >
                                            <div
                                                class="rounded-lg bg-surface-sunken px-2 py-1.5"
                                            >
                                                <dt
                                                    class="truncate text-muted-foreground"
                                                >
                                                    En atención
                                                </dt>
                                                <dd
                                                    class="font-display text-base leading-5 font-semibold tabular-nums"
                                                >
                                                    {{ agent.in_attention }}
                                                </dd>
                                            </div>
                                            <div
                                                :class="
                                                    cn(
                                                        'rounded-lg px-2 py-1.5',
                                                        agent.awaiting > 0
                                                            ? 'bg-warning-soft text-warning-soft-foreground'
                                                            : 'bg-surface-sunken',
                                                    )
                                                "
                                            >
                                                <dt
                                                    class="truncate"
                                                    :class="
                                                        agent.awaiting === 0 &&
                                                        'text-muted-foreground'
                                                    "
                                                >
                                                    Esperan su respuesta
                                                </dt>
                                                <dd
                                                    class="font-display text-base leading-5 font-semibold tabular-nums"
                                                >
                                                    {{ agent.awaiting }}
                                                </dd>
                                            </div>
                                            <div
                                                class="rounded-lg bg-surface-sunken px-2 py-1.5"
                                            >
                                                <dt
                                                    class="truncate text-muted-foreground"
                                                >
                                                    Esperando al cliente
                                                </dt>
                                                <dd
                                                    class="font-display text-base leading-5 font-semibold tabular-nums"
                                                >
                                                    {{ agent.waiting_customer }}
                                                </dd>
                                            </div>
                                        </dl>
                                        <p
                                            v-if="
                                                agent.overdue > 0 ||
                                                agent.oldest_waiting_since
                                            "
                                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                                        >
                                            <span
                                                v-if="agent.overdue > 0"
                                                class="inline-flex items-center gap-1 font-medium text-warning-soft-foreground"
                                            >
                                                <Hourglass
                                                    class="size-3 text-warning"
                                                    aria-hidden="true"
                                                />
                                                {{ agent.overdue }} con más de
                                                {{ control.tolerance_minutes }}
                                                min
                                            </span>
                                            <span
                                                v-if="
                                                    agent.oldest_waiting_since
                                                "
                                                class="inline-flex items-center gap-1 text-muted-foreground"
                                            >
                                                <Clock
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                Más antigua
                                                <time
                                                    :datetime="
                                                        agent.oldest_waiting_since
                                                    "
                                                    :title="
                                                        formatDateTime(
                                                            agent.oldest_waiting_since,
                                                        )
                                                    "
                                                    class="text-foreground"
                                                    >{{
                                                        formatRelative(
                                                            agent.oldest_waiting_since,
                                                        )
                                                    }}</time
                                                >
                                            </span>
                                        </p>
                                    </div>
                                    <div
                                        v-if="can.send"
                                        class="flex items-center justify-between gap-2 sm:justify-end"
                                    >
                                        <Checkbox
                                            class="sm:hidden"
                                            :model-value="
                                                selected.includes(agent.id)
                                            "
                                            :aria-label="`Elegir a ${agent.name}`"
                                            @update:model-value="
                                                (checked) =>
                                                    toggle(
                                                        agent.id,
                                                        checked === true,
                                                    )
                                            "
                                        />
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="rounded-full pointer-coarse:h-10"
                                            @click="
                                                compose(
                                                    [agent.id],
                                                    'individual',
                                                )
                                            "
                                        >
                                            <BellRing aria-hidden="true" />
                                            Alertar
                                        </Button>
                                    </div>
                                </li>
                            </TransitionGroup>
                        </div>
                    </SurfaceCard>
                </div>
                <aside
                    class="grid min-w-0 content-start gap-(--section-gap)"
                    aria-label="Clientes en espera y alertas enviadas"
                >
                    <div
                        v-if="oldestWaiting"
                        class="relative flex flex-col gap-3 overflow-hidden rounded-xl border border-warning/40 bg-[color-mix(in_oklch,var(--warning)_7%,var(--card))] px-4 py-4 shadow-(--shadow-control) sm:flex-row sm:items-center sm:justify-between sm:px-5"
                        data-attention-oldest
                    >
                        <p class="flex min-w-0 items-start gap-3 text-sm">
                            <span
                                class="insight-tile"
                                data-tone="warning"
                                aria-hidden="true"
                            >
                                <Clock class="size-4" />
                            </span>
                            <span class="min-w-0 pt-0.5">
                                <span
                                    class="block text-xs font-medium text-muted-foreground"
                                    >Espera más larga</span
                                >
                                <strong class="font-semibold">{{
                                    oldestWaiting.contact
                                }}</strong>
                                con
                                {{ oldestWaiting.agent?.name ?? 'sin agente' }},
                                desde
                                <time
                                    :datetime="
                                        oldestWaiting.waiting_since ?? undefined
                                    "
                                    :title="
                                        formatDateTime(
                                            oldestWaiting.waiting_since,
                                        )
                                    "
                                    class="font-semibold"
                                    >{{
                                        formatRelative(
                                            oldestWaiting.waiting_since,
                                        )
                                    }}</time
                                >.
                            </span>
                        </p>
                        <Button
                            as-child
                            size="sm"
                            class="shrink-0 rounded-full"
                        >
                            <Link :href="oldestWaiting.url"
                                >Abrir conversación</Link
                            >
                        </Button>
                    </div>

                    <SurfaceCard
                        title="Clientes esperando respuesta"
                        description="Conversaciones en atención donde el cliente escribió después de la última respuesta del agente, la más antigua primero."
                    >
                        <div class="px-3 pb-4 sm:px-4">
                            <div
                                v-if="waiting.length === 0"
                                class="flex items-center gap-3 rounded-xl border border-dashed bg-surface-sunken/50 px-4 py-5 text-sm text-muted-foreground"
                                data-attention-waiting-empty
                            >
                                <span
                                    class="insight-tile"
                                    data-tone="success"
                                    aria-hidden="true"
                                >
                                    <CheckCheck class="size-4" />
                                </span>
                                Ningún cliente espera respuesta en este momento.
                            </div>
                            <TransitionGroup
                                v-else
                                tag="ul"
                                name="filter-row"
                                class="relative grid grid-cols-1 gap-1"
                                aria-label="Clientes esperando respuesta"
                            >
                                <li
                                    v-for="item in waiting"
                                    :key="item.id"
                                    class="list-row flex items-center gap-3 px-2.5 py-2"
                                >
                                    <ListAvatar
                                        :name="item.contact"
                                        :pending="item.overdue"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"
                                        >
                                            <span class="font-medium">{{
                                                item.contact
                                            }}</span>
                                            <DepartmentTag
                                                v-if="item.department"
                                                :name="item.department.name"
                                                :color="item.department.color"
                                            />
                                            <span
                                                v-if="item.overdue"
                                                class="inline-flex items-center gap-1 rounded-full border border-warning/50 bg-warning-soft px-2 py-0.5 text-xs text-warning-soft-foreground"
                                            >
                                                <Hourglass
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                Más de
                                                {{ control.tolerance_minutes }}
                                                min
                                            </span>
                                        </p>
                                        <p
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{
                                                item.agent?.name ?? 'Sin agente'
                                            }}
                                            · espera desde
                                            <time
                                                :datetime="
                                                    item.waiting_since ??
                                                    undefined
                                                "
                                                :title="
                                                    formatDateTime(
                                                        item.waiting_since,
                                                    )
                                                "
                                                >{{
                                                    formatRelative(
                                                        item.waiting_since,
                                                    )
                                                }}</time
                                            >
                                        </p>
                                    </div>
                                    <Button
                                        as-child
                                        variant="ghost"
                                        size="sm"
                                        class="shrink-0 rounded-full"
                                    >
                                        <Link
                                            :href="item.url"
                                            :aria-label="`Abrir la conversación con ${item.contact}`"
                                            >Abrir</Link
                                        >
                                    </Button>
                                </li>
                            </TransitionGroup>
                        </div>
                    </SurfaceCard>

                    <SurfaceCard
                        v-if="can.send"
                        title="Alertas enviadas"
                        description="Mostrada: la aplicación del agente la presentó en pantalla. Confirmada: el agente pulsó «Entendido»."
                    >
                        <div class="px-3 pb-4 sm:px-4">
                            <div
                                v-if="alerts.length === 0"
                                class="flex items-center gap-3 rounded-xl border border-dashed bg-surface-sunken/50 px-4 py-5 text-sm text-muted-foreground"
                            >
                                <span
                                    class="insight-tile"
                                    data-tone="muted"
                                    aria-hidden="true"
                                >
                                    <BellRing class="size-4" />
                                </span>
                                Todavía no se han enviado alertas.
                            </div>
                            <ul
                                v-else
                                class="grid gap-2"
                                aria-label="Alertas enviadas"
                            >
                                <li v-for="alert in alerts" :key="alert.id">
                                    <button
                                        type="button"
                                        class="grid w-full gap-1.5 rounded-xl border interactive-surface bg-card px-3 py-2.5 text-left shadow-(--shadow-control) outline-none focus-visible:ring-[3px] focus-visible:ring-primary/25"
                                        data-attention-history
                                        @click="openDetail(alert)"
                                    >
                                        <span
                                            class="flex min-w-0 items-center gap-2 text-sm"
                                        >
                                            <span
                                                :class="
                                                    cn(
                                                        'size-4 shrink-0 rounded-full border',
                                                        ALERT_SURFACE[
                                                            alert.color
                                                        ]?.surface,
                                                    )
                                                "
                                                role="img"
                                                :aria-label="`Fondo ${colorLabel(alert.color)}`"
                                                :title="`Fondo ${colorLabel(alert.color)}`"
                                                data-attention-history-color
                                            />
                                            <span
                                                :class="
                                                    cn(
                                                        'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold',
                                                        PRIORITY_LOOK[
                                                            alert.priority
                                                        ].badge,
                                                    )
                                                "
                                            >
                                                <component
                                                    :is="
                                                        PRIORITY_LOOK[
                                                            alert.priority
                                                        ].icon
                                                    "
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                {{ alert.priority_label }}
                                            </span>
                                            <span
                                                class="min-w-0 truncate font-medium"
                                                >{{ alert.title }}</span
                                            >
                                        </span>
                                        <span
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ alert.audience_label }} ·
                                            {{ alert.sender }} ·
                                            <time
                                                :datetime="
                                                    alert.sent_at ?? undefined
                                                "
                                                :title="
                                                    formatDateTime(
                                                        alert.sent_at,
                                                    )
                                                "
                                                >{{
                                                    formatRelative(
                                                        alert.sent_at,
                                                    )
                                                }}</time
                                            >
                                        </span>
                                        <span
                                            class="grid grid-cols-2 gap-2 text-[0.6875rem] text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            <span class="grid gap-1">
                                                <span class="tabular-nums"
                                                    >{{
                                                        alert.delivered_count
                                                    }}/{{
                                                        alert.recipients_count
                                                    }}
                                                    mostradas</span
                                                >
                                                <span
                                                    class="h-1 overflow-hidden rounded-full bg-muted"
                                                >
                                                    <span
                                                        class="block h-full rounded-full bg-primary/60 transition-[width] duration-(--duration-slow) ease-(--ease-out-quint)"
                                                        :style="{
                                                            width: `${ratio(alert.delivered_count, alert.recipients_count)}%`,
                                                        }"
                                                    />
                                                </span>
                                            </span>
                                            <span class="grid gap-1">
                                                <span class="tabular-nums"
                                                    >{{
                                                        alert.acknowledged_count
                                                    }}/{{
                                                        alert.recipients_count
                                                    }}
                                                    confirmadas</span
                                                >
                                                <span
                                                    class="h-1 overflow-hidden rounded-full bg-muted"
                                                >
                                                    <span
                                                        class="block h-full rounded-full bg-success transition-[width] duration-(--duration-slow) ease-(--ease-out-quint)"
                                                        :style="{
                                                            width: `${ratio(alert.acknowledged_count, alert.recipients_count)}%`,
                                                        }"
                                                    />
                                                </span>
                                            </span>
                                        </span>
                                        <span class="sr-only">
                                            {{ alert.delivered_count }} de
                                            {{ alert.recipients_count }}
                                            mostradas,
                                            {{ alert.acknowledged_count }} de
                                            {{ alert.recipients_count }}
                                            confirmadas
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </SurfaceCard>
                </aside>
            </div>
        </div>

        <details
            class="group/glossary rounded-xl border bg-card px-4 py-3 text-sm shadow-(--shadow-control) transition-colors duration-(--duration-fast) hover:border-border-strong"
            data-attention-glossary
        >
            <summary
                class="flex cursor-pointer list-none items-center gap-2 font-medium [&::-webkit-details-marker]:hidden"
            >
                <ChevronRight
                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-(--duration-base) ease-(--ease-out-quint) group-open/glossary:rotate-90"
                    aria-hidden="true"
                />
                ¿En qué se diferencian los recordatorios, las alertas de SLA y
                el control de pendientes?
            </summary>
            <dl
                class="mt-3 grid gap-x-8 gap-y-3 text-muted-foreground lg:grid-cols-3"
            >
                <div>
                    <dt class="font-medium text-foreground">
                        Recordatorios enviados por administradores
                    </dt>
                    <dd>
                        Mensajes que una persona escribe y envía a uno o varios
                        agentes desde «Seguimiento de agentes». Aparecen en la
                        pantalla del agente hasta que pulsa «Entendido» y se
                        consultan en «Alertas enviadas».
                    </dd>
                </div>
                <div v-if="phaseTwo">
                    <dt class="font-medium text-foreground">
                        Alertas automáticas de SLA
                    </dt>
                    <dd>
                        Avisos que genera el sistema, sin que nadie los escriba,
                        cuando una solicitud está por vencer o venció su tiempo
                        máximo de respuesta. Llegan a la campana del agente
                        responsable y de supervisión, y se revisan en «Monitoreo
                        de SLA».
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-foreground">
                        Control de conversaciones pendientes
                    </dt>
                    <dd>
                        Regla que impide a un agente aceptar conversaciones
                        nuevas mientras tenga clientes esperando su respuesta
                        más del tiempo tolerado. No envía avisos ni depende de
                        los objetivos de SLA.
                    </dd>
                </div>
            </dl>
        </details>

        <AttentionAlertComposer
            v-if="can.send"
            v-model:open="composerOpen"
            :agents="agents"
            :initial-recipients="composerRecipients"
            :initial-audience="composerAudience"
            :sender-name="senderName"
            :options="options"
            @sent="onSent"
        />

        <Dialog v-model:open="detailOpen">
            <DialogContent
                class="max-h-[min(40rem,calc(100dvh-2rem))] overflow-y-auto sm:max-w-lg"
            >
                <DialogHeader>
                    <DialogTitle>{{ detail?.alert.title }}</DialogTitle>
                    <DialogDescription>
                        {{ detail?.alert.priority_label }} · enviada por
                        {{ detail?.alert.sender }}
                        {{ formatRelative(detail?.alert.sent_at) }}
                    </DialogDescription>
                </DialogHeader>
                <AttentionAlertCard
                    v-if="detail"
                    preview
                    :title="detail.alert.title"
                    :body="detail.alert.body"
                    :priority="detail.alert.priority"
                    :priority-label="detail.alert.priority_label"
                    :color="detail.alert.color"
                    :sender="detail.alert.sender"
                    :sent-at="detail.alert.sent_at"
                    :link-pending="detail.alert.link_pending"
                />
                <div
                    v-if="detailHttp.processing"
                    class="flex items-center gap-2 py-4 text-sm text-muted-foreground"
                    role="status"
                >
                    <Spinner />
                    Cargando destinatarios…
                </div>
                <p
                    v-else-if="detailFailed"
                    class="rounded-xl border border-dashed bg-surface-sunken/50 px-4 py-4 text-sm text-muted-foreground"
                    data-attention-detail-empty
                >
                    No se pudo cargar la lista de destinatarios. Cierra y vuelve
                    a abrir la alerta para intentarlo de nuevo.
                </p>
                <ul
                    v-else
                    class="divide-y overflow-hidden rounded-xl border text-sm"
                    aria-label="Destinatarios"
                >
                    <li
                        v-for="recipient in detail?.recipients ?? []"
                        :key="recipient.id"
                        class="flex flex-col gap-0.5 px-3 py-2 sm:flex-row sm:justify-between"
                    >
                        <span class="font-medium">{{
                            recipient.name ?? 'Persona eliminada'
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            recipientState(recipient)
                        }}</span>
                    </li>
                </ul>
            </DialogContent>
        </Dialog>
    </div>
</template>
