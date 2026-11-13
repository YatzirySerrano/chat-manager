<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import {
    Archive,
    ArrowDown,
    ArrowLeft,
    BellOff,
    Info,
    Palette,
    Upload,
} from '@lucide/vue';
import {
    useDocumentVisibility,
    useEventListener,
    useIntervalFn,
    useWindowFocus,
} from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    provide,
    ref,
    watch,
} from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChatAppearanceDialog from '@/components/personalization/ChatAppearanceDialog.vue';
import MessageBubble from '@/components/team-chat/MessageBubble.vue';
import MessageComposer from '@/components/team-chat/MessageComposer.vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import {
    continuesBlock,
    dayKey,
    dayLabel,
    isPdf,
    viewable,
} from '@/components/team-chat/teamChatFormat';
import TeamMediaViewer from '@/components/team-chat/TeamMediaViewer.vue';
import type { ViewerItem } from '@/components/team-chat/TeamMediaViewer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useChannel } from '@/composables/useChannel';
import { useStickToBottom } from '@/composables/useStickToBottom';
import { useTeamPresence } from '@/composables/useTeamPresence';
import {
    CUSTOM_BUBBLE_CLASSES,
    CUSTOM_CHAT_COLORS,
    chatColorVariables,
} from '@/lib/chatAppearance';
import { formatDateTime, formatFileSize } from '@/lib/format';
import { onReconnect, realtimeState } from '@/lib/realtime';
import { index as teamChatIndex } from '@/routes/team-chat';
import { read as markRead } from '@/routes/team-chat/threads';
import {
    destroy as destroyMessage,
    index as messagesIndex,
    update as updateMessage,
} from '@/routes/team-chat/threads/messages';
import {
    destroy as removeReaction,
    store as addReaction,
} from '@/routes/team-chat/threads/messages/reactions';
import type { ViewerChatAppearance } from '@/types';
import type {
    TeamChatLimits,
    TeamMention,
    TeamMessage,
    TeamMessagePage,
    TeamThreadDetail,
} from '@/types/team-chat';

/**
 * The open internal chat: header, history (cursor pagination in both
 * directions), composer and real-time updates. Real-time signals only say
 * that something changed; the window asks the server again, which checks
 * the viewer still participates.
 *
 * The whole pane takes files: dropped on it or pasted anywhere in it they
 * become attachments of the message being written (the composer checks and
 * previews them). Images, videos and PDF of the messages on screen open in
 * one viewer, one after another.
 */
const props = defineProps<{
    thread: TeamThreadDetail;
    page: TeamMessagePage;
    around: number | null;
    limits: TeamChatLimits;
}>();

const emit = defineEmits<{
    openSettings: [];
    /** `requestedAt`: performance.now() when the read was sent. */
    unreadChanged: [total: number, requestedAt: number];
}>();

/*
 * Background of the history: the sunken canvas with a faint dot grid tinted
 * by the palette, so bubbles read as objects resting on a surface (the same
 * canvas as the customer conversations). Not used with personal colors.
 */
const CHAT_CANVAS =
    'bg-surface-sunken bg-[radial-gradient(color-mix(in_oklch,var(--primary)_14%,transparent)_1px,transparent_1.5px)] bg-size-[20px_20px]';

/*
 * The viewer's own chat colors for this thread (PERSONALIZATION): CSS
 * variables on the history only, replaced right after saving without
 * reloading the page. Nobody else's colors ever reach this view.
 */
const appearance = ref<ViewerChatAppearance | null>(
    props.thread.appearance ?? null,
);
const appearanceOpen = ref(false);

watch(
    () => props.thread.appearance,
    (value) => (appearance.value = value ?? null),
);

const customColors = computed(() => Boolean(appearance.value?.colors));
const chatStyle = computed(() => chatColorVariables(appearance.value?.colors));

provide(CUSTOM_CHAT_COLORS, customColors);

const FALLBACK_INTERVAL_MS = 15_000;
const TYPING_TTL_MS = 5_000;

const messages = ref<TeamMessage[]>([...props.page.data]);
const hasMoreBefore = ref(props.page.has_more_before);
const hasMoreAfter = ref(props.page.has_more_after);
const loadingOlder = ref(false);
const loadingNewer = ref(false);
/** A signal arrived while loadNewer was running: load again afterwards. */
let newerRequested = false;
const scroller = ref<HTMLElement | null>(null);
const composer = ref<InstanceType<typeof MessageComposer> | null>(null);
const atBottom = ref(true);
/* Media gets its height on load: keep the end in view while it grows. */
const { follow } = useStickToBottom(scroller, 80);
const newBelow = ref(0);
const replyTo = ref<TeamMessage | null>(null);
const highlightedId = ref<number | null>(props.around);
const toDelete = ref<TeamMessage | null>(null);
const deleting = ref(false);
const savingId = ref<number | null>(null);
/* The edit the server refused: the bubble keeps its editor open with it. */
const editError = ref<{ id: number; message: string } | null>(null);
const actionError = ref<string | null>(null);
const visibility = useDocumentVisibility();
/** Read only while the user looks at the chat (visible and focused window). */
const focused = useWindowFocus();

