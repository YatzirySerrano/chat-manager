<script setup lang="ts">
import SlaBadge from '@/components/sla/SlaBadge.vue';
import {
    Bot,
    CircleCheck,
    CircleHelp,
    EllipsisVertical,
    Eye,
    FileClock,
    Hand,
    Hourglass,
    MailOpen,
    MessageSquareText,
    Tags,
    UserPen,
    UserRound,
} from '@lucide/vue';
import { computed, inject } from 'vue';
import {
    ACCEPT_BLOCK,
    noAcceptBlock,
} from '@/components/conversations/acceptBlock';
import AcceptanceNotice from '@/components/conversations/AcceptanceNotice.vue';
import CompletedBadge from '@/components/conversations/CompletedBadge.vue';
import MessageStatusIndicator from '@/components/conversations/MessageStatusIndicator.vue';
import {
    awaitingSince,
    isAwaitingInfo,
    noQueueActions,
    QUEUE_ACTIONS,
    useAcceptance,
} from '@/components/conversations/queueRules';
import {
    listTime,
    previewText,
    statusLabel,
} from '@/components/conversations/messageFormat';
import CountBadge from '@/components/filters/CountBadge.vue';
import HighlightMatch from '@/components/filters/HighlightMatch.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import { userAvatarUrl } from '@/composables/useUserAvatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useLongPress } from '@/composables/useLongPress';
import { colorPresentations } from '@/lib/colors';
import type { ColorPresentation } from '@/lib/colors';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type { AppColor, ConversationSummary } from '@/types';

/**
 * One row of the inbox list. Every viewer sees the essentials: the contact
 * (their private alias when they set one), the latest message with its
 * delivery state, the time, unread messages and the conversation's tags.
 * Pending conversations are set apart with a text badge «Pendiente» and an
 * icon (never by color alone), and a dot marks those where the customer
 * wrote last and is still waiting. Operational badges (status, department,
 * responsible agent, connection) only appear for viewers who supervise or
 * distribute work (`showOperational`), so an agent's row stays clean.
 *
 * Row actions live in a menu next to the row button (never nested in it):
 * open, mark as read, manage tags (when `can.tag`) and the personal alias
 * (when the parent offers it). A pending row the server lets the viewer
 * accept (`can.accept`) offers "Aceptar" without opening it first; while
 * the control of pending conversations blocks the viewer, it stays visible
 * but disabled, with the reason.
 *
 * Queue rules: every viewer sees the request category and, where it
 * matters, its readiness («Necesita información» / «Lista para atención»).
 * A pending row that cannot be taken yet says why below it, in text
 * (AcceptanceNotice: countdown of the minimum wait, the earlier request to
 * take first, what can be done), and that text describes the disabled
 * «Aceptar». The readiness actions live in the row menu for whoever may.
 *
 * «Esperando información del cliente» (returned for missing information):
 * the row says so with its own badge and the time since it waits, instead
 * of «Pendiente», the readiness and the queue-rule notice; it offers no
 * «Aceptar» (nobody takes it while it waits) and, for whoever may, a
 * prominent «Completa» that confirms it complete.
 *
 * Holding the row on a touch screen, right clicking it or «Vista previa» in
 * its menu shows the latest messages without opening it (`preview`): the
 * read state does not change.
 */
const props = withDefaults(
    defineProps<{
        conversation: ConversationSummary;
        selected: boolean;
        /** Unread counter to show (0 while the chat is open on screen). */
        unread: number;
        /** Department, status and connection badges (supervision). */
        showOperational?: boolean;
        /** Responsible agent badge (team inboxes of supervisors). */
        showAssignee?: boolean;
        /** The viewer's connections span more than one channel: tell it. */
        showChannel?: boolean;
        /** Conversation being accepted from the list, if any (one at a time). */
        acceptingId: number | null;
        /** The row menu offers "Asignar alias personal". */
        canEditAlias?: boolean;
        /** Applied contact search: its words are marked in the name. */
        highlight?: string;
    }>(),
    {
        highlight: '',
        showOperational: false,
        showAssignee: false,
        showChannel: false,
        canEditAlias: false,
    },
);

