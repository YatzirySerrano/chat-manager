<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowUpRight,
    Bell,
    BellRing,
    Check,
    CheckCheck,
    Download,
    Lock,
    RotateCcw,
    Settings2,
    Timer,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AttentionAlertBellItem from '@/components/attention/AttentionAlertBellItem.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import NotificationKindIcon from '@/components/notifications/NotificationKindIcon.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Segmented } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAttentionAlerts } from '@/composables/useAttentionAlerts';
import { useNotificationCenter } from '@/composables/useNotificationCenter';
import { formatDateTime, platformTimezone } from '@/lib/format';
import {
    notificationActionLabel,
    notificationKind,
    occurrencesText,
} from '@/lib/notifications/kinds';
import { cn } from '@/lib/utils';
import { index as conversationsIndex } from '@/routes/conversations';
import { edit as editPreferences } from '@/routes/notifications/preferences';
import type { AppNotification } from '@/types';

const center = useNotificationCenter();
/*
 * The administrators' attention alerts, the same list (and the same
 * «Entendido») as the floating cards: they keep their own rule (only
 * «Entendido» acknowledges them; opening the bell or «Marcar todo leído»
 * does not) and never touch the read state of conversations or chats.
 */
const attention = useAttentionAlerts();
const open = ref(false);

const pendingAlerts = computed(() => attention.total.value);
const urgent = computed(
    () => center.unread.value.urgent > 0 || attention.urgent.value,
);
const count = computed(() => center.unread.value.total + pendingAlerts.value);

const badge = computed(() => (count.value > 99 ? '99+' : String(count.value)));

function alertsText(total: number): string {
    return total === 1
        ? '1 alerta por confirmar'
        : `${total} alertas por confirmar`;
}

/** «3 sin leer · 1 alerta por confirmar», or «Estás al día». */
const summary = computed(() => {
    const parts: string[] = [];

    if (center.unread.value.total > 0) {
        parts.push(`${center.unread.value.total} sin leer`);
    }

    if (pendingAlerts.value > 0) {
        parts.push(alertsText(pendingAlerts.value));
    }

    return parts.join(' · ');
});

const label = computed(() =>
    count.value === 0
        ? 'Notificaciones, sin pendientes'
        : `Notificaciones, ${summary.value}`,
);

/** Alerts waiting beyond the listed page (they come as these are confirmed). */
const moreAlerts = computed(() =>
    Math.max(0, pendingAlerts.value - attention.alerts.value.length),
);

const filterOptions = [
    { value: false, label: 'Todas' },
    { value: true, label: 'Sin leer' },
];

function viewPending(): void {
    open.value = false;
    router.visit(conversationsIndex({ query: { view: 'mine' } }));
}

function select(item: AppNotification): void {
    if (item.url) {
        open.value = false;
    }

    center.open(item);
}

/*
 * Time of each row: relative within today («hace 3 min»), the clock time
 * on older days (their group already says which day). The full date is in
 * the title. «now» advances every 30 s while the bell is open.
 */
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | null = null;

function stopClock(): void {
    if (clock !== null) {
        clearInterval(clock);
        clock = null;
    }
}

watch(open, (value) => {
    attention.bellOpen.value = value;
    stopClock();

    if (value) {
        now.value = Date.now();
        clock = setInterval(() => (now.value = Date.now()), 30_000);
    }
});

onBeforeUnmount(() => {
    attention.bellOpen.value = false;
    stopClock();
});

function dayKey(at: number): string {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: platformTimezone(),
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(at);
    } catch {
        return new Date(at).toISOString().slice(0, 10);
    }
}

function dayLabel(at: number, key: string): string {
    if (key === dayKey(now.value)) {
        return 'Hoy';
    }

    if (key === dayKey(now.value - 86_400_000)) {
        return 'Ayer';
    }

    const sameYear = key.slice(0, 4) === dayKey(now.value).slice(0, 4);
    const text = formatDateTime(new Date(at).toISOString(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        ...(sameYear ? {} : { year: 'numeric' }),
    });

    return text.charAt(0).toUpperCase() + text.slice(1);
}

type Group = {
    key: string;
    label: string;
    items: { item: AppNotification; index: number }[];
};