/*
 * Where the unread messages started when the chat was opened: a line marks
 * it until the chat is opened again (it does not chase the read position).
 */
const openedReadUpTo = props.thread.me.last_read_message_id ?? 0;
const firstUnreadId =
    props.around === null
        ? (props.page.data.find(
              (message) =>
                  message.id > openedReadUpTo &&
                  !message.is_mine &&
                  !message.deleted &&
                  message.kind === 'message',
          )?.id ?? null)
        : null;
/* Nothing to mark when everything on screen is new (a chat never opened). */
const unreadMarkerId =
    firstUnreadId !== null && firstUnreadId !== props.page.data[0]?.id
        ? firstUnreadId
        : null;

const olderHttp = useHttp<Record<string, never>, TeamMessagePage>({});
const newerHttp = useHttp<Record<string, never>, TeamMessagePage>({});
const refreshHttp = useHttp<Record<string, never>, { data: TeamMessage[] }>({});
const readHttp = useHttp<
    { message_id: number },
    { last_read_message_id: number; unread_total: number }
>({ message_id: 0 });
const editHttp = useHttp<{ body: string }, { data: TeamMessage }>({ body: '' });
const deleteHttp = useHttp<Record<string, never>, { data: TeamMessage }>({});
const reactionHttp = useHttp<{ emoji: string }, { data: TeamMessage }>({
    emoji: '',
});
const typingUntil = ref<Map<number, ReturnType<typeof setTimeout>>>(new Map());
const { isOnline } = useTeamPresence();

const channelName = computed(() => `team-chat.threads.${props.thread.id}`);
const isGroup = computed(() => props.thread.type === 'group');
const partnerReadUpTo = computed(() => {
    if (props.thread.type !== 'direct') {
        return null;
    }

    return (
        props.thread.participants.find((participant) => !participant.is_me)
            ?.last_read_message_id ?? null
    );
});
const lastOwnMessageId = computed(
    () =>
        [...messages.value]
            .reverse()
            .find((message) => message.is_mine && !message.deleted)?.id ?? null,
);
const partnerOnline = computed(
    () =>
        props.thread.partner !== null &&
        !props.thread.partner.deleted &&
        isOnline(props.thread.partner.id),
);
const subtitle = computed(() => {
    if (props.thread.type === 'group') {
        const count = props.thread.participants.length;
        const online = props.thread.participants.filter(
            (participant) =>
                !participant.is_me &&
                !participant.deleted &&
                isOnline(participant.id),
        ).length;
        const people =
            count === 1 ? '1 participante' : `${count} participantes`;

        return online > 0 ? `${people}, ${online} en línea` : people;
    }

    const partner = props.thread.partner;

    if (!partner || partner.deleted) {
        return 'Cuenta eliminada';
    }

    return partnerOnline.value ? 'En línea' : 'Sin conexión';
});
const mentionable = computed<TeamMention[]>(() =>
    props.thread.type === 'group'
        ? props.thread.participants
              .filter(
                  (participant) => !participant.is_me && !participant.deleted,
              )
              .map(({ id, name }) => ({ id, name }))
        : [],
);
const typingLabel = computed(() => {
    const names = props.thread.participants
        .filter((participant) => typingUntil.value.has(participant.id))
        .map((participant) => participant.name);

    if (names.length === 0) {
        return null;
    }

    if (props.thread.type === 'direct') {
        return 'Escribiendo…';
    }

    if (names.length === 1) {
        return `${names[0]} está escribiendo…`;
    }

    return names.length === 2
        ? `${names[0]} y ${names[1]} están escribiendo…`
        : 'Varias personas están escribiendo…';
});

function stopTyping(userId: number): void {
    const timer = typingUntil.value.get(userId);

    if (timer === undefined) {
        return;
    }

    clearTimeout(timer);
    const next = new Map(typingUntil.value);
    next.delete(userId);
    typingUntil.value = next;
}

/** Someone else is writing: shown for a few seconds unless renewed. */
function startTyping(userId: number): void {
    const participant = props.thread.participants.find(
        (candidate) => candidate.id === userId,
    );

    if (!participant || participant.is_me) {
        return;
    }

    const previous = typingUntil.value.get(userId);

    if (previous !== undefined) {
        clearTimeout(previous);
    }

    const next = new Map(typingUntil.value);
    next.set(
        userId,
        setTimeout(() => stopTyping(userId), TYPING_TTL_MS),
    );
    typingUntil.value = next;
}

/* ------------------------------------------------------- history layout */

type Row = {
    message: TeamMessage;
    /** First / last message of a block of the same person. */
    first: boolean;
    last: boolean;
    showAuthor: boolean;
    unreadStart: boolean;
    receipt: 'sent' | 'read' | null;
};

type Day = { key: string; label: string; rows: Row[] };

/**
 * The history by calendar day (each day keeps its date in view while it is
 * read) and, inside a day, in blocks of consecutive messages of one person.
 */
