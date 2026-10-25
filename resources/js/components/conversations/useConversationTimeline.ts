import { useHttp } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { STATUS_RANK } from '@/components/conversations/messageFormat';
import { timeline as timelineRoute } from '@/routes/conversations';
import type {
    ConversationEvent,
    ConversationMessage,
    ConversationNote,
    ConversationTimelinePage,
    MessageStatusValue,
} from '@/types';

export type TimelineItem =
    | {
          kind: 'message';
          key: string;
          at: string;
          id: number;
          message: ConversationMessage;
      }
    | {
          kind: 'note';
          key: string;
          at: string;
          id: number;
          note: ConversationNote;
      }
    | {
          kind: 'event';
          key: string;
          at: string;
          id: number;
          event: ConversationEvent;
      };

const KIND_ORDER = { event: 0, message: 1, note: 2 } as const;

/**
 * The moment a message was written, the same key the server orders by: a
 * customer message by the time the customer sent it (Meta's timestamp, which
 * the resource already falls back to the stored time), a reply by the time
 * it was stored. Webhooks are processed in parallel, so a customer message's
 * id says when it arrived, not when it was written.
 */
export function messageWrittenAt(message: ConversationMessage): string {
    return message.direction === 'inbound'
        ? (message.sent_at ?? message.created_at ?? '')
        : (message.created_at ?? message.sent_at ?? '');
}

function timeOf(at: string): number {
    const time = Date.parse(at);

    return Number.isNaN(time) ? 0 : time;
}

/*
 * Inside one second (the resolution of updated_at) and without a delivery
 * attempt, STATUS_RANK (shared with the inbox list, messageFormat.ts) orders
 * the states.
 */

/**
 * Order of the delivery states inside one delivery attempt. Each claim of
 * the job counts an attempt as it moves the message to sending, so within
 * the same attempt a queued state can only be the job putting it back after
 * a transient error (or a retry after a failure, which the next sending,
 * one attempt later, supersedes). A failure still wins a tie.
 */
const ATTEMPT_RANK: Record<MessageStatusValue, number> = {
    received: 0,
    sending: 1,
    queued: 2,
    accepted: 2,
    sent: 3,
    delivered: 4,
    read: 5,
    failed: 6,
};

/**
 * Whether a copy of a message read from the server may replace the one
 * shown. Requests run in parallel (signals, refreshes, reloads, the send
 * response), so the last response to arrive is not always the newest state:
 * the newer updated_at wins; inside the same second the higher manual
 * retry count wins (a retry requeues a failed message without a new
 * attempt), then the higher delivery attempt, and then the state rank. A copy without a version replaces
 * the shown one, as before.
 */
export function isNewerMessageState(
    current: ConversationMessage,
    incoming: ConversationMessage,
): boolean {
    const currentAt = Date.parse(current.updated_at ?? '');
    const incomingAt = Date.parse(incoming.updated_at ?? '');

    if (Number.isNaN(currentAt) || Number.isNaN(incomingAt)) {
        return true;
    }

    if (incomingAt !== currentAt) {
        return incomingAt > currentAt;
    }

    const currentRetries = current.delivery_retries;
    const incomingRetries = incoming.delivery_retries;

    if (
        typeof currentRetries === 'number' &&
        typeof incomingRetries === 'number' &&
        incomingRetries !== currentRetries
    ) {
        return incomingRetries > currentRetries;
    }

    const currentAttempt = current.delivery_attempts;
    const incomingAttempt = incoming.delivery_attempts;

    if (
        typeof currentAttempt !== 'number' ||
        typeof incomingAttempt !== 'number'
    ) {
        return STATUS_RANK[incoming.status] >= STATUS_RANK[current.status];
    }

    if (incomingAttempt !== currentAttempt) {
        return incomingAttempt > currentAttempt;
    }

    return ATTEMPT_RANK[incoming.status] >= ATTEMPT_RANK[current.status];
}

/**
 * Client state of a conversation's chat. The server sends the newest page
 * as a prop; older pages, new arrivals and status changes come from the
 * authorized timeline endpoint and are merged by id, so an item is never
 * shown twice whatever the order in which pages, reloads and real-time
 * signals arrive.
 */
