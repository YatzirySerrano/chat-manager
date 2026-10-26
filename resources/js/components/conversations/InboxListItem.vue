<script setup lang="ts">
import {
    Bot,
    CircleDot,
    EllipsisVertical,
    Hand,
    Hourglass,
    MailOpen,
    MessageSquareText,
    Tags,
    UserPen,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import MessageStatusIndicator from '@/components/conversations/MessageStatusIndicator.vue';
import {
    listTime,
    previewText,
    statusLabel,
} from '@/components/conversations/messageFormat';
import DepartmentTag from '@/components/DepartmentTag.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type { ConversationSummary } from '@/types';

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
 * accept (`can.accept`) offers "Aceptar" without opening it first.
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
    }>(),
    {
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
}>();

const { getInitials } = useInitials();

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
const visibleTags = computed(() => (props.conversation.tags ?? []).slice(0, 2));
const hiddenTags = computed(
    () => (props.conversation.tags?.length ?? 0) - visibleTags.value.length,
);
const canAccept = computed(() => props.conversation.can.accept);
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
const label = computed(() => {
    // Whoever may accept it hears it first: the status is part of the prefix.
    const parts = canAccept.value
        ? [`Pendiente de aceptar: ${displayName.value}`]
        : [displayName.value, props.conversation.status_label];

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

    if (props.conversation.last_message_at) {
        parts.push(listTime(props.conversation.last_message_at));
    }

    return parts.join(', ');
});
</script>