const days = computed<Day[]>(() => {
    const list = messages.value;
    const result: Day[] = [];
    const direct = props.thread.type === 'direct';
    const partnerGone = props.thread.partner?.deleted ?? true;

    list.forEach((message, index) => {
        const previous = list[index - 1];
        const next = list[index + 1];
        const key = dayKey(message.created_at);
        const unreadStart = message.id === unreadMarkerId;
        const first = unreadStart || !continuesBlock(previous, message);
        const last =
            !next ||
            next.id === unreadMarkerId ||
            !continuesBlock(message, next);

        let day = result[result.length - 1];

        if (!day || day.key !== key) {
            day = { key, label: dayLabel(message.created_at), rows: [] };
            result.push(day);
        }

        day.rows.push({
            message,
            first,
            last,
            showAuthor: first && props.thread.type === 'group',
            unreadStart,
            receipt:
                direct && message.is_mine && !partnerGone
                    ? partnerReadUpTo.value !== null &&
                      partnerReadUpTo.value >= message.id
                        ? 'read'
                        : 'sent'
                    : null,
        });
    });

    return result;
});

/* ------------------------------------------------------------ the viewer */

const viewerOpen = ref(false);
const viewerIndex = ref(0);
const viewerItems = computed<ViewerItem[]>(() =>
    messages.value.flatMap((message) =>
        message.deleted
            ? []
            : message.attachments.filter(viewable).map((attachment) => ({
                  id: attachment.id,
                  kind: isPdf(attachment)
                      ? ('pdf' as const)
                      : attachment.kind === 'video'
                        ? ('video' as const)
                        : ('image' as const),
                  src:
                      (attachment.kind === 'video'
                          ? attachment.stream_url
                          : attachment.preview_url) ?? attachment.download_url,
                  name: attachment.name,
                  downloadUrl: attachment.download_url,
                  caption: `${message.is_mine ? 'Tú' : (message.author?.name ?? '')}, ${formatDateTime(message.created_at)}`,
              })),
    ),
);

function openViewer(attachmentId: number): void {
    const index = viewerItems.value.findIndex(
        (item) => item.id === attachmentId,
    );

    if (index >= 0) {
        viewerIndex.value = index;
        viewerOpen.value = true;
    }
}

/* ------------------------------------------------- files dropped / pasted */

const dropping = ref(false);
let dragDepth = 0;

function carriesFiles(event: DragEvent): boolean {
    return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function onDragEnter(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    event.preventDefault();
    dragDepth += 1;
    dropping.value = true;
}

function onDragOver(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = props.thread.can.post ? 'copy' : 'none';
    }
}

function onDragLeave(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    dragDepth = Math.max(0, dragDepth - 1);

    if (dragDepth === 0) {
        dropping.value = false;
    }
}

function onDrop(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    event.preventDefault();
    dragDepth = 0;
    dropping.value = false;

    if (!props.thread.can.post || !event.dataTransfer) {
        return;
    }

    /* A folder arrives as an entry without content: it is named, not sent. */
    const folders = Array.from(event.dataTransfer.items ?? []).filter(
        (item) =>
            item.kind === 'file' && item.webkitGetAsEntry?.()?.isDirectory,
    ).length;
    const files = Array.from(event.dataTransfer.files).filter(
        (file) => !(file.size === 0 && file.type === ''),
    );
    const problems =
        folders > 0
            ? [
                  'Las carpetas no se pueden adjuntar: abre la carpeta y arrastra los archivos.',
              ]
            : [];

    if (files.length > 0 || problems.length > 0) {
        composer.value?.addFiles(files, problems);
    }
}

/* A file dropped outside the chat must not replace the application. */
useEventListener(window, 'dragover', (event: DragEvent) => {
    if (carriesFiles(event)) {
        event.preventDefault();
    }
});
useEventListener(window, 'drop', (event: DragEvent) => {
    if (carriesFiles(event)) {
        event.preventDefault();
        dragDepth = 0;
        dropping.value = false;
    }
});

/*
 * Ctrl+V with the focus anywhere in the page but a text field (the composer
 * handles its own paste): a screenshot still lands in the message.
 */
useEventListener(document, 'paste', (event: ClipboardEvent) => {
    const target = event.target as HTMLElement | null;

    if (
        event.defaultPrevented ||
        !props.thread.can.post ||
        target?.closest(
            'input, textarea, [contenteditable=""], [contenteditable="true"]',
        ) ||
        document.querySelector('[role="dialog"][data-state="open"]')
    ) {
        return;
    }

    const files = Array.from(event.clipboardData?.files ?? []);

    if (files.length > 0) {
        event.preventDefault();
        composer.value?.addFiles(files);
    }
});

const dropHint = computed(
    () =>
        `Hasta ${props.limits.maxFiles} archivos por mensaje, de ${formatFileSize(
            Math.min(
                props.limits.maxKilobytes * 1024,
                props.limits.server?.max_file_bytes ?? Number.POSITIVE_INFINITY,
            ),
        )} como máximo cada uno.`,
);

/* ---------------------------------------------------------------- loading */

