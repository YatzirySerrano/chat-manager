import { useHttp } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { timeline as timelineRoute } from '@/routes/conversations';
import type {
    ConversationEvent,
    ConversationMessage,
    ConversationNote,
    ConversationTimelinePage,
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
                next.set(message.id, message);
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
                at: message.created_at ?? message.sent_at ?? '',
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
            if (a.kind === 'message' && b.kind === 'message') {
                return a.id - b.id;
            }

            const time = new Date(a.at).getTime() - new Date(b.at).getTime();

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