const emit = defineEmits<{
    select: [id: number];
    accept: [id: number];
    manageTags: [conversation: ConversationSummary];
    markRead: [id: number];
    editAlias: [conversation: ConversationSummary];
    preview: [conversation: ConversationSummary];
}>();

const acceptBlock = inject(ACCEPT_BLOCK, noAcceptBlock);
const queueActions = inject(QUEUE_ACTIONS, noQueueActions);

/* Queue rules and the viewer's own limits, counted down on the server clock. */
const acceptance = useAcceptance(() => props.conversation);
const acceptBlocked = computed(
    () => acceptBlock.value?.blocked === true || acceptance.value.blocked,
);
const acceptanceId = computed(() => `acceptance-${props.conversation.id}`);
const readiness = computed(() => props.conversation.readiness ?? null);
/* Returned for missing information: waits for the customer, outside «Pendientes». */
const awaitingInfo = computed(() => isAwaitingInfo(props.conversation));
const awaitingTitle = computed(() =>
    awaitingInfo.value
        ? `Esperando información del cliente desde ${awaitingSince(props.conversation.awaiting_info_at)}`
        : '',
);
/*
 * The row's time: since when it waits for the customer; for a request
 * waiting in «Pendientes», when it entered the queue (the order of the
 * list, the latest arrival at the bottom); otherwise its latest activity.
 */
const queuedSince = computed(() =>
    props.conversation.status === 'pending' && !props.conversation.assignee
        ? (props.conversation.queued_at ?? null)
        : null,
);
const rowTime = computed(() =>
    awaitingInfo.value
        ? (props.conversation.awaiting_info_at ?? null)
        : (queuedSince.value ?? props.conversation.last_message_at),
);
/* «Lista» only where the confirmation rule applies; «Necesita información» always. */
const showReadiness = computed(
    () =>
        readiness.value !== null &&
        props.conversation.status !== 'closed' &&
        !awaitingInfo.value &&
        (readiness.value.value === 'needs_info' ||
            (readiness.value.tracked &&
                props.conversation.status === 'pending')),
);
const acceptBlockedReason = computed(
    () =>
        acceptance.value.text ??
        (acceptBlock.value?.blocked ? acceptBlock.value.message : null),
);

const longPress = useLongPress(() => emit('preview', props.conversation));

function open(): void {
    if (!longPress.consumeClick()) {
        emit('select', props.conversation.id);
    }
}

/*
 * Name shown: the viewer's private alias when PERSONALIZATION provides it
 * (contact.display_name), the official name otherwise.
 */
type ListedContact = ConversationSummary['contact'] & {
    display_name?: string | null;
    alias?: string | null;
};

const contact = computed(() => props.conversation.contact as ListedContact);
const displayName = computed(
    () =>
        contact.value.display_name ||
        contact.value.name ||
        contact.value.profile_name ||
        (contact.value.phone_e164
            ? formatPhone(contact.value.phone_e164)
            : 'Contacto'),
);
const hasAlias = computed(() => Boolean(contact.value.alias));
const aliasTitle = computed(() =>
    contact.value.name
        ? `Alias personal. Nombre oficial: ${contact.value.name}`
        : 'Alias personal',
);