function merge(incoming: TeamMessage[]): void {
    const byId = new Map(
        messages.value.map((message) => [message.id, message]),
    );

    incoming.forEach((message) => byId.set(message.id, message));
    messages.value = [...byId.values()].sort(
        (first, second) => first.id - second.id,
    );
}

function onScroll(): void {
    const element = scroller.value;

    if (!element) {
        return;
    }

    atBottom.value =
        element.scrollHeight - element.scrollTop - element.clientHeight < 80;

    if (atBottom.value) {
        newBelow.value = 0;
        void markLatestRead();

        if (hasMoreAfter.value) {
            void loadNewer();
        }
    }

    if (element.scrollTop < 240 && hasMoreBefore.value) {
        void loadOlder();
    }
}

async function scrollToBottom(smooth = false): Promise<void> {
    follow();
    await nextTick();
    scroller.value?.scrollTo({
        top: scroller.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
    });
}

async function scrollToMessage(messageId: number): Promise<void> {
    follow(false);
    await nextTick();
    document
        .getElementById(`team-message-${messageId}`)
        ?.scrollIntoView({ block: 'center' });
}

/**
 * Older messages are added above what is being read without moving it: the
 * distance to the end of the history is the same before and after (the
 * browser's own scroll anchoring is paused meanwhile so both do not add up).
 */
async function loadOlder(): Promise<void> {
    const first = messages.value[0];

    if (loadingOlder.value || !first) {
        return;
    }

    loadingOlder.value = true;
    const element = scroller.value;

    try {
        const page = await olderHttp.get(
            messagesIndex.url(props.thread.id, { query: { before: first.id } }),
            {
                onHttpException: () => false,
            },
        );
        const fromEnd = element ? element.scrollHeight - element.scrollTop : 0;

        if (element) {
            element.style.overflowAnchor = 'none';
        }

        merge(page.data);
        hasMoreBefore.value = page.has_more_before;
        loadingOlder.value = false;
        await nextTick();

        if (element) {
            element.scrollTop = element.scrollHeight - fromEnd;
            element.style.overflowAnchor = '';
        }
    } catch {
        actionError.value = 'No se pudieron cargar los mensajes anteriores.';
    } finally {
        loadingOlder.value = false;
    }
}

/**
 * Fetch what arrived after the newest message shown (after a signal, a
 * reconnection, or periodically while real-time is not available).
 *
 * A request already running may have been answered before the message
 * that caused a new signal was stored, so calls made meanwhile are
 * coalesced into one more load once it finishes (never dropped).
 */
async function loadNewer(): Promise<void> {
    const last = messages.value[messages.value.length - 1];

    if (loadingNewer.value) {
        newerRequested = true;

        return;
    }

    loadingNewer.value = true;
    const wasAtBottom = atBottom.value;

    try {
        const page = await newerHttp.get(
            messagesIndex.url(props.thread.id, {
                query: { after: last?.id ?? 0 },
            }),
            {
                onHttpException: (response) => {
                    if (response.status === 404) {
                        lostAccess();
                    }

                    return false;
                },
            },
        );
        const fresh = page.data.filter(
            (message) =>
                !messages.value.some((known) => known.id === message.id),
        );
        fresh.forEach((message) => {
            if (message.author?.id) {
                stopTyping(message.author.id);
            }
        });
        merge(page.data);
        hasMoreAfter.value = page.has_more_after;

        if (wasAtBottom && !hasMoreAfter.value) {
            await scrollToBottom(true);
            void markLatestRead();
        } else {
            newBelow.value += fresh.filter(
                (message) => !message.is_mine && message.kind === 'message',
            ).length;
        }
    } catch {
        // A failed refresh is retried on the next signal or interval.
    } finally {
        loadingNewer.value = false;

        if (newerRequested) {
            newerRequested = false;
            void loadNewer();
        }
    }
}

/* The server answers at most this many messages per `ids` request. */
const REFRESH_BATCH = 50;

async function refreshMessages(ids: number[]): Promise<void> {
    for (let start = 0; start < ids.length; start += REFRESH_BATCH) {
        try {
            const response = await refreshHttp.get(
                messagesIndex.url(props.thread.id, {
                    query: { ids: ids.slice(start, start + REFRESH_BATCH) },
                }),
                {
                    onHttpException: () => false,
                },
            );
            merge(response.data);
        } catch {
            // Retried on the next signal, reconnection or interval.
            return;
        }
    }
}

/*
 * Edits, deletions and reactions only arrive as signals: after a
 * disconnection (or while real-time is unavailable) the messages already
 * shown are asked again, so a deleted message does not stay visible.
 */
function refreshLoaded(latestOnly = false): void {
    const ids = messages.value
        .filter((message) => message.kind !== 'system')
        .map((message) => message.id)
        .slice(latestOnly ? -REFRESH_BATCH : 0);

    if (ids.length > 0) {
        void refreshMessages(ids);
    }
}

/*
 * This page's URL with `around` set (or removed), keeping the chat list's
 * search, filter and size.
 */