export function useConversationTimeline(
    conversationId: Ref<number | null>,
    initial: Ref<ConversationTimelinePage | null>,
) {
    const messages = shallowRef(new Map<number, ConversationMessage>());
    const notes = shallowRef(new Map<number, ConversationNote>());
    const events = shallowRef(new Map<number, ConversationEvent>());
    const hasMore = ref(false);
    /** Upper bound of the notes and events of the next older page, when the last one was shortened. */
    const olderUntil = ref<string | null>(null);
    const loadingOlder = ref(false);
    const failed = ref(false);

    const olderHttp = useHttp<Record<string, never>, ConversationTimelinePage>(
        {},
    );
    const newerHttp = useHttp<Record<string, never>, ConversationTimelinePage>(
        {},
    );
    const refreshHttp = useHttp<
        Record<string, never>,
        ConversationTimelinePage
    >({});

    let pendingNewer = false;
    let newerRunning = false;

    function reset(): void {
        messages.value = new Map();
        notes.value = new Map();
        events.value = new Map();
        hasMore.value = false;
        olderUntil.value = null;
        failed.value = false;
    }

    function ingest(page: ConversationTimelinePage, older = false): void {
        if (page.messages.length > 0) {
            const next = new Map(messages.value);

            for (const message of page.messages) {
                const current = next.get(message.id);

                if (!current || isNewerMessageState(current, message)) {
                    next.set(message.id, message);
                }
            }

            messages.value = next;
        }

        if (page.notes.length > 0) {
            const next = new Map(notes.value);

            for (const note of page.notes) {
                next.set(note.id, note);
            }

            notes.value = next;
        }

        if (page.events.length > 0) {
            const next = new Map(events.value);

            for (const event of page.events) {
                next.set(event.id, event);
            }

            events.value = next;
        }

        if (older) {
            hasMore.value = page.has_more;
            olderUntil.value = page.until ?? null;
        }
    }

    watch(
        conversationId,
        () => {
            reset();

            if (initial.value) {
                ingest(initial.value, true);
            }
        },
        { immediate: true },
    );

    // Later reloads of the newest page (after an operation) merge in place.
    watch(initial, (page, previous) => {
        if (page && previous !== undefined && page !== previous) {
            ingest(page, messages.value.size <= page.messages.length);
        }
    });

    const maxId = (map: Map<number, unknown>): number =>
        map.size === 0 ? 0 : Math.max(...map.keys());

    const latestMessageId = computed(() => maxId(messages.value));
    const oldestMessageId = computed(() =>
        messages.value.size === 0 ? null : Math.min(...messages.value.keys()),
    );

    const items = computed<TimelineItem[]>(() => {
        const list: TimelineItem[] = [];

        for (const message of messages.value.values()) {
            list.push({
                kind: 'message',
                key: `m${message.id}`,
                at: messageWrittenAt(message),
                id: message.id,
                message,
            });
        }

        for (const note of notes.value.values()) {
            list.push({
                kind: 'note',
                key: `n${note.id}`,
                at: note.created_at ?? '',
                id: note.id,
                note,
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
            const time = timeOf(a.at) - timeOf(b.at);

            if (time !== 0) {
                return time;
            }

            return KIND_ORDER[a.kind] - KIND_ORDER[b.kind] || a.id - b.id;
        });
    });

    async function loadOlder(): Promise<number> {
        const id = conversationId.value;

        if (id === null || !hasMore.value || loadingOlder.value) {
            return 0;
        }

        const before = oldestMessageId.value;
        const until = olderUntil.value;

        if (before === null && until === null) {
            return 0;
        }

        loadingOlder.value = true;
        failed.value = false;

        try {
            const page = await olderHttp.get(
                timelineRoute.url(id, {
                    query: {
                        ...(before !== null ? { before } : {}),
                        ...(until !== null ? { until } : {}),
                    },
                }),
            );

            if (conversationId.value === id) {
                ingest(page, true);
            }

            return page.messages.length;
        } catch {
            failed.value = true;

            return 0;
        } finally {
            loadingOlder.value = false;
        }
    }

    /**
     * Fetches what arrived after the newest known items. Calls made while a
     * request is running are coalesced into one more request.
     */
    async function fetchNewer(): Promise<ConversationTimelinePage | null> {
        const id = conversationId.value;

        if (id === null) {
            return null;
        }

        if (newerRunning) {
            pendingNewer = true;

            return null;
        }

        newerRunning = true;

        try {
            const page = await newerHttp.get(
                timelineRoute.url(id, {
                    query: {
                        after_message: latestMessageId.value,
                        after_note: maxId(notes.value),
                        after_event: maxId(events.value),
                    },
                }),
            );

            if (conversationId.value === id) {
                ingest(page);

                // A stream reached the server's limit: ask again from the newest.
                if (page.has_more) {
                    pendingNewer = true;
                }
            }

            return page;
        } catch {
            return null;
        } finally {
            newerRunning = false;

            if (pendingNewer) {
                pendingNewer = false;
                void fetchNewer();
            }
        }
    }

    /** Re-reads items already shown (delivery status, edited notes). */
    async function refresh(ids: {
        messages?: number[];
        notes?: number[];
    }): Promise<void> {
        const id = conversationId.value;
        const messageIds = (ids.messages ?? []).filter((value) =>
            messages.value.has(value),
        );
        const noteIds = (ids.notes ?? []).filter((value) =>
            notes.value.has(value),
        );

        if (id === null || (messageIds.length === 0 && noteIds.length === 0)) {
            return;
        }

        try {
            const page = await refreshHttp.get(
                timelineRoute.url(id, {
                    query: { messages: messageIds, notes: noteIds },
                }),
            );

            if (conversationId.value === id) {
                ingest(page);
            }
        } catch {
            // The next signal or reload brings the current state.
        }
    }

    function upsertMessage(message: ConversationMessage): void {
        ingest({ messages: [message], notes: [], events: [], has_more: false });
    }

    function upsertNote(note: ConversationNote): void {
        ingest({ messages: [], notes: [note], events: [], has_more: false });
    }

    function findMessage(id: number): ConversationMessage | undefined {
        return messages.value.get(id);
    }

    return {
        items,
        hasMore,
        loadingOlder,
        failed,
        latestMessageId,
        loadOlder,
        fetchNewer,
        refresh,
        upsertMessage,
        upsertNote,
        findMessage,
    };
}