/** The list split by day, newest first, as the server sends it. */
const groups = computed<Group[]>(() => {
    const result: Group[] = [];
    let index = 0;

    for (const item of center.items.value) {
        const at = Date.parse(item.updated_at ?? item.created_at ?? '');
        const key = Number.isFinite(at) ? dayKey(at) : 'unknown';
        let group = result[result.length - 1];

        if (!group || group.key !== key) {
            group = {
                key,
                label: Number.isFinite(at) ? dayLabel(at, key) : 'Sin fecha',
                items: [],
            };
            result.push(group);
        }

        group.items.push({ item, index: index++ });
    }

    return result;
});

function timeText(iso: string | null): string {
    const at = Date.parse(iso ?? '');

    if (!Number.isFinite(at)) {
        return '';
    }

    if (dayKey(at) !== dayKey(now.value)) {
        return formatDateTime(iso, { hour: '2-digit', minute: '2-digit' });
    }

    const minutes = Math.floor(Math.max(0, now.value - at) / 60_000);

    if (minutes < 1) {
        return 'ahora';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    return `hace ${Math.floor(minutes / 60)} h`;
}

function hasActions(item: AppNotification): boolean {
    return item.read_at === null || item.url !== null;
}

/** Room the row actions take at the end of the meta line. */
function actionsRoom(item: AppNotification): string | false {
    const unread = item.read_at === null;

    if (item.url === null) {
        return unread && 'pr-9';
    }

    if (item.kind === 'export.finished') {
        return unread ? 'pr-[8.25rem]' : 'pr-24';
    }

    return unread ? 'pr-[6.25rem]' : 'pr-[4.25rem]';
}

function onOpenChange(value: boolean): void {
    open.value = value;

    if (value) {
        void center.load();
        void attention.reload();
    }
}
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <!--
            The tooltip has its own floating anchor: without an explicit
            anchor outside it, the popover would attach to the tooltip's and
            never be positioned (it stayed off screen).
        -->
        <PopoverAnchor as-child>
            <span class="inline-flex">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <PopoverTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-9 rounded-lg text-muted-foreground pointer-coarse:size-10"
                                :aria-label="label"
                            >
                                <BellRing
                                    v-if="urgent"
                                    class="bell-ring size-4 text-foreground"
                                />
                                <Bell v-else class="size-4" />
                                <span
                                    v-if="count > 0"
                                    :key="badge"
                                    :class="
                                        cn(
                                            'badge-pop absolute -top-1 -right-1 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full px-1 text-[0.6875rem] leading-none font-semibold tabular-nums ring-2 ring-background',
                                            urgent
                                                ? 'bg-destructive text-destructive-foreground'
                                                : 'bg-primary text-primary-foreground',
                                        )
                                    "
                                    aria-hidden="true"
                                    data-notification-badge
                                >
                                    {{ badge }}
                                </span>
                            </Button>
                        </PopoverTrigger>
                    </TooltipTrigger>
                    <TooltipContent>Notificaciones</TooltipContent>
                </Tooltip>
            </span>
        </PopoverAnchor>

        <PopoverContent
            align="end"
            :side-offset="8"
            class="notification-panel flex max-h-[min(38rem,calc(100dvh-5rem))] w-[min(25rem,calc(100vw-1.5rem))] flex-col overflow-hidden bg-popover! p-0 backdrop-filter-none!"
        >
            <header class="grid gap-3 border-b px-4 pt-3.5 pb-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2
                            class="flex items-center gap-2 font-display text-base leading-tight font-semibold"
                        >
                            Notificaciones
                            <span
                                v-if="center.unread.value.total > 0"
                                class="inline-flex h-5 min-w-5 items-center justify-center rounded-md bg-primary px-1.5 text-[0.6875rem] font-semibold text-primary-foreground tabular-nums"
                                aria-hidden="true"
                            >
                                {{
                                    center.unread.value.total > 99
                                        ? '99+'
                                        : center.unread.value.total
                                }}
                            </span>
                        </h2>
                        <p
                            class="mt-0.5 text-xs text-muted-foreground"
                            aria-live="polite"
                            data-notification-summary
                        >
                            {{ count === 0 ? 'Estás al día' : summary }}
                        </p>
                    </div>
                    <!--
                        A native title, not a Tooltip: the popover focuses
                        its first control on open, and a tooltip would pop
                        up every time the bell opens.
                    -->
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="-mt-1 -mr-1.5 text-muted-foreground"
                        as-child
                    >
                        <Link
                            :href="editPreferences()"
                            aria-label="Preferencias de notificación"
                            title="Preferencias de notificación"
                            data-notification-preferences
                            @click="open = false"
                        >
                            <Settings2 />
                        </Link>
                    </Button>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <Segmented
                        v-model="center.onlyUnread.value"
                        :options="filterOptions"
                        size="sm"
                        aria-label="Mostrar"
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                        :disabled="center.unread.value.total === 0"
                        @click="center.markAllRead()"
                    >
                        <CheckCheck class="size-3.5" />
                        Marcar todas como leídas
                    </Button>
                </div>
            </header>

            <div
                class="notification-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain"
            >
                <section
                    v-if="attention.alerts.value.length > 0"
                    class="grid gap-2 border-b bg-muted/40 p-3"
                    aria-labelledby="bell-attention-heading"
                    data-bell-attention
                >
                    <h3
                        id="bell-attention-heading"
                        class="flex items-center justify-between gap-2 text-xs font-semibold text-muted-foreground"
                    >
                        <span>Alertas de administración</span>
                        <span class="font-normal tabular-nums">{{
                            alertsText(pendingAlerts)
                        }}</span>
                    </h3>
                    <ul class="grid gap-2">
                        <li
                            v-for="alert in attention.ordered.value"
                            :key="alert.id"
                        >
                            <AttentionAlertBellItem
                                :alert="alert"
                                :acknowledging="
                                    attention.acknowledging.value === alert.id
                                "
                                :busy="attention.acknowledging.value !== null"
                                @acknowledge="attention.acknowledge(alert)"
                                @view-pending="viewPending"
                            />
                        </li>
                    </ul>
                    <p
                        v-if="moreAlerts > 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            moreAlerts === 1
                                ? '1 alerta más: aparecerá al confirmar las anteriores.'
                                : `${moreAlerts} alertas más: aparecerán al confirmar las anteriores.`
                        }}
                    </p>
                </section>

                <div
                    v-if="!center.loaded.value && center.loading.value"
                    class="grid gap-1 p-2"
                    aria-busy="true"
                >
                    <div
                        v-for="index in 4"
                        :key="index"
                        class="flex gap-3 px-2.5 py-2.5"
                    >
                        <Skeleton class="size-9 shrink-0 rounded-[0.625rem]" />
                        <div class="flex-1 space-y-2 pt-0.5">
                            <Skeleton class="h-3.5 w-3/4" />
                            <Skeleton class="h-3 w-11/12" />
                            <Skeleton class="h-3 w-1/3" />
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="
                        center.failed.value && center.items.value.length === 0
                    "
                    class="flex flex-col items-center gap-3 px-6 py-10 text-center text-sm"
                >
                    <span
                        class="grid size-10 place-items-center rounded-xl bg-warning-soft text-warning-soft-foreground"
                    >
                        <AlertTriangle class="size-5" aria-hidden="true" />
                    </span>
                    <p class="text-muted-foreground">
                        No se pudieron cargar las notificaciones.
                    </p>
                    <Button variant="outline" size="sm" @click="center.load()">
                        <RotateCcw />
                        Reintentar
                    </Button>
                </div>

                <p
                    v-else-if="
                        center.items.value.length === 0 &&
                        attention.alerts.value.length > 0
                    "
                    class="p-4 text-xs text-muted-foreground"
                >
                    {{
                        center.onlyUnread.value
                            ? 'No tienes otras notificaciones sin leer.'
                            : 'No tienes otras notificaciones.'
                    }}
                </p>

                <div
                    v-else-if="center.items.value.length === 0"
                    class="flex flex-col items-center px-8 py-12 text-center"
                    data-notification-empty
                >
                    <span
                        class="relative mb-4 grid size-12 place-items-center rounded-2xl border bg-muted text-muted-foreground"
                    >
                        <CheckCheck
                            v-if="center.onlyUnread.value"
                            class="size-5"
                            aria-hidden="true"
                        />
                        <Bell v-else class="size-5" aria-hidden="true" />
                    </span>
                    <p class="text-sm font-semibold">
                        {{
                            center.onlyUnread.value
                                ? 'Nada pendiente por leer'
                                : 'Aún no tienes notificaciones'
                        }}
                    </p>
                    <p
                        class="mt-1 max-w-[17rem] text-xs leading-relaxed text-muted-foreground"
                    >
                        {{
                            center.onlyUnread.value
                                ? 'Ya leíste todo.'
                                : 'Aquí verás asignaciones, mensajes y avisos.'
                        }}
                    </p>
                    <Button
                        v-if="center.onlyUnread.value"
                        variant="outline"
                        size="sm"
                        class="mt-4"
                        @click="center.onlyUnread.value = false"
                    >
                        Ver todas
                    </Button>
                </div>

                <template v-else>
                    <section
                        v-for="group in groups"
                        :key="group.key"
                        :aria-labelledby="`notification-day-${group.key}`"
                        data-notification-day
                    >
                        <h3
                            :id="`notification-day-${group.key}`"
                            class="sticky top-0 z-10 border-b border-border/70 bg-popover px-4 py-1.5 text-xs font-medium text-muted-foreground"
                        >
                            {{ group.label }}
                        </h3>
                        <ul class="grid gap-px p-1.5">
                            <li
                                v-for="{ item, index } in group.items"
                                :key="item.id"
                                class="notification-row group/notification relative rounded-lg"
                                :style="{ '--i': Math.min(index, 10) }"
                                :data-notification-kind="item.kind"
                                :data-unread="
                                    item.read_at === null || undefined
                                "
                            >
                                <button
                                    type="button"
                                    class="flex w-full gap-3 rounded-lg py-2.5 pr-3 pl-3.5 text-left transition-colors duration-(--duration-fast) hover:bg-accent/70 focus-visible:bg-accent/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    @click="select(item)"
                                >
                                    <span
                                        class="relative mt-0.5 flex shrink-0 self-start"
                                    >
                                        <template v-if="item.subject">
                                            <ListAvatar
                                                :name="item.subject.name"
                                                :src="item.subject.avatar_url"
                                            />
                                            <NotificationKindIcon
                                                :item="item"
                                                size="badge"
                                                class="absolute -right-1.5 -bottom-1"
                                            />
                                        </template>
                                        <NotificationKindIcon
                                            v-else
                                            :item="item"
                                        />
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-start gap-2">
                                            <span
                                                class="line-clamp-2 min-w-0 flex-1 text-sm leading-snug"
                                                :class="
                                                    item.read_at === null
                                                        ? 'font-semibold text-foreground'
                                                        : 'font-medium text-foreground/75'
                                                "
                                            >
                                                {{ item.title }}
                                            </span>
                                            <time
                                                class="mt-px shrink-0 text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                                :datetime="
                                                    item.updated_at ?? undefined
                                                "
                                                :title="
                                                    formatDateTime(
                                                        item.updated_at,
                                                        {
                                                            dateStyle: 'full',
                                                            timeStyle: 'short',
                                                        },
                                                    )
                                                "
                                            >
                                                {{ timeText(item.updated_at) }}
                                            </time>
                                        </span>
                                        <span
                                            v-if="item.body"
                                            class="mt-0.5 line-clamp-2 block text-[0.8125rem] leading-snug text-muted-foreground"
                                        >
                                            {{ item.body }}
                                        </span>
                                        <span
                                            class="mt-1.5 flex min-h-6 flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                                            :class="actionsRoom(item)"
                                        >
                                            <span class="font-medium">
                                                {{
                                                    notificationKind(item).label
                                                }}
                                            </span>
                                            <span
                                                v-if="item.level === 'urgent'"
                                                class="inline-flex h-5 items-center rounded-md bg-destructive-soft px-1.5 font-medium text-destructive-soft-foreground"
                                                data-notification-urgent
                                            >
                                                Urgente
                                            </span>
                                            <span
                                                v-if="occurrencesText(item)"
                                                class="inline-flex h-5 items-center rounded-md bg-muted px-1.5 font-medium text-foreground/80 tabular-nums"
                                                data-notification-occurrences
                                            >
                                                {{ occurrencesText(item) }}
                                            </span>
                                            <span
                                                v-if="
                                                    item.kind.startsWith('sla.')
                                                "
                                                class="inline-flex h-5 items-center gap-1 rounded-md border px-1.5"
                                                data-notification-sla
                                            >
                                                <Timer
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                SLA automático
                                            </span>
                                            <span
                                                v-if="!item.accessible"
                                                class="inline-flex h-5 items-center gap-1 rounded-md border px-1.5"
                                            >
                                                <Lock
                                                    class="size-3"
                                                    aria-hidden="true"
                                                />
                                                Sin acceso
                                            </span>
                                            <span
                                                v-if="item.read_at === null"
                                                class="sr-only"
                                            >
                                                Sin leer
                                            </span>
                                        </span>
                                    </span>
                                </button>

                                <!-- Unread: a dot in the gutter that fades once read. -->
                                <span
                                    class="unread-dot pointer-events-none absolute top-[1.15rem] left-1 size-1.5 rounded-full bg-primary"
                                    :class="
                                        item.read_at === null
                                            ? 'opacity-100'
                                            : 'scale-50 opacity-0'
                                    "
                                    aria-hidden="true"
                                />

                                <div
                                    v-if="hasActions(item)"
                                    class="row-actions absolute right-2.5 bottom-2 flex items-center gap-1"
                                >
                                    <Tooltip v-if="item.read_at === null">
                                        <TooltipTrigger as-child>
                                            <button
                                                type="button"
                                                class="row-action grid size-7 place-items-center rounded-md border bg-popover text-muted-foreground hover:bg-accent hover:text-foreground"
                                                aria-label="Marcar como leída"
                                                data-notification-mark-read
                                                @click="center.markRead(item)"
                                            >
                                                <Check
                                                    class="size-3.5"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent
                                            >Marcar como leída</TooltipContent
                                        >
                                    </Tooltip>
                                    <button
                                        v-if="item.url"
                                        type="button"
                                        class="row-action inline-flex h-7 items-center gap-1 rounded-md border bg-popover px-2 text-xs font-medium text-foreground hover:bg-accent"
                                        :aria-label="`${notificationActionLabel(item)}: ${item.title}`"
                                        data-notification-action
                                        @click="select(item)"
                                    >
                                        <Download
                                            v-if="
                                                item.kind === 'export.finished'
                                            "
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ notificationActionLabel(item) }}
                                        <ArrowUpRight
                                            v-if="
                                                item.kind !== 'export.finished'
                                            "
                                            class="size-3.5 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            </li>
                        </ul>
                    </section>
                </template>

                <div
                    v-if="center.hasMore.value"
                    class="border-t p-2 text-center"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="center.loading.value"
                        @click="center.loadMore()"
                        :loading="center.loading.value"
                    >
                        Ver anteriores
                    </Button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>

