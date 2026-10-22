<script setup lang="ts">
import { Bot, CircleDot, Hourglass, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import MessageStatusIndicator from '@/components/conversations/MessageStatusIndicator.vue';
import {
    listTime,
    previewText,
} from '@/components/conversations/messageFormat';
import DepartmentTag from '@/components/DepartmentTag.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { useInitials } from '@/composables/useInitials';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type { ConversationSummary } from '@/types';

/**
 * One row of the inbox list. Pending conversations (waiting in a queue) are
 * set apart from the ones someone attends, and a dot marks those where the
 * customer wrote last and is still waiting for an answer.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    selected: boolean;
    /** Unread counter to show (0 while the chat is open on screen). */
    unread: number;
    /** Personal inboxes do not repeat the responsible agent (it is the viewer). */
    showAssignee: boolean;
}>();

const emit = defineEmits<{
    select: [id: number];
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

    return parts.join(', ');
});
</script>

<template>
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
                    class="truncate text-sm"
                    :class="unread > 0 ? 'font-semibold' : 'font-medium'"
                    >{{ conversation.contact.name }}</span
                >
                <time
                    v-if="conversation.last_message_at"
                    class="shrink-0 text-[11px]"
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
                    class="flex min-w-0 flex-1 items-center gap-1 truncate text-xs"
                    :class="
                        unread > 0 ? 'text-foreground' : 'text-muted-foreground'
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
                            class="size-3 shrink-0"
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
                    class="min-w-5 shrink-0 rounded-full bg-primary px-1.5 text-center text-[11px] leading-5 font-semibold text-primary-foreground tabular-nums"
                    aria-hidden="true"
                    >{{ unread > 99 ? '99+' : unread }}</span
                >
            </span>

            <span class="mt-1.5 flex flex-wrap items-center gap-1">
                <ConversationStatusBadge
                    v-if="conversation.status !== 'open'"
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
                    class="inline-flex max-w-32 items-center gap-1 truncate text-[11px] text-muted-foreground"
                >
                    <UserRound class="size-3 shrink-0" aria-hidden="true" />
                    {{ conversation.assignee.name }}
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
                    class="text-[11px] text-muted-foreground"
                    >+{{ hiddenTags }}</span
                >
            </span>
        </span>
    </button>
</template>