function threadUrl(around: number | null): string {
    const query = new URLSearchParams(window.location.search);

    if (around === null) {
        query.delete('around');
    } else {
        query.set('around', String(around));
    }

    const search = query.toString();

    return `${window.location.pathname}${search === '' ? '' : `?${search}`}`;
}

let lastReadSent = props.thread.me.last_read_message_id ?? 0;

async function markLatestRead(): Promise<void> {
    const latest = messages.value[messages.value.length - 1];

    if (
        !latest ||
        latest.id <= lastReadSent ||
        visibility.value !== 'visible' ||
        !focused.value ||
        !atBottom.value ||
        hasMoreAfter.value
    ) {
        return;
    }

    const previous = lastReadSent;
    lastReadSent = latest.id;
    readHttp.message_id = latest.id;
    const requestedAt = performance.now();

    try {
        const response = await readHttp.post(markRead.url(props.thread.id), {
            onHttpException: () => false,
        });
        emit('unreadChanged', response.unread_total, requestedAt);
    } catch {
        // Not confirmed: the next look at the end of the chat sends it again.
        lastReadSent = Math.min(lastReadSent, previous);
    }
}

function lostAccess(): void {
    router.visit(teamChatIndex.url(), { replace: true });
}

function reloadThread(): void {
    router.reload({
        only: ['activeThread'],
        onHttpException: (response) => {
            if (response.status === 404 || response.status === 403) {
                lostAccess();

                return false;
            }
        },
    });
}

function onSent(message: TeamMessage): void {
    replyTo.value = null;
    merge([message]);
    lastReadSent = Math.max(lastReadSent, message.id);
    void scrollToBottom(true);
}

async function saveEdit(message: TeamMessage, body: string): Promise<void> {
    actionError.value = null;
    editError.value = null;
    savingId.value = message.id;
    editHttp.clearErrors();
    editHttp.body = body;

    try {
        const response = await editHttp.put(
            updateMessage.url({ thread: props.thread.id, message: message.id }),
            {
                onHttpException: () => false,
            },
        );
        merge([response.data]);
    } catch {
        // Kept next to the text being edited, which stays in its editor.
        editError.value = {
            id: message.id,
            message:
                editHttp.errors.body ??
                'No se pudo guardar el cambio. Inténtalo de nuevo.',
        };
    } finally {
        savingId.value = null;
    }
}

async function confirmDelete(): Promise<void> {
    const message = toDelete.value;

    if (!message) {
        return;
    }

    deleting.value = true;
    actionError.value = null;

    try {
        const response = await deleteHttp.delete(
            destroyMessage.url({
                thread: props.thread.id,
                message: message.id,
            }),
            {
                onHttpException: () => false,
            },
        );
        merge([response.data]);

        if (replyTo.value?.id === message.id) {
            replyTo.value = null;
        }

        toDelete.value = null;
    } catch {
        toDelete.value = null;
        actionError.value =
            'No se pudo eliminar el mensaje. Inténtalo de nuevo.';
    } finally {
        deleting.value = false;
    }
}

/*
 * The reaction shows at once and the server's answer replaces it; a refusal
 * puts the message back as it was.
 */
async function toggleReaction(
    message: TeamMessage,
    emoji: string,
): Promise<void> {
    actionError.value = null;
    const mine = message.reactions.some(
        (reaction) => reaction.emoji === emoji && reaction.mine,
    );
    const target = { thread: props.thread.id, message: message.id };
    const existing = message.reactions.find(
        (reaction) => reaction.emoji === emoji,
    );
    const optimistic = mine
        ? message.reactions
              .map((reaction) =>
                  reaction.emoji === emoji
                      ? {
                            ...reaction,
                            mine: false,
                            count: reaction.count - 1,
                            names: reaction.names.filter(
                                (name) => name !== 'Tú',
                            ),
                        }
                      : reaction,
              )
              .filter((reaction) => reaction.count > 0)
        : existing
          ? message.reactions.map((reaction) =>
                reaction.emoji === emoji
                    ? {
                          ...reaction,
                          mine: true,
                          count: reaction.count + 1,
                          names: [...reaction.names, 'Tú'],
                      }
                    : reaction,
            )
          : [
                ...message.reactions,
                { emoji, count: 1, mine: true, names: ['Tú'] },
            ];

    merge([{ ...message, reactions: optimistic }]);

    try {
        reactionHttp.emoji = emoji;
        const response = mine
            ? await reactionHttp.delete(removeReaction.url(target), {
                  onHttpException: () => false,
              })
            : await reactionHttp.put(addReaction.url(target), {
                  onHttpException: () => false,
              });
        merge([response.data]);
    } catch {
        void refreshMessages([message.id]);
        actionError.value = 'No se pudo guardar la reacción.';
    }
}

function reply(message: TeamMessage): void {
    replyTo.value = message;
    composer.value?.focus();
}

async function jumpTo(messageId: number): Promise<void> {
    if (messages.value.some((message) => message.id === messageId)) {
        highlightedId.value = messageId;
        await scrollToMessage(messageId);

        return;
    }

    router.visit(threadUrl(messageId), {
        preserveScroll: true,
    });
}