<style scoped>
/* The count pops when it changes (the span is keyed by its text). */
.badge-pop {
    animation: badge-pop var(--duration-panel) var(--ease-spring);
}

/* An urgent notification rings the bell once, briefly. */
.bell-ring {
    transform-origin: 50% 10%;
    animation: bell-ring 700ms var(--ease-standard);
}

/* Rows fade in once, a few milliseconds apart (capped at ten). */
.notification-row {
    animation: row-in 260ms var(--ease-standard) backwards;
    animation-delay: calc(var(--i, 0) * 28ms);
}

.unread-dot {
    transition:
        opacity 320ms var(--ease-standard),
        scale 320ms var(--ease-standard);
}

/*
 * Row actions appear on hover or keyboard focus; on touch screens (no
 * hover) they are always visible.
 */
.row-actions {
    opacity: 0;
    transition: opacity var(--duration-fast) var(--ease-standard);
}

.group\/notification:hover .row-actions,
.group\/notification:focus-within .row-actions {
    opacity: 1;
}

@media (hover: none), (pointer: coarse) {
    .row-actions {
        opacity: 1;
    }
}

.row-action:focus-visible {
    outline: none;
    box-shadow: 0 0 0 2px var(--ring);
}

@keyframes row-in {
    from {
        opacity: 0;
        transform: translateY(3px);
    }
}

@keyframes badge-pop {
    from {
        transform: scale(0.4);
        opacity: 0;
    }
}

@keyframes bell-ring {
    0%,
    100% {
        rotate: 0deg;
    }

    15% {
        rotate: 14deg;
    }

    30% {
        rotate: -12deg;
    }

    45% {
        rotate: 8deg;
    }

    60% {
        rotate: -5deg;
    }

    75% {
        rotate: 2deg;
    }
}

@media (prefers-reduced-motion: reduce) {
    .notification-row,
    .badge-pop,
    .bell-ring {
        animation: none;
    }

    .unread-dot,
    .row-actions {
        transition: none;
    }
}
</style>