const preview = computed(() =>
    props.conversation.last_message
        ? previewText(props.conversation.last_message)
        : null,
);
const pending = computed(() => props.conversation.status === 'pending');
/* Closed as «Solicitud completada»: its badge replaces «Cerrada». */
const completed = computed(
    () =>
        props.conversation.status === 'closed' &&
        Boolean(props.conversation.completed_at),
);
const visibleTags = computed(() => (props.conversation.tags ?? []).slice(0, 2));
const hiddenTags = computed(
    () => (props.conversation.tags?.length ?? 0) - visibleTags.value.length,
);
/* Nobody takes a request while it waits for the customer's information. */
const canAccept = computed(
    () => props.conversation.can.accept && !awaitingInfo.value,
);
/* «Completa»: confirms a waiting request complete, right from the row. */
const canConfirm = computed(
    () => awaitingInfo.value && props.conversation.can.confirm_ready,
);
const accepting = computed(() => props.acceptingId === props.conversation.id);
const connectionPhone = computed(() =>
    props.conversation.connection.phone
        ? formatPhone(props.conversation.connection.phone)
        : null,
);
const connectionTitle = computed(() =>
    [
        `${props.conversation.connection.channel_label}: ${props.conversation.connection.name}`,
        connectionPhone.value,
        props.conversation.connection.state === 'active'
            ? null
            : props.conversation.connection.state === 'deleted'
              ? 'conexión eliminada'
              : 'conexión pausada',
    ]
        .filter(Boolean)
        .join(' · '),
);
/* A small colour mark (category, department, tag) in the row's meta line. */
function dotOf(color: AppColor | null | undefined): ColorPresentation {
    return colorPresentations(color).dot;
}

/* The supervision line (responsible, channel, connection) is shown. */
const operationalLine = computed(
    () =>
        props.showOperational ||
        props.showChannel ||
        (props.showAssignee && Boolean(props.conversation.assignee)),
);

const statusDot = computed(
    () =>
        ({
            pending: 'bg-warning',
            open: 'bg-success',
            on_hold: 'bg-primary',
            closed: 'bg-muted-foreground/50',
        })[props.conversation.status] ?? 'bg-muted-foreground/50',
);

const label = computed(() => {
    // Whoever may accept it hears it first: the status is part of the prefix.
    const parts = awaitingInfo.value
        ? [`Esperando información del cliente: ${displayName.value}`]
        : canAccept.value
          ? [`Pendiente de aceptar: ${displayName.value}`]
          : [
                displayName.value,
                completed.value
                    ? 'cerrada como solicitud completada'
                    : props.conversation.status_label,
            ];

    if (hasAlias.value && contact.value.name) {
        parts.push(`alias personal de ${contact.value.name}`);
    }

    if (props.unread > 0) {
        parts.push(
            props.unread === 1
                ? '1 mensaje sin leer'
                : `${props.unread} mensajes sin leer`,
        );
    }

    if (props.conversation.awaiting_reply) {
        parts.push('esperando respuesta');
    }

    if (showReadiness.value && readiness.value) {
        parts.push(readiness.value.label.toLowerCase());
    }

    if (props.conversation.category) {
        parts.push(`categoría ${props.conversation.category.name}`);
    }

    if (props.showOperational && props.conversation.department) {
        parts.push(`departamento ${props.conversation.department.name}`);
    }

    if (props.showAssignee && props.conversation.assignee) {
        parts.push(`responsable ${props.conversation.assignee.name}`);
    }

    if (props.showOperational || props.showChannel) {
        parts.push(`recibida en ${connectionTitle.value}`);
    }

    // The visible row is replaced by this label: it also carries the last
    // message (with its delivery state when it is ours) and its time.
    const last = props.conversation.last_message;

    if (last && preview.value) {
        const text =
            preview.value.length > 140
                ? `${preview.value.slice(0, 140)}…`
                : preview.value;
        const delivery =
            last.direction === 'outbound'
                ? ` (${statusLabel(last.status, last.error_code).toLowerCase()})`
                : '';

        parts.push(
            `${last.direction === 'outbound' ? 'último mensaje enviado' : 'último mensaje'}${delivery}: ${text}`,
        );
    }

    if (awaitingInfo.value) {
        parts.push(
            `desde ${awaitingSince(props.conversation.awaiting_info_at)}`,
        );
    } else if (props.conversation.last_message_at) {
        parts.push(listTime(props.conversation.last_message_at));
    }

    return parts.join(', ');
});
</script>