useChannel(channelName, {
    'message.created': () => void loadNewer(),
    'thread.changed': (payload) => {
        if (typeof payload.message_id === 'number') {
            void refreshMessages([payload.message_id]);
        } else {
            reloadThread();
            void loadNewer();
        }
    },
    typing: (payload) => {
        if (typeof payload.user_id === 'number') {
            startTyping(payload.user_id);
        }
    },
    read: (payload) => {
        const partner = props.thread.participants.find(
            (participant) => !participant.is_me,
        );

        if (props.thread.type === 'direct' && payload.user_id === partner?.id) {
            reloadThread();
        }
    },
});

const stopReconnect = onReconnect(() => {
    void loadNewer();
    refreshLoaded();
    reloadThread();
});

useIntervalFn(() => {
    if (realtimeState.value !== 'connected' && visibility.value === 'visible') {
        void loadNewer();
        // Periodically only the latest page (one request); all on reconnect.
        refreshLoaded(true);
    }
}, FALLBACK_INTERVAL_MS);

watch([visibility, focused], ([state, hasFocus]) => {
    if (state === 'visible' && hasFocus) {
        void markLatestRead();
    }
});

watch(highlightedId, (id) => {
    if (id !== null) {
        setTimeout(() => {
            if (highlightedId.value === id) {
                highlightedId.value = null;
            }
        }, 4000);
    }
});

/* The «escribiendo…» bubble appears below the last message: keep it in view. */
watch(typingLabel, (label) => {
    if (label !== null && atBottom.value && !hasMoreAfter.value) {
        void scrollToBottom();
    }
});

/* An error of an action leaves by itself; the cause stays fixable meanwhile. */
watch(actionError, (message) => {
    if (message !== null) {
        setTimeout(() => {
            if (actionError.value === message) {
                actionError.value = null;
            }
        }, 8000);
    }
});

onMounted(async () => {
    if (props.around !== null) {
        await scrollToMessage(props.around);
        onScroll();
    } else if (unreadMarkerId !== null) {
        // Open where the reading stopped, with the first new message in view.
        follow(false);
        await nextTick();
        const marker = document.getElementById('team-chat-unread-marker');
        const list = scroller.value;

        // Moved inside the history only (scrollIntoView would also move the page).
        if (marker && list) {
            list.scrollTop +=
                marker.getBoundingClientRect().top -
                list.getBoundingClientRect().top -
                48;
        }
        onScroll();
    } else {
        await scrollToBottom();
        void markLatestRead();
    }
});

onBeforeUnmount(() => {
    stopReconnect();
    typingUntil.value.forEach((timer) => clearTimeout(timer));
});
</script>

