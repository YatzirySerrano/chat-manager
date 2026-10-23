<script setup lang="ts">
import {
    Bot,
    CircleDot,
    Hand,
    Hourglass,
    MessageCircle,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import MessageStatusIndicator from '@/components/conversations/MessageStatusIndicator.vue';
import {
    listTime,
    previewText,
} from '@/components/conversations/messageFormat';
import DepartmentTag from '@/components/DepartmentTag.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type { ConversationSummary } from '@/types';

/**
 * One row of the inbox list. Pending conversations (waiting in a queue) are
 * set apart from the ones someone attends, and a dot marks those where the
 * customer wrote last and is still waiting for an answer. Every row tells
 * its status, department, responsible agent and the connection (channel,
 * name and number) the customer wrote to. A pending row the server lets the
 * viewer accept (`can.accept`) offers "Aceptar" without opening it first.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    selected: boolean;
    /** Unread counter to show (0 while the chat is open on screen). */
    unread: number;
    /** Personal inboxes do not repeat the responsible agent (it is the viewer). */
    showAssignee: boolean;
    /** The viewer is not available: accepting is disabled, as in the chat. */
    unavailable: boolean;
    /** Conversation being accepted from the list, if any (one at a time). */
    acceptingId: number | null;
}>();

const emit = defineEmits<{
    select: [id: number];
    accept: [id: number];
}>();

const { getInitials } = useInitials();

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
const acceptHint = computed(() =>
    props.unavailable
        ? 'Cambia tu disponibilidad a Disponible para aceptar conversaciones.'
        : 'Hacerte responsable de esta conversación',
);
const acceptHintId = computed(
    () => `inbox-accept-hint-${props.conversation.id}`,
);
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
    const parts = [
        props.conversation.contact.name,
        props.conversation.status_label,
    ];

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

    if (props.conversation.department) {
        parts.push(`departamento ${props.conversation.department.name}`);
    }

    if (props.showAssignee && props.conversation.assignee) {
        parts.push(`responsable ${props.conversation.assignee.name}`);
    }

    parts.push(`recibida en ${connectionTitle.value}`);

    return parts.join(', ');
});
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="group relative flex w-full items-start gap-3 border-l-[3px] px-3 py-3 text-left transition-colors outline-none focus-visible:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            :class="[
                selected
                    ? 'border-l-primary bg-accent/80'
                    : pending
                      ? 'border-l-warning hover:bg-accent/50'
                      : 'border-l-transparent hover:bg-accent/50',
                conversation.status === 'closed' && 'opacity-75',
            ]"
            :aria-current="selected ? 'true' : undefined"
            :aria-label="label"
            data-inbox-item
            @click="emit('select', conversation.id)"
        >
            <span
                class="relative flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                :class="
                    pending
                        ? 'bg-warning/15 text-warning'
                        : 'bg-primary-soft text-primary-soft-foreground'
                "
                aria-hidden="true"
            >
                {{ getInitials(conversation.contact.name) }}
                <Hourglass
                    v-if="pending"
                    class="absolute -right-0.5 -bottom-0.5 size-4 rounded-full bg-background p-0.5 text-warning"
                />
            </span>

            <span class="min-w-0 flex-1">
                <span class="flex items-baseline justify-between gap-2">
                    <span
                        class="truncate text-[15px] lg:text-sm"
                        :class="unread > 0 ? 'font-semibold' : 'font-medium'"
                        :title="conversation.contact.name"
                        >{{ conversation.contact.name }}</span
                    >
                    <time
                        v-if="conversation.last_message_at"
                        class="shrink-0 text-xs"
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

                <span class="mt-0.5 flex items-center gap-1.5">
                    <span
                        class="flex min-w-0 flex-1 items-center gap-1 truncate text-sm"
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
                            />
                            <Bot
                                v-if="conversation.last_message.automatic"
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ preview }}</span>
                        </template>
                        <span v-else class="truncate">{{
                            conversation.contact.phone_e164
                                ? formatPhone(conversation.contact.phone_e164)
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

                <span class="mt-1.5 flex flex-wrap items-center gap-1">
                    <ConversationStatusBadge
                        :status="conversation.status"
                        :label="conversation.status_label"
                    />
                    <DepartmentTag
                        v-if="conversation.department"
                        :name="conversation.department.name"
                        :color="conversation.department.color"
                        :muted="!conversation.department.is_active"
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
                        class="text-xs text-muted-foreground"
                        :title="`${hiddenTags} etiquetas más`"
                        >+{{ hiddenTags }}</span
                    >
                </span>

                <span
                    class="mt-1 flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
                    :class="[
                        canAccept && 'min-h-8 pr-24 pointer-coarse:min-h-10',
                        conversation.connection.state !== 'active' &&
                            'opacity-70',
                    ]"
                    :title="connectionTitle"
                >
                    <MessageCircle
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="sr-only"
                        >{{ conversation.connection.channel_label }}:</span
                    >
                    <span class="truncate">{{
                        conversation.connection.name
                    }}</span>
                    <span v-if="connectionPhone" class="shrink-0 tabular-nums"
                        >· {{ connectionPhone }}</span
                    >
                </span>
            </span>
        </button>

        <span
            v-if="canAccept"
            class="absolute right-3 bottom-3"
            :title="acceptHint"
        >
            <!-- Unavailable: aria-disabled keeps it focusable, so screen
                 readers and touch users get the reason (aria-describedby). -->
            <Button
                size="sm"
                variant="outline"
                class="bg-background aria-disabled:cursor-not-allowed aria-disabled:opacity-50 pointer-coarse:h-10"
                :disabled="acceptingId !== null"
                :aria-disabled="unavailable || undefined"
                :aria-label="`Aceptar la conversación de ${conversation.contact.name}`"
                :aria-describedby="unavailable ? acceptHintId : undefined"
                data-inbox-accept
                @click="unavailable || emit('accept', conversation.id)"
            >
                <Spinner v-if="accepting" />
                <Hand v-else aria-hidden="true" />
                Aceptar
            </Button>
            <span v-if="unavailable" :id="acceptHintId" class="sr-only">{{
                acceptHint
            }}</span>
        </span>
    </div>
</template>