<template>
    <div
        class="group/row relative list-item"
        :data-readiness="readiness?.value"
        :data-selected="selected ? '' : undefined"
    >
        <div class="relative">
            <button
                type="button"
                class="list-row flex w-full items-start gap-2.5 py-2 pr-2.5 pl-3 text-left outline-none select-none [-webkit-touch-callout:none] pointer-coarse:pr-12"
                :data-selected="selected ? '' : undefined"
                :aria-current="selected ? 'true' : undefined"
                :aria-label="label"
                data-inbox-item
                v-on="longPress.handlers"
                @click="open"
            >
                <span
                    v-if="unread > 0"
                    class="list-unread-dot"
                    aria-hidden="true"
                />
                <ListAvatar
                    :name="displayName"
                    :src="conversation.contact.avatar_url"
                    :channel="conversation.connection.channel"
                    :channel-title="connectionTitle"
                    :pending="pending"
                    :dimmed="conversation.status === 'closed'"
                    class="mt-0.5"
                />

                <span class="min-w-0 flex-1">
                    <!-- Name and time. -->
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="flex min-w-0 items-center gap-1.5">
                            <span
                                class="truncate text-sm leading-5"
                                :class="
                                    unread > 0
                                        ? 'font-semibold text-foreground'
                                        : 'font-medium text-foreground/90'
                                "
                                :title="displayName"
                                ><HighlightMatch
                                    :text="displayName"
                                    :query="highlight"
                            /></span>
                            <span
                                v-if="hasAlias"
                                class="inline-flex shrink-0 text-muted-foreground"
                                :title="aliasTitle"
                                data-inbox-alias
                            >
                                <UserPen class="size-3" aria-hidden="true" />
                                <span class="sr-only">alias</span>
                            </span>
                        </span>
                        <time
                            v-if="rowTime"
                            class="list-time shrink-0 text-[0.6875rem] tabular-nums"
                            :class="
                                awaitingInfo
                                    ? 'font-medium text-warning-soft-foreground'
                                    : unread > 0
                                      ? 'font-semibold text-primary'
                                      : 'text-muted-foreground'
                            "
                            :datetime="rowTime"
                            :title="
                                awaitingInfo
                                    ? awaitingTitle
                                    : queuedSince
                                      ? `En «Pendientes» desde ${formatDateTime(queuedSince)}`
                                      : formatDateTime(rowTime)
                            "
                            :data-inbox-awaiting-time="
                                awaitingInfo || undefined
                            "
                            >{{ listTime(rowTime) }}</time
                        >
                    </span>

                    <!-- Latest message, waiting dot and unread counter. -->
                    <span class="flex items-center gap-1.5">
                        <span
                            class="flex min-w-0 flex-1 items-center gap-1 truncate text-[0.8125rem] leading-5"
                            :class="
                                unread > 0
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            <template v-if="conversation.last_message">
                                <MessageStatusIndicator
                                    v-if="
                                        conversation.last_message.direction ===
                                        'outbound'
                                    "
                                    :status="conversation.last_message.status"
                                    :error-code="
                                        conversation.last_message.error_code
                                    "
                                />
                                <Bot
                                    v-if="conversation.last_message.automatic"
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{ preview }}</span>
                            </template>
                            <span v-else class="truncate">{{
                                contact.phone_e164
                                    ? formatPhone(contact.phone_e164)
                                    : 'Sin mensajes'
                            }}</span>
                        </span>
                        <!-- The customer wrote last and waits. -->
                        <span
                            v-if="conversation.awaiting_reply && unread === 0"
                            class="inline-flex shrink-0 items-center gap-1 text-[0.6875rem] leading-4 font-medium text-warning-soft-foreground"
                            aria-hidden="true"
                            data-inbox-awaiting-chip
                        >
                            <span class="size-1.5 rounded-full bg-warning" />
                            Espera
                        </span>
                        <!-- Bumps and rolls when a message arrives. -->
                        <CountBadge
                            v-if="unread > 0"
                            :value="unread"
                            tone="primary"
                        />
                    </span>

                    <!-- State, request and labels on one line. -->
                    <span
                        v-if="
                            conversation.sla ||
                            pending ||
                            showOperational ||
                            conversation.status === 'closed' ||
                            showReadiness ||
                            conversation.category ||
                            visibleTags.length > 0
                        "
                        class="mt-1 flex min-w-0 items-center gap-x-2.5 overflow-hidden text-[0.6875rem] leading-4 whitespace-nowrap text-muted-foreground"
                        :class="
                            (canAccept || canConfirm) &&
                            !operationalLine &&
                            'pr-[6.25rem]'
                        "
                    >
                        <!-- Response objective (SLA) of the request in course. -->
                        <SlaBadge
                            v-if="conversation.sla"
                            :sla="conversation.sla"
                            variant="compact"
                            class="shrink-0"
                        />
                        <!-- Waiting for the customer's information: its own state. -->
                        <span
                            v-if="awaitingInfo"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full bg-warning-soft px-1.5 py-px font-semibold text-warning-soft-foreground ring-1 ring-warning/35"
                            :title="awaitingTitle"
                            data-inbox-awaiting-info
                        >
                            <FileClock
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            Esperando información
                        </span>
                        <!-- Pending: text and icon, never color alone. -->
                        <span
                            v-else-if="pending"
                            class="inline-flex shrink-0 items-center gap-1 font-semibold text-warning-soft-foreground"
                            data-inbox-pending
                        >
                            <Hourglass
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            {{ conversation.status_label }}
                        </span>
                        <CompletedBadge
                            v-else-if="completed"
                            :at="conversation.completed_at"
                            :outcome="conversation.outcome"
                            class="shrink-0"
                        />
                        <span
                            v-else-if="
                                showOperational ||
                                conversation.status === 'closed'
                            "
                            class="inline-flex shrink-0 items-center gap-1"
                            :class="
                                conversation.status === 'open' &&
                                'text-foreground/80'
                            "
                            data-inbox-status
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="statusDot"
                                aria-hidden="true"
                            />
                            {{ conversation.status_label }}
                        </span>
                        <span
                            v-if="showReadiness && readiness"
                            class="inline-flex shrink-0 items-center gap-1"
                            :class="
                                readiness.value === 'needs_info'
                                    ? 'text-info-soft-foreground'
                                    : 'text-success-soft-foreground'
                            "
                            :data-readiness="readiness.value"
                        >
                            <component
                                :is="
                                    readiness.value === 'needs_info'
                                        ? CircleHelp
                                        : CircleCheck
                                "
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            {{ readiness.label }}
                        </span>
                        <span
                            v-if="conversation.category"
                            class="inline-flex max-w-36 min-w-0 shrink items-center gap-1"
                            :class="
                                conversation.category.is_active === false &&
                                'line-through'
                            "
                            :title="`Categoría: ${conversation.category.name}`"
                            data-category
                        >
                            <span
                                class="size-2 shrink-0 rounded-[3px]"
                                v-bind="dotOf(conversation.category.color)"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                conversation.category.name
                            }}</span>
                        </span>
                        <span
                            v-if="showOperational && conversation.department"
                            class="inline-flex max-w-36 min-w-0 shrink items-center gap-1 rounded-full px-1.5 py-px font-medium text-foreground/85"
                            v-bind="
                                colorPresentations(
                                    conversation.department.color,
                                ).soft
                            "
                            :class="
                                !conversation.department.is_active &&
                                'line-through'
                            "
                            :title="`Departamento: ${conversation.department.name}`"
                            data-inbox-department
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                v-bind="dotOf(conversation.department.color)"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                conversation.department.name
                            }}</span>
                        </span>
                        <span
                            v-for="tag in visibleTags"
                            :key="tag.id"
                            class="inline-flex max-w-28 min-w-0 shrink items-center gap-1"
                            :class="!tag.is_active && 'line-through'"
                            :title="`Etiqueta: ${tag.name}`"
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                v-bind="dotOf(tag.color)"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ tag.name }}</span>
                        </span>
                        <span
                            v-if="hiddenTags > 0"
                            class="shrink-0 tabular-nums"
                            :title="`${hiddenTags} etiquetas más`"
                            >+{{ hiddenTags }}</span
                        >
                    </span>

                    <!-- Who attends it and where it came in (supervision). -->
                    <span
                        v-if="operationalLine"
                        class="mt-0.5 flex min-w-0 items-center gap-1 overflow-hidden text-[0.6875rem] leading-4 whitespace-nowrap text-muted-foreground"
                        :class="[
                            (canAccept || canConfirm) &&
                                'min-h-7 pr-[6.25rem] pointer-coarse:min-h-10',
                            conversation.connection.state !== 'active' &&
                                'italic',
                        ]"
                    >
                        <span
                            v-if="showAssignee && conversation.assignee"
                            class="inline-flex max-w-[45%] min-w-0 shrink-0 items-center gap-1"
                            :class="(showOperational || showChannel) && 'mr-1'"
                            :title="`Responsable: ${conversation.assignee.name}`"
                            data-inbox-assignee
                        >
                            <img
                                v-if="userAvatarUrl(conversation.assignee.id)"
                                :src="userAvatarUrl(conversation.assignee.id)!"
                                alt=""
                                loading="lazy"
                                class="size-3.5 shrink-0 rounded-full object-cover"
                                aria-hidden="true"
                            />
                            <UserRound
                                v-else
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{
                                conversation.assignee.name
                            }}</span>
                        </span>
                        <span
                            v-if="showChannel"
                            class="shrink-0 font-medium text-foreground/70"
                            data-inbox-channel
                            >{{ conversation.connection.channel_label }}</span
                        >
                        <template v-if="showOperational">
                            <span
                                v-if="showChannel"
                                class="shrink-0"
                                aria-hidden="true"
                                >·</span
                            >
                            <span class="truncate" :title="connectionTitle">{{
                                conversation.connection.name
                            }}</span>
                            <span
                                v-if="connectionPhone"
                                class="shrink-0 tabular-nums"
                                >· {{ connectionPhone }}</span
                            >
                        </template>
                    </span>
                    <span
                        v-else-if="canAccept || canConfirm"
                        class="block min-h-7 pointer-coarse:min-h-10"
                        aria-hidden="true"
                    />
                </span>
            </button>

            <!-- Hover quick actions over the time. -->
            <div
                class="list-quick absolute top-1 right-1.5 flex items-center gap-px rounded-md border bg-popover p-px shadow-(--shadow-control) pointer-coarse:border-transparent pointer-coarse:bg-transparent pointer-coarse:shadow-none"
            >
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="size-6 rounded text-muted-foreground hover:text-foreground pointer-coarse:hidden [&_svg]:size-3.5"
                    :aria-label="`Vista previa de la conversación con ${displayName}`"
                    title="Vista previa sin marcar como leída"
                    data-inbox-quick-preview
                    @click="emit('preview', conversation)"
                >
                    <Eye aria-hidden="true" />
                </Button>
                <Button
                    v-if="unread > 0"
                    variant="ghost"
                    size="icon-sm"
                    class="size-6 rounded text-muted-foreground hover:text-foreground pointer-coarse:hidden [&_svg]:size-3.5"
                    :aria-label="`Marcar como leída la conversación con ${displayName}`"
                    title="Marcar como leída"
                    data-inbox-quick-read
                    @click="emit('markRead', conversation.id)"
                >
                    <MailOpen aria-hidden="true" />
                </Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="size-6 rounded text-muted-foreground hover:text-foreground pointer-coarse:size-10 [&_svg]:size-3.5"
                            :aria-label="`Acciones de la conversación con ${displayName}`"
                            data-inbox-row-menu
                        >
                            <EllipsisVertical aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem
                            @select="emit('select', conversation.id)"
                        >
                            <MessageSquareText aria-hidden="true" />
                            Abrir
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            data-inbox-preview
                            @select="emit('preview', conversation)"
                        >
                            <Eye aria-hidden="true" />
                            Vista previa
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="unread > 0"
                            @select="emit('markRead', conversation.id)"
                        >
                            <MailOpen aria-hidden="true" />
                            Marcar como leída
                        </DropdownMenuItem>
                        <template
                            v-if="conversation.can.confirm_ready && readiness"
                        >
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-if="readiness.value === 'needs_info'"
                                class="font-medium text-success focus:bg-success-soft focus:text-success-soft-foreground [&_svg]:text-success!"
                                data-readiness-confirm
                                @select="
                                    queueActions.readiness(
                                        conversation,
                                        'ready',
                                    )
                                "
                            >
                                <CircleCheck aria-hidden="true" />
                                Confirmar solicitud completa
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-else
                                data-readiness-revert
                                @select="
                                    queueActions.readiness(
                                        conversation,
                                        'needs_info',
                                    )
                                "
                            >
                                <CircleHelp aria-hidden="true" />
                                Marcar que necesita información
                            </DropdownMenuItem>
                        </template>
                        <template v-if="conversation.can.tag || canEditAlias">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-if="conversation.can.tag"
                                @select="emit('manageTags', conversation)"
                            >
                                <Tags aria-hidden="true" />
                                Administrar etiquetas
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="canEditAlias"
                                @select="emit('editAlias', conversation)"
                            >
                                <UserPen aria-hidden="true" />
                                Asignar alias personal
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <span v-if="canAccept" class="absolute right-2.5 bottom-2">
                <Button
                    size="sm"
                    class="h-7 gap-1 rounded-md px-2.5 text-xs pointer-coarse:h-10 [&_svg]:size-3.5"
                    :disabled="acceptingId !== null || acceptBlocked"
                    :aria-label="
                        acceptBlocked
                            ? `Aceptar la conversación de ${displayName}: no disponible. ${acceptBlockedReason ?? ''}`
                            : `Aceptar la conversación de ${displayName}`
                    "
                    :aria-describedby="
                        acceptance.blocked &&
                        acceptance.code !== 'pending_attention'
                            ? acceptanceId
                            : undefined
                    "
                    :title="
                        acceptBlocked
                            ? (acceptBlockedReason ?? undefined)
                            : 'Hacerte responsable de esta conversación'
                    "
                    data-inbox-accept
                    @click="emit('accept', conversation.id)"
                    :loading="accepting"
                >
                    <Hand aria-hidden="true" />
                    Aceptar
                </Button>
            </span>
            <!-- «Completa»: the waiting request is confirmed complete from the row. -->
            <span v-if="canConfirm" class="absolute right-2.5 bottom-2">
                <button
                    type="button"
                    class="inline-flex h-7 items-center gap-1 rounded-md bg-success px-2.5 text-xs font-semibold text-background shadow-(--shadow-control) transition-[box-shadow,filter,scale] duration-(--duration-base) outline-none hover:shadow-(--shadow-lift) hover:brightness-110 focus-visible:ring-[3px] focus-visible:ring-success/45 active:scale-95 pointer-coarse:h-10 [&_svg]:size-3.5"
                    :aria-label="`Confirmar completa la solicitud de ${displayName}`"
                    title="El cliente ya envió lo que faltaba: la solicitud regresa a «Pendientes» como nueva"
                    data-inbox-confirm-ready
                    @click="queueActions.readiness(conversation, 'ready')"
                >
                    <CircleCheck aria-hidden="true" />
                    Completa
                </button>
            </span>
        </div>
        <!-- Why it cannot be taken yet, in text (describes «Aceptar»). -->
        <AcceptanceNotice
            v-if="pending && !awaitingInfo"
            :id="acceptanceId"
            :conversation="conversation"
            compact
            class="relative -mt-0.5 pr-3 pb-2.5 pl-[3.5rem]"
        />
    </div>
</template>