<template>
    <section
        class="relative flex h-full min-h-0 flex-col"
        :aria-label="`Chat: ${thread.title}`"
        @dragenter="onDragEnter"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
    >
        <header
            class="flex h-14 shrink-0 items-center gap-1 border-b bg-background px-1.5 sm:h-16 sm:gap-2 sm:px-4"
        >
            <Button
                variant="ghost"
                size="icon"
                class="rounded-full lg:hidden"
                as-child
                aria-label="Volver a la lista de chats"
            >
                <Link :href="teamChatIndex()" preserve-state>
                    <ArrowLeft />
                </Link>
            </Button>
            <!-- A shortcut for the pointer; the «Información» button is the control. -->
            <div
                class="flex min-w-0 flex-1 cursor-pointer items-center gap-3 rounded-xl py-1 pr-3 pl-1 transition-colors duration-(--duration-fast) hover:bg-accent/70"
                @click="emit('openSettings')"
            >
                <TeamAvatar
                    :name="thread.title"
                    :group="isGroup"
                    :deleted="thread.partner?.deleted ?? false"
                    :online="partnerOnline"
                />
                <span class="min-w-0 flex-1">
                    <h2
                        class="flex items-center gap-1.5 font-display text-[1.05rem] leading-tight font-semibold"
                    >
                        <span class="truncate">{{ thread.title }}</span>
                        <BellOff
                            v-if="thread.me.muted"
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-label="Silenciado"
                        />
                    </h2>
                    <span
                        class="block truncate text-xs"
                        :class="
                            typingLabel && thread.can.post
                                ? 'font-medium text-primary'
                                : partnerOnline
                                  ? 'text-success'
                                  : 'text-muted-foreground'
                        "
                    >
                        {{
                            typingLabel && thread.can.post
                                ? typingLabel
                                : subtitle
                        }}
                    </span>
                </span>
            </div>
            <Button
                v-if="appearance"
                variant="ghost"
                size="icon"
                class="shrink-0 rounded-full text-muted-foreground"
                title="Apariencia del chat"
                aria-label="Cambiar los colores de tu chat"
                @click="appearanceOpen = true"
            >
                <Palette aria-hidden="true" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 rounded-full text-muted-foreground"
                :aria-label="
                    isGroup
                        ? 'Información y ajustes del grupo'
                        : 'Información del chat'
                "
                :title="
                    isGroup
                        ? 'Información y ajustes del grupo'
                        : 'Información del chat'
                "
                @click="emit('openSettings')"
            >
                <Info />
            </Button>
        </header>

        <div
            v-if="thread.archived"
            class="flex shrink-0 items-center gap-2 border-b bg-warning-soft px-4 py-2 text-xs text-warning-soft-foreground"
            role="status"
        >
            <Archive class="size-3.5 shrink-0" aria-hidden="true" />
            Grupo archivado: puedes consultar su historial, pero ya no se pueden
            enviar mensajes.
        </div>

        <div class="relative min-h-0 flex-1">
            <div
                ref="scroller"
                class="relative h-full overflow-y-auto overscroll-contain pb-3"
                :class="customColors ? CUSTOM_BUBBLE_CLASSES.chat : CHAT_CANVAS"
                :style="chatStyle"
                :data-custom-colors="customColors || undefined"
                role="log"
                aria-live="polite"
                aria-relevant="additions"
                :aria-busy="loadingOlder || loadingNewer"
                @scroll.passive="onScroll"
            >
                <div
                    v-if="loadingOlder"
                    class="grid gap-2 px-3 pt-4 sm:px-5"
                    aria-hidden="true"
                >
                    <Skeleton class="h-9 w-52 rounded-2xl" />
                    <Skeleton class="h-9 w-72 max-w-[70%] rounded-2xl" />
                    <Skeleton
                        class="h-9 w-44 justify-self-end rounded-2xl bg-primary/20"
                    />
                </div>
                <div v-else-if="hasMoreBefore" class="flex justify-center pt-3">
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        @click="loadOlder"
                    >
                        Cargar mensajes anteriores
                    </Button>
                </div>
                <p
                    v-else-if="messages.length > 0"
                    class="px-4 pt-4 text-center text-xs text-muted-foreground"
                >
                    <span
                        :class="
                            customColors &&
                            'rounded-full bg-background px-2.5 py-0.5'
                        "
                        >Inicio de la conversación</span
                    >
                </p>

                <div
                    v-if="messages.length === 0"
                    class="mx-3 mt-6 grid w-fit max-w-prose gap-1 rounded-2xl border bg-card px-4 py-3 sm:mx-5"
                >
                    <p class="font-display text-base font-semibold">
                        {{
                            isGroup
                                ? 'El grupo está listo'
                                : `Empieza la conversación con ${thread.title}`
                        }}
                    </p>
                    <p class="text-sm text-pretty text-muted-foreground">
                        Aún no hay mensajes. Escribe el primero: solo
                        {{
                            isGroup
                                ? 'los participantes del grupo'
                                : 'ustedes dos'
                        }}
                        pueden leerlo.
                    </p>
                </div>

                <section
                    v-for="day in days"
                    :key="day.key"
                    :aria-label="day.label"
                >
                    <div
                        class="pointer-events-none sticky top-0 z-10 flex justify-center py-2"
                    >
                        <span
                            class="inline-block rounded-full bg-background/90 px-3 py-1 text-xs font-medium text-muted-foreground ring-1 ring-border/70 backdrop-blur-sm first-letter:uppercase"
                        >
                            {{ day.label }}
                        </span>
                    </div>
                    <template v-for="row in day.rows" :key="row.message.id">
                        <div
                            v-if="row.unreadStart"
                            id="team-chat-unread-marker"
                            class="flex scroll-mt-12 items-center gap-3 px-3 pt-3 sm:px-5"
                            role="separator"
                        >
                            <span class="h-px flex-1 bg-primary/40" />
                            <span
                                class="rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-semibold text-primary-soft-foreground"
                            >
                                Mensajes nuevos
                            </span>
                            <span class="h-px flex-1 bg-primary/40" />
                        </div>
                        <MessageBubble
                            :message="row.message"
                            :show-author="row.showAuthor"
                            :first="row.first"
                            :last="row.last"
                            :with-avatar="isGroup"
                            :highlighted="highlightedId === row.message.id"
                            :seen="
                                row.message.id === lastOwnMessageId &&
                                partnerReadUpTo !== null &&
                                partnerReadUpTo >= row.message.id
                            "
                            :receipt="row.receipt"
                            :can-reply="thread.can.post"
                            :reactions="thread.can.post ? limits.reactions : []"
                            :saving="savingId === row.message.id"
                            :edit-error="
                                editError?.id === row.message.id
                                    ? editError.message
                                    : null
                            "
                            @react="toggleReaction"
                            @reply="reply"
                            @edit="saveEdit"
                            @delete="toDelete = $event"
                            @jump="jumpTo"
                            @view="openViewer"
                        />
                    </template>
                </section>

                <div v-if="hasMoreAfter" class="flex justify-center py-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        :disabled="loadingNewer"
                        :loading="loadingNewer"
                        @click="loadNewer"
                    >
                        Cargar mensajes más recientes
                    </Button>
                </div>

                <!-- Someone is writing: the place where their message will land. -->
                <div
                    v-if="typingLabel && thread.can.post"
                    class="flex items-center gap-2 px-3 pt-2.5 sm:px-5"
                    aria-live="polite"
                    data-typing
                >
                    <span
                        class="flex h-8 items-center gap-1 rounded-[1.15rem] rounded-bl-sm border border-border/80 bg-card px-3"
                        aria-hidden="true"
                    >
                        <span
                            v-for="dot in 3"
                            :key="dot"
                            class="size-1.5 animate-bounce rounded-full bg-muted-foreground/70 motion-reduce:animate-none"
                            :style="{ animationDelay: `${(dot - 1) * 140}ms` }"
                        />
                    </span>
                    <span
                        class="truncate text-xs text-muted-foreground"
                        :class="
                            customColors &&
                            'rounded-full bg-background px-2 py-0.5'
                        "
                        >{{ typingLabel }}</span
                    >
                </div>
            </div>

            <button
                v-if="!atBottom"
                type="button"
                class="absolute right-4 bottom-3 inline-flex h-10 items-center gap-1.5 rounded-full border bg-background px-3 text-sm font-medium text-foreground shadow-elevated transition-[scale,background-color] duration-(--duration-fast) outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/45 active:scale-95"
                :class="newBelow === 0 && 'w-10 justify-center px-0'"
                :aria-label="
                    newBelow > 0
                        ? `${newBelow} ${newBelow === 1 ? 'mensaje nuevo' : 'mensajes nuevos'}, ir al final`
                        : 'Ir al final'
                "
                :title="
                    newBelow > 0 ? 'Ver los mensajes nuevos' : 'Ir al final'
                "
                @click="
                    hasMoreAfter
                        ? router.visit(threadUrl(null))
                        : scrollToBottom(true)
                "
            >
                <span
                    v-if="newBelow > 0"
                    class="grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums"
                    >{{ newBelow > 99 ? '99+' : newBelow }}</span
                >
                <span v-if="newBelow > 0">{{
                    newBelow === 1 ? 'nuevo' : 'nuevos'
                }}</span>
                <ArrowDown class="size-4" aria-hidden="true" />
            </button>
        </div>

        <p
            v-if="actionError"
            class="flex shrink-0 items-center justify-between gap-3 border-t bg-destructive-soft px-4 py-1.5 text-xs text-destructive-soft-foreground"
            role="alert"
        >
            {{ actionError }}
            <button
                type="button"
                class="shrink-0 rounded font-semibold underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-current"
                @click="actionError = null"
            >
                Cerrar
            </button>
        </p>

        <MessageComposer
            v-if="thread.can.post"
            ref="composer"
            :thread-id="thread.id"
            :limits="limits"
            :mentionable="mentionable"
            :reply-to="replyTo"
            @sent="onSent"
            @cancel-reply="replyTo = null"
        />
        <p
            v-else
            class="shrink-0 border-t bg-surface-sunken px-4 py-3 text-sm text-muted-foreground sm:px-5"
        >
            {{
                thread.post_blocked_reason ??
                'No puedes enviar mensajes en este chat.'
            }}
        </p>

        <!-- The whole pane is the drop zone. -->
        <div
            v-if="dropping"
            class="pointer-events-none absolute inset-0 z-30 grid place-items-center bg-background/80 p-4 backdrop-blur-[2px]"
            data-team-dropzone
        >
            <div
                class="grid w-full max-w-md justify-items-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center"
                :class="
                    thread.can.post
                        ? 'border-primary bg-primary-soft text-primary-soft-foreground'
                        : 'border-border-strong bg-muted text-muted-foreground'
                "
            >
                <Upload class="size-8" aria-hidden="true" />
                <p class="font-display text-lg font-semibold">
                    {{
                        thread.can.post
                            ? 'Suelta los archivos para adjuntarlos'
                            : 'Este chat ya no acepta archivos'
                    }}
                </p>
                <p class="text-sm text-pretty">
                    {{
                        thread.can.post
                            ? `Se agregan al mensaje que estás escribiendo; tú decides cuándo enviarlo. ${dropHint}`
                            : (thread.post_blocked_reason ??
                              'No puedes enviar mensajes en este chat.')
                    }}
                </p>
            </div>
        </div>

        <TeamMediaViewer
            v-model:open="viewerOpen"
            v-model:index="viewerIndex"
            :items="viewerItems"
        />

        <ConfirmDialog
            :open="toDelete !== null"
            title="¿Eliminar el mensaje?"
            description="Su texto y sus archivos se borrarán para todos los participantes y no se podrán recuperar. En el chat quedará la marca «Mensaje eliminado»."
            confirm-label="Eliminar mensaje"
            destructive
            :processing="deleting"
            @update:open="
                (value) => {
                    if (!value) toDelete = null;
                }
            "
            @confirm="confirmDelete"
        />

        <ChatAppearanceDialog
            v-if="appearance"
            v-model:open="appearanceOpen"
            :target="{ type: 'team_thread', id: thread.id }"
            :appearance="appearance"
            @saved="(value) => (appearance = value)"
        />
    </section>
</template>