<template>
    <div class="group/row relative">
        <button
            type="button"
            class="relative flex w-full items-start gap-3 border-l-[3px] py-3 pr-11 pl-3 text-left outline-none focus-visible:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset motion-safe:transition-colors"
            :class="[
                selected
                    ? 'border-l-primary bg-primary-soft'
                    : pending
                      ? 'border-l-warning hover:bg-accent/60'
                      : unread > 0
                        ? 'border-l-primary/40 hover:bg-accent/60'
                        : 'border-l-transparent hover:bg-accent/60',
            ]"
            :aria-current="selected ? 'true' : undefined"
            :aria-label="label"
            data-inbox-item
            @click="emit('select', conversation.id)"
        >
            <span
                class="relative flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                :class="[
                    pending
                        ? 'bg-warning/15 text-foreground'
                        : 'bg-primary-soft text-primary-soft-foreground',
                    conversation.status === 'closed' && 'opacity-70',
                ]"
                aria-hidden="true"
            >
                {{ getInitials(displayName) }}
                <Hourglass
                    v-if="pending"
                    class="absolute -right-0.5 -bottom-0.5 size-4 rounded-full bg-background p-0.5 text-warning"
                />
            </span>

            <span class="min-w-0 flex-1">
                <span class="flex items-baseline justify-between gap-2">
                    <span class="flex min-w-0 items-center gap-1.5">
                        <span
                            class="truncate text-sm font-semibold"
                            :class="
                                unread > 0
                                    ? 'text-foreground'
                                    : 'text-foreground/90'
                            "
                            :title="displayName"
                            >{{ displayName }}</span
                        >
                        <span
                            v-if="hasAlias"
                            class="inline-flex shrink-0 items-center gap-0.5 rounded border px-1 text-xs leading-4 text-muted-foreground"
                            :title="aliasTitle"
                            data-inbox-alias
                        >
                            <UserPen class="size-3" aria-hidden="true" />
                            alias
                        </span>
                    </span>
                    <time
                        v-if="conversation.last_message_at"
                        class="shrink-0 text-xs tabular-nums"
                        :class="
                            unread > 0
                                ? 'font-semibold text-primary'
                                : 'text-muted-foreground'
                        "
                        :datetime="conversation.last_message_at"
                        :title="formatDateTime(conversation.last_message_at)"
                        >{{ listTime(conversation.last_message_at) }}</time
                    >
                </span>

                <span class="mt-1 flex items-center gap-1.5">
                    <span
                        class="flex min-w-0 flex-1 items-center gap-1 truncate text-sm"
                        :class="
                            unread > 0
                                ? 'font-medium text-foreground'
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
                    <CircleDot
                        v-if="conversation.awaiting_reply && unread === 0"
                        class="size-3.5 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <span
                        v-if="unread > 0"
                        class="min-w-5 shrink-0 rounded-full bg-primary px-1.5 text-center text-xs leading-5 font-semibold text-primary-foreground tabular-nums"
                        aria-hidden="true"
                        >{{ unread > 99 ? '99+' : unread }}</span
                    >
                </span>

                <span
                    v-if="
                        pending ||
                        showOperational ||
                        conversation.status === 'closed' ||
                        visibleTags.length > 0
                    "
                    class="mt-1.5 flex flex-wrap items-center gap-1"
                >
                    <!-- Pending: text and icon on the soft warning pair
                         (≥4.5:1 in both themes), never color alone. -->
                    <span
                        v-if="pending"
                        class="inline-flex items-center gap-1 rounded-full border border-warning/50 bg-warning-soft px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-warning-soft-foreground"
                        data-inbox-pending
                    >
                        <Hourglass class="size-3 shrink-0" aria-hidden="true" />
                        {{ conversation.status_label }}
                    </span>
                    <ConversationStatusBadge
                        v-else-if="
                            showOperational || conversation.status === 'closed'
                        "
                        :status="conversation.status"
                        :label="conversation.status_label"
                    />
                    <DepartmentTag
                        v-if="showOperational && conversation.department"
                        :name="conversation.department.name"
                        :color="conversation.department.color"
                        :muted="!conversation.department.is_active"
                        data-inbox-department
                    />
                    <span
                        v-if="showAssignee && conversation.assignee"
                        class="inline-flex max-w-36 min-w-0 items-center gap-1 text-xs text-muted-foreground"
                        :title="`Responsable: ${conversation.assignee.name}`"
                    >
                        <UserRound
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{
                            conversation.assignee.name
                        }}</span>
                    </span>
                    <TagBadge
                        v-for="tag in visibleTags"
                        :key="tag.id"
                        :name="tag.name"
                        :color="tag.color"
                        :deleted="!tag.is_active"
                    />
                    <span
                        v-if="hiddenTags > 0"
                        class="rounded-sm bg-surface-sunken px-1 text-xs text-muted-foreground tabular-nums"
                        :title="`${hiddenTags} etiquetas más`"
                        >+{{ hiddenTags }}</span
                    >
                </span>

                <span
                    v-if="showOperational || showChannel"
                    class="mt-1 flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
                    :class="[
                        canAccept && 'min-h-8 pr-24 pointer-coarse:min-h-10',
                        conversation.connection.state !== 'active' && 'italic',
                    ]"
                    :title="connectionTitle"
                >
                    <ChannelIcon
                        :channel="conversation.connection.channel"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span
                        v-if="showChannel"
                        class="shrink-0 rounded border px-1 leading-4 text-foreground"
                        data-inbox-channel
                        >{{ conversation.connection.channel_label }}</span
                    >
                    <template v-if="showOperational">
                        <span class="truncate">{{
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
                    v-else-if="canAccept"
                    class="block min-h-8 pointer-coarse:min-h-10"
                    aria-hidden="true"
                />
            </span>
        </button>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    class="absolute top-2 right-1.5 text-muted-foreground opacity-0 group-focus-within/row:opacity-100 group-hover/row:opacity-100 hover:text-foreground focus-visible:opacity-100 data-[state=open]:opacity-100 motion-safe:transition-opacity pointer-coarse:size-10 pointer-coarse:opacity-100"
                    :aria-label="`Acciones de la conversación con ${displayName}`"
                    data-inbox-row-menu
                >
                    <EllipsisVertical aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuItem @select="emit('select', conversation.id)">
                    <MessageSquareText aria-hidden="true" />
                    Abrir
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="unread > 0"
                    @select="emit('markRead', conversation.id)"
                >
                    <MailOpen aria-hidden="true" />
                    Marcar como leída
                </DropdownMenuItem>
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

        <span v-if="canAccept" class="absolute right-3 bottom-3">
            <Button
                size="sm"
                variant="outline"
                class="bg-background pointer-coarse:h-10"
                :disabled="acceptingId !== null"
                :aria-label="`Aceptar la conversación de ${displayName}`"
                title="Hacerte responsable de esta conversación"
                data-inbox-accept
                @click="emit('accept', conversation.id)"
            >
                <Spinner v-if="accepting" />
                <Hand v-else aria-hidden="true" />
                Aceptar
            </Button>
        </span>
    </div>
</template>
