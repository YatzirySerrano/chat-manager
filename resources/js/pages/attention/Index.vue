<script setup lang="ts">
import { Head, Link, router, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import {
    BellRing,
    Clock,
    Hourglass,
    Inbox,
    Lock,
    RefreshCw,
    Search,
    Send,
    UserRoundX,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AttentionAlertCard from '@/components/attention/AttentionAlertCard.vue';
import AttentionAlertComposer from '@/components/attention/AttentionAlertComposer.vue';
import { ALERT_SURFACE, PRIORITY_LOOK } from '@/components/attention/priority';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SlaMonitorPanel from '@/components/sla/SlaMonitorPanel.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
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
    slaFilters: SlaMonitorFilters;
    slaOptions: SlaMonitorOptions;
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

const selected = ref<number[]>([]);

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

async function openDetail(alert: AttentionAlertSummary): Promise<void> {
    detail.value = { alert, recipients: [] };
    detailOpen.value = true;

    try {
        detail.value = await detailHttp.get(showAlert.url(alert.id));
    } catch {
        detail.value = { alert, recipients: [] };
    }
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
                <span class="text-xs text-muted-foreground"
                    >Actualizado {{ formatRelative(generatedAt) }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <Spinner v-if="refreshing" />
                    <RefreshCw v-else aria-hidden="true" />
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

        <div
            class="flex flex-wrap gap-1 border-b pb-2"
            role="tablist"
            aria-label="Secciones del seguimiento"
        >
            <Button
                v-for="section in [
                    {
                        value: 'attention' as const,
                        label: 'Seguimiento de agentes',
                    },
                    { value: 'sla' as const, label: 'Monitoreo de SLA' },
                ]"
                :id="`attention-tab-${section.value}`"
                :key="section.value"
                role="tab"
                size="sm"
                :variant="activeTab === section.value ? 'secondary' : 'ghost'"
                :aria-selected="activeTab === section.value"
                :data-attention-tab="section.value"
                @click="selectTab(section.value)"
            >
                {{ section.label }}
            </Button>
        </div>

        <details
            class="rounded-xl border bg-surface-raised px-4 py-3 text-sm"
            data-attention-glossary
        >
            <summary class="cursor-pointer font-medium">
                ¿En qué se diferencian los recordatorios, las alertas de SLA y
                el control de pendientes?
            </summary>
            <dl class="mt-3 grid gap-3 text-muted-foreground">
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
                <div>
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

        <SlaMonitorPanel
            v-if="activeTab === 'sla'"
            role="tabpanel"
            aria-labelledby="attention-tab-sla"
            :data="sla"
            :history="slaBreaches"
            :filters="slaFilters"
            :options="slaOptions"
            :settings-url="settingsUrl"
        />

        <template v-else>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Agentes"
                    :value="agents.length"
                    :icon="Users"
                    hint="Que atienden clientes dentro de tu alcance"
                />
                <StatCard
                    label="Clientes esperando respuesta"
                    :value="awaitingTotal"
                    :icon="Hourglass"
                    hint="El cliente escribió y el agente aún no responde"
                />
                <StatCard
                    label="Agentes sin poder aceptar"
                    :value="control.enabled ? blockedCount : null"
                    :icon="Lock"
                    :hint="`Con clientes esperando más de ${control.tolerance_minutes} min`"
                    unavailable-label="El control de pendientes está desactivado"
                />
                <StatCard
                    label="En cola sin agente"
                    :value="queue.count"
                    :icon="Inbox"
                    :hint="
                        queue.oldest_since
                            ? `La más antigua espera desde ${formatRelative(queue.oldest_since)}`
                            : 'Ninguna esperando'
                    "
                />
            </div>

            <SurfaceCard
                v-if="oldestWaiting"
                body-class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="flex items-start gap-2 text-sm">
                    <Clock
                        class="mt-0.5 size-4 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <span>
                        Espera más larga:
                        <strong>{{ oldestWaiting.contact }}</strong> con
                        {{ oldestWaiting.agent?.name ?? 'sin agente' }}, desde
                        <time
                            :datetime="oldestWaiting.waiting_since ?? undefined"
                            :title="formatDateTime(oldestWaiting.waiting_since)"
                            >{{
                                formatRelative(oldestWaiting.waiting_since)
                            }}</time
                        >.
                    </span>
                </p>
                <Button as-child variant="outline" size="sm">
                    <Link :href="oldestWaiting.url">Abrir conversación</Link>
                </Button>
            </SurfaceCard>

            <SurfaceCard
                title="Agentes"
                description="Ordenados por acumulación: primero quienes tienen clientes esperando más tiempo."
            >
                <div class="grid gap-3 px-5 pb-5">
                    <div
                        class="flex flex-col gap-2 md:flex-row md:items-center"
                    >
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                v-model="search"
                                type="search"
                                class="pl-8"
                                placeholder="Buscar agente"
                                aria-label="Buscar agente"
                            />
                        </div>
                        <Select v-model="department">
                            <SelectTrigger
                                class="md:w-56"
                                aria-label="Filtrar por departamento"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all"
                                    >Todos los departamentos</SelectItem
                                >
                                <SelectItem
                                    v-for="item in departments"
                                    :key="item.id"
                                    :value="String(item.id)"
                                    >{{ item.name }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <label
                            class="flex min-h-10 cursor-pointer items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="onlyWaiting"
                                @update:model-value="
                                    (checked) =>
                                        (onlyWaiting = checked === true)
                                "
                            />
                            Solo con clientes esperando
                        </label>
                    </div>

                    <div
                        v-if="can.send && shownAgents.length > 0"
                        class="flex flex-wrap items-center gap-3 rounded-lg bg-muted/40 px-3 py-2 text-sm"
                    >
                        <label class="flex cursor-pointer items-center gap-2">
                            <Checkbox
                                :model-value="allShownSelected"
                                @update:model-value="
                                    (checked) =>
                                        toggleAllShown(checked === true)
                                "
                            />
                            Elegir los de la lista
                        </label>
                        <span class="text-muted-foreground"
                            >{{ selected.length }}
                            {{
                                selected.length === 1 ? 'elegido' : 'elegidos'
                            }}</span
                        >
                        <Button
                            size="sm"
                            class="ml-auto"
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

                    <EmptyState
                        v-if="shownAgents.length === 0"
                        :icon="UserRoundX"
                        :title="
                            agents.length === 0
                                ? 'No hay agentes a tu cargo'
                                : 'Ningún agente coincide'
                        "
                        :description="
                            agents.length === 0
                                ? 'Aquí aparecen las personas activas que atienden clientes dentro de tu alcance.'
                                : 'Cambia la búsqueda o los filtros.'
                        "
                    />

                    <ul v-else class="grid gap-2" aria-label="Agentes">
                        <li
                            v-for="agent in shownAgents"
                            :key="agent.id"
                            :class="
                                cn(
                                    'grid gap-3 rounded-xl border p-3 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:items-center',
                                    agent.blocked &&
                                        'border-warning/60 bg-warning-soft/40',
                                )
                            "
                            data-attention-agent
                        >
                            <Checkbox
                                v-if="can.send"
                                class="hidden sm:flex"
                                :model-value="selected.includes(agent.id)"
                                :aria-label="`Elegir a ${agent.name}`"
                                @update:model-value="
                                    (checked) =>
                                        toggle(agent.id, checked === true)
                                "
                            />
                            <span v-else class="hidden sm:block" />
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="font-medium"
                                        data-attention-agent-name
                                        >{{ agent.name }}</span
                                    >
                                    <span
                                        :class="
                                            cn(
                                                'inline-flex items-center gap-1 text-xs',
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
                                                    'size-2 rounded-full',
                                                    presence(agent).online
                                                        ? 'bg-success'
                                                        : 'bg-muted-foreground/40',
                                                )
                                            "
                                            aria-hidden="true"
                                        />
                                        {{ presence(agent).label }}
                                    </span>
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
                                    class="mt-0.5 truncate text-xs text-muted-foreground"
                                >
                                    {{ agent.role ?? 'Sin rol' }} ·
                                    {{
                                        agent.departments
                                            .map((item) => item.name)
                                            .join(', ') || 'Sin departamento'
                                    }}
                                </p>
                                <dl
                                    class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs"
                                >
                                    <div class="flex gap-1">
                                        <dt class="text-muted-foreground">
                                            En atención
                                        </dt>
                                        <dd class="font-semibold tabular-nums">
                                            {{ agent.in_attention }}
                                        </dd>
                                    </div>
                                    <div class="flex gap-1">
                                        <dt class="text-muted-foreground">
                                            Esperan su respuesta
                                        </dt>
                                        <dd class="font-semibold tabular-nums">
                                            {{ agent.awaiting }}
                                        </dd>
                                    </div>
                                    <div class="flex gap-1">
                                        <dt class="text-muted-foreground">
                                            Esperando al cliente
                                        </dt>
                                        <dd class="font-semibold tabular-nums">
                                            {{ agent.waiting_customer }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="agent.overdue > 0"
                                        class="flex gap-1 text-warning-soft-foreground"
                                    >
                                        <dt>
                                            Más de
                                            {{ control.tolerance_minutes }} min
                                        </dt>
                                        <dd class="font-semibold tabular-nums">
                                            {{ agent.overdue }}
                                        </dd>
                                    </div>
                                    <div
                                        v-if="agent.oldest_waiting_since"
                                        class="flex gap-1"
                                    >
                                        <dt class="text-muted-foreground">
                                            Más antigua
                                        </dt>
                                        <dd
                                            :title="
                                                formatDateTime(
                                                    agent.oldest_waiting_since,
                                                )
                                            "
                                        >
                                            {{
                                                formatRelative(
                                                    agent.oldest_waiting_since,
                                                )
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            <Button
                                v-if="can.send"
                                variant="outline"
                                size="sm"
                                class="justify-self-start sm:justify-self-end pointer-coarse:h-10"
                                @click="compose([agent.id], 'individual')"
                            >
                                <BellRing aria-hidden="true" />
                                Alertar
                            </Button>
                        </li>
                    </ul>
                </div>
            </SurfaceCard>

            <SurfaceCard
                title="Clientes esperando respuesta"
                description="Conversaciones en atención donde el cliente escribió después de la última respuesta del agente, la más antigua primero."
            >
                <div class="px-5 pb-5">
                    <p
                        v-if="waiting.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Ningún cliente espera respuesta en este momento.
                    </p>
                    <ul
                        v-else
                        class="divide-y rounded-lg border"
                        aria-label="Clientes esperando respuesta"
                    >
                        <li
                            v-for="item in waiting"
                            :key="item.id"
                            class="flex flex-col gap-1 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-2 text-sm"
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
                                        {{ control.tolerance_minutes }} min
                                    </span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.agent?.name ?? 'Sin agente' }} ·
                                    espera desde
                                    <time
                                        :datetime="
                                            item.waiting_since ?? undefined
                                        "
                                        :title="
                                            formatDateTime(item.waiting_since)
                                        "
                                        >{{
                                            formatRelative(item.waiting_since)
                                        }}</time
                                    >
                                </p>
                            </div>
                            <Button
                                as-child
                                variant="ghost"
                                size="sm"
                                class="self-start sm:self-auto"
                            >
                                <Link :href="item.url">Abrir</Link>
                            </Button>
                        </li>
                    </ul>
                </div>
            </SurfaceCard>

            <SurfaceCard
                v-if="can.send"
                title="Alertas enviadas"
                description="Mostrada: la aplicación del agente la presentó en pantalla. Confirmada: el agente pulsó «Entendido»."
            >
                <div class="px-5 pb-5">
                    <p
                        v-if="alerts.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        Todavía no se han enviado alertas.
                    </p>
                    <ul v-else class="grid gap-2" aria-label="Alertas enviadas">
                        <li v-for="alert in alerts" :key="alert.id">
                            <button
                                type="button"
                                class="grid w-full gap-1 rounded-lg border px-3 py-2.5 text-left outline-none hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring"
                                data-attention-history
                                @click="openDetail(alert)"
                            >
                                <span
                                    class="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <span
                                        :class="
                                            cn(
                                                'size-4 shrink-0 rounded-full border',
                                                ALERT_SURFACE[alert.color]
                                                    ?.surface,
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
                                                'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold',
                                                PRIORITY_LOOK[alert.priority]
                                                    .badge,
                                            )
                                        "
                                    >
                                        <component
                                            :is="
                                                PRIORITY_LOOK[alert.priority]
                                                    .icon
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
                                <span class="text-xs text-muted-foreground">
                                    {{ alert.audience_label }} ·
                                    {{ alert.sender }} ·
                                    <time
                                        :datetime="alert.sent_at ?? undefined"
                                        :title="formatDateTime(alert.sent_at)"
                                        >{{
                                            formatRelative(alert.sent_at)
                                        }}</time
                                    >
                                    · {{ alert.delivered_count }}/{{
                                        alert.recipients_count
                                    }}
                                    mostradas ·
                                    {{ alert.acknowledged_count }}/{{
                                        alert.recipients_count
                                    }}
                                    confirmadas
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>
            </SurfaceCard>
        </template>

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
                    class="flex justify-center py-4"
                >
                    <Spinner />
                </div>
                <ul
                    v-else
                    class="divide-y rounded-lg border text-sm"
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
