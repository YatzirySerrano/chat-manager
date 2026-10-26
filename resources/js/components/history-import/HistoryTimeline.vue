<script setup lang="ts">
import { MessagesSquare } from '@lucide/vue';
import { computed, nextTick, ref, shallowRef } from 'vue';
import { groupByCalendarDay } from '@/components/conversations/dayGroups';
import MessageBubble from '@/components/conversations/MessageBubble.vue';
import { todayKey } from '@/components/conversations/messageFormat';
import TimelineEvent from '@/components/conversations/TimelineEvent.vue';
import { messageWrittenAt } from '@/components/conversations/useConversationTimeline';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { platformTimezone } from '@/lib/format';
import { messages as messagesRoute } from '@/routes/admin/history/conversations';
import type { ConversationEvent } from '@/types';
import type {
    ArchivedTimelinePage,
    ImportedMessage,
} from '@/types/history-import';
import { errorMessage, historyRequest } from './api';

/**
 * Read-only chat of an imported conversation. It reuses the inbox's
 * bubbles and event lines (MessageBubble, TimelineEvent) without any
 * action: no reply, quote, retry or link revocation. Older pages load on
 * demand with the same cursor the inbox uses.
 */
const props = defineProps<{
    conversationId: number;
    contactName: string;
    initial: ArchivedTimelinePage;
}>();

type Item =
    | {
          kind: 'message';
          key: string;
          at: string;
          id: number;
          message: ImportedMessage;
      }
    | {
          kind: 'event';
          key: string;
          at: string;
          id: number;
          event: ConversationEvent;
      };

const messages = shallowRef(new Map<number, ImportedMessage>());
const events = shallowRef(new Map<number, ConversationEvent>());
const hasMore = ref(false);
const until = ref<string | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const loadedNotice = ref('');
const root = ref<HTMLElement | null>(null);

function ingest(page: ArchivedTimelinePage): void {
    const nextMessages = new Map(messages.value);

    for (const message of page.messages) {
        nextMessages.set(message.id, message);
    }

    messages.value = nextMessages;

    const nextEvents = new Map(events.value);

    for (const event of page.events) {
        nextEvents.set(event.id, event);
    }

    events.value = nextEvents;
    hasMore.value = page.has_more;
    until.value = page.until ?? null;
}

ingest(props.initial);

const items = computed<Item[]>(() => {
    const list: Item[] = [];

    for (const message of messages.value.values()) {
        list.push({
            kind: 'message',
            key: `m${message.id}`,
            at: messageWrittenAt(message),
            id: message.id,
            message,
        });
    }

    for (const event of events.value.values()) {
        list.push({
            kind: 'event',
            key: `e${event.id}`,
            at: event.created_at,
            id: event.id,
            event,
        });
    }

    return list.sort((a, b) => {
        const time = (Date.parse(a.at) || 0) - (Date.parse(b.at) || 0);

        return time !== 0
            ? time
            : a.kind === b.kind
              ? a.id - b.id
              : a.kind === 'event'
                ? -1
                : 1;
    });
});

const dayGroups = computed(() =>
    groupByCalendarDay(
        items.value,
        (item) => item.at,
        todayKey(),
        platformTimezone(),
    ),
);

async function loadOlder(): Promise<void> {
    if (!hasMore.value || loading.value) {
        return;
    }

    const before =
        messages.value.size > 0 ? Math.min(...messages.value.keys()) : null;
    loading.value = true;
    error.value = null;

    try {
        const page = await historyRequest<ArchivedTimelinePage>(
            'GET',
            messagesRoute.url(props.conversationId, {
                query: {
                    ...(before !== null ? { before } : {}),
                    ...(until.value !== null ? { until: until.value } : {}),
                },
            }),
        );
        /* Keep the message that was on top in place while older ones appear above. */
        const anchor = root.value?.querySelector<HTMLElement>(
            '[data-imported-message]',
        );
        const anchorTop = anchor?.getBoundingClientRect().top ?? null;
        ingest(page);
        await nextTick();

        if (anchor && anchorTop !== null) {
            window.scrollBy(0, anchor.getBoundingClientRect().top - anchorTop);
        }

        loadedNotice.value =
            page.messages.length === 1
                ? 'Se cargó 1 mensaje anterior.'
                : `Se cargaron ${page.messages.length} mensajes anteriores.`;
    } catch (failure) {
        error.value = errorMessage(failure);
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div
        ref="root"
        class="rounded-xl border bg-muted/30 px-3 py-4 sm:px-6"
        role="region"
        tabindex="0"
        :aria-label="`Historial importado de la conversación con ${contactName}`"
        data-history-timeline
    >
        <div class="mx-auto grid max-w-4xl grid-cols-1 gap-2">
            <div class="flex justify-center py-1">
                <Button
                    v-if="hasMore"
                    variant="ghost"
                    size="sm"
                    class="pointer-coarse:h-10"
                    :disabled="loading"
                    @click="loadOlder"
                >
                    <Spinner v-if="loading" />
                    Cargar mensajes anteriores
                </Button>
                <p
                    v-else-if="items.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    Inicio de la conversación
                </p>
            </div>
            <p class="sr-only" role="status">{{ loadedNotice }}</p>
            <p
                v-if="error"
                class="text-center text-xs text-destructive"
                role="alert"
            >
                {{ error }}
            </p>

            <EmptyState
                v-if="items.length === 0"
                class="border-none bg-transparent"
                :icon="MessagesSquare"
                title="Sin mensajes"
                description="Esta conversación se importó sin mensajes."
            />

            <section
                v-for="group in dayGroups"
                :key="group.day"
                class="grid grid-cols-1 gap-2 pt-0.5"
                :data-day="group.day"
            >
                <h3 class="flex justify-center py-1">
                    <span
                        class="max-w-full rounded-full border bg-background px-3 py-0.5 text-center text-xs font-medium text-muted-foreground shadow-xs"
                    >
                        {{ group.label }}
                    </span>
                </h3>
                <template v-for="item in group.items" :key="item.key">
                    <MessageBubble
                        v-if="item.kind === 'message'"
                        :message="item.message"
                        :contact-name="contactName"
                        channel-label="Whaticket"
                        :data-imported-message="item.message.id"
                    />
                    <TimelineEvent v-else :event="item.event" />
                </template>
            </section>
        </div>
    </div>
</template>
