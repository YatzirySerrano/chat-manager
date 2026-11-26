<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { MessagesSquare } from '@lucide/vue';
import {
    useDocumentVisibility,
    useElementSize,
    useIntervalFn,
    useMediaQuery,
} from '@vueuse/core';
import {
    computed,
    defineAsyncComponent,
    inject,
    nextTick,
    onMounted,
    provide,
    ref,
    watch,
} from 'vue';
import { toast } from 'vue-sonner';
import { CHAT_UNDER_HEADER, arriveClass } from '@/components/chat/bubble';
import ChatDaySeparator from '@/components/chat/ChatDaySeparator.vue';
import ChatDivider from '@/components/chat/ChatDivider.vue';
import ChatHistoryStart from '@/components/chat/ChatHistoryStart.vue';
import ChatJumpButton from '@/components/chat/ChatJumpButton.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormDialog from '@/components/FormDialog.vue';
import { Textarea } from '@/components/ui/textarea';
import MessageBubble from '@/components/conversations/MessageBubble.vue';
import { groupByCalendarDay } from '@/components/conversations/dayGroups';
import { todayKey } from '@/components/conversations/messageFormat';
import TimelineEvent from '@/components/conversations/TimelineEvent.vue';
import TimelineNote from '@/components/conversations/TimelineNote.vue';
import type { TimelineItem } from '@/components/conversations/useConversationTimeline';
import EmptyState from '@/components/EmptyState.vue';
import { CUSTOM_CHAT_COLORS } from '@/lib/chatAppearance';
import { useStickToBottom } from '@/composables/useStickToBottom';
import { MEDIA_GALLERY, viewerKind } from '@/lib/conversations/media';
import type { ViewerItem } from '@/lib/conversations/media';
import { platformTimezone } from '@/lib/format';
import {
    destroy as destroyMessageRoute,
    retry as retryRoute,
    update as updateMessageRoute,
} from '@/routes/conversations/messages';
import { revokeLink as revokeLinkRoute } from '@/routes/conversations/messages/attachments';
import type {
    ConversationEvent,
    ConversationMessage,
    ConversationSummary,
    MessageAttachment,
} from '@/types';

// Loaded the first time it is shown: it is not needed to paint the page.
const MediaViewer = defineAsyncComponent(
    () => import('@/components/conversations/MediaViewer.vue'),
);

/**
 * The scrolling history of the chat. It opens at the newest message, loads
 * older pages when scrolled to the top keeping the reading position, and
 * only follows new messages when the reader is already at the bottom;
 * otherwise a button tells how many arrived. New customer messages are
 * announced to screen readers.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    items: TimelineItem[];
    hasMore: boolean;
    loadingOlder: boolean;
    failed: boolean;
    noteMaxLength: number;
}>();

const emit = defineEmits<{
    loadOlder: [];
    refresh: [ids: { messages?: number[]; notes?: number[] }];
    quote: [message: ConversationMessage];
}>();

/* The viewer's own chat colors, set as CSS variables by ConversationChat. */
const customColors = inject(
    CUSTOM_CHAT_COLORS,
    computed(() => false),
);

const scroller = ref<HTMLElement | null>(null);
/*
 * Keys of the items that arrived while the chat was open (never the first
 * page nor an older page): only those slide in.
 */
const fresh = ref<Set<string | number>>(new Set());
const atBottom = ref(true);
const unseen = ref(0);
const announcement = ref('');

const BOTTOM_THRESHOLD = 96;

/* Media gets its height on load: keep the end in view while it grows. */
const { follow } = useStickToBottom(scroller, BOTTOM_THRESHOLD);

/*
 * While the "go to the end" button floats over the history, the history
 * reserves room below its last item, so the button never covers the last
 * message, its delivery state or its retry button.
 */
const scrollerSize = useElementSize(scroller, undefined, {
    box: 'border-box',
});

/*
 * In a history squeezed below ~88 px the button would cover most of what is
 * left of it, so it is not shown there (End still jumps to the newest
 * message from the focused history). On very short windows it is smaller.
 */
const MIN_JUMP_HEIGHT = 88;

const showJumpButton = computed(
    () =>
        (unseen.value > 0 || !atBottom.value) &&
        scrollerSize.height.value >= MIN_JUMP_HEIGHT,
);
const compactJump = useMediaQuery('(max-height: 20rem)');

/*
 * One group per calendar day of the platform time zone, keyed by the same
 * instant the timeline is ordered by (item.at), so days come out contiguous
 * and each one gets exactly one separator, also after an older page is
 * prepended or a new message arrives. "Hoy"/"Ayer" follow `today`, which
 * moves at midnight while the chat stays open.
 */
const today = ref(todayKey());

function refreshToday(): void {
    const current = todayKey();

    if (current !== today.value) {
        today.value = current;
    }
}

useIntervalFn(refreshToday, 30_000);
watch(useDocumentVisibility(), (visibility) => {
    if (visibility === 'visible') {
        refreshToday();
    }
});

/*
 * Consecutive messages of the same author, moments apart, read as one group:
 * the name shows once and only the last bubble keeps its tail. A note, an
 * event, a change of day or a silence of five minutes starts a new group.
 */
const GROUP_WINDOW_MS = 5 * 60_000;

function authorKey(message: ConversationMessage): string {
    if (message.direction === 'inbound') {
        return 'in';
    }

    return message.automatic
        ? 'out:auto'
        : message.integration
          ? `out:api:${message.integration.name}`
          : `out:${message.sender?.id ?? message.sender?.name ?? message.imported?.sender_name ?? 'team'}`;
}

/** A Whaticket operation line drawn like an event of this system. */
function importedEvent(message: ConversationMessage): ConversationEvent {
    const event = message.imported?.event;

    return {
        id: message.id,
        type: 'imported',
        label: message.body ?? '',
        action: event?.action ?? null,
        actor: event?.actor ?? null,
        from_user: null,
        to_user: null,
        from_department: null,
        to_department: null,
        note: null,
        trigger: null,
        message_id: message.id,
        reason: null,
        created_at: message.sent_at ?? message.created_at ?? '',
    };
}

function sameGroup(
    a: TimelineItem | undefined,
    b: TimelineItem | undefined,
): boolean {
    if (a?.kind !== 'message' || b?.kind !== 'message') {
        return false;
    }

    return (
        authorKey(a.message) === authorKey(b.message) &&
        Boolean(a.message.is_historical) === Boolean(b.message.is_historical) &&
        Math.abs(new Date(b.at).getTime() - new Date(a.at).getTime()) <
            GROUP_WINDOW_MS
    );
}

const dayGroups = computed(() =>
    groupByCalendarDay(
        props.items,
        (item) => item.at,
        today.value,
        platformTimezone(),
    ).map((group) => ({
        ...group,
        rows: group.items.map((item, index) => ({
            item,
            groupedWithPrevious: sameGroup(group.items[index - 1], item),
            groupedWithNext: sameGroup(item, group.items[index + 1]),
        })),
    })),
);

/*
 * Where the history brought in from the linked WhatsApp ends and what was
 * received here begins: one discreet divider under the last imported
 * message that is followed by a live one.
 */
const historyEndsAt = computed<string | number | null>(() => {
    let last: string | number | null = null;

    props.items.forEach((item, index) => {
        const next = props.items[index + 1];

        if (
            item.kind === 'message' &&
            item.message.is_historical === true &&
            next !== undefined &&
            !(next.kind === 'message' && next.message.is_historical === true)
        ) {
            last = item.key;
        }
    });

    return last;
});

/*
 * Every picture, video and PDF loaded in the history, in order: the viewer
 * moves between them. Bubbles ask for it through MEDIA_GALLERY.
 */
const gallery = computed<ViewerItem[]>(() =>
    props.items.flatMap((item) => {
        if (item.kind !== 'message' || item.message.revoked_at) {
            return [];
        }

        const message = item.message;

        return (message.attachments ?? []).flatMap((attachment) => {
            const kind = viewerKind(attachment, message);

            return kind
                ? [
                      {
                          id: attachment.id,
                          kind,
                          src: attachment.url,
                          downloadUrl: attachment.download_url,
                          name: attachment.file_name ?? 'Archivo adjunto',
                          caption: message.body,
                          author:
                              message.direction === 'inbound'
                                  ? props.conversation.contact.display_name
                                  : (message.sender?.name ??
                                    message.imported?.sender_name ??
                                    'Equipo'),
                          at: message.sent_at ?? message.created_at,
                      },
                  ]
                : [];
        });
    }),
);
const viewerOpen = ref(false);
const viewerIndex = ref(0);

provide(MEDIA_GALLERY, {
    open: (attachmentId: number): boolean => {
        const index = gallery.value.findIndex(
            (item) => item.id === attachmentId,
        );

        if (index < 0) {
            return false;
        }

        viewerIndex.value = index;
        viewerOpen.value = true;

        return true;
    },
});

function measureBottom(): void {
    const element = scroller.value;

    if (!element) {
        return;
    }

    atBottom.value =
        element.scrollHeight - element.scrollTop - element.clientHeight <
        BOTTOM_THRESHOLD;

    if (atBottom.value) {
        unseen.value = 0;
    }
}

function scrollToBottom(smooth = false): void {
    const element = scroller.value;

    follow();

    if (element) {
        element.scrollTo({
            top: element.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto',
        });
    }

    unseen.value = 0;
}

let restoringFrom: number | null = null;

/*
 * Cleared first so the same text ("Nuevo mensaje de …" twice in a row) is a
 * new change of the live region and screen readers announce it again.
 */
function announce(text: string): void {
    announcement.value = '';
    void nextTick(() => {
        announcement.value = text;
    });
}

async function loadOlder(): Promise<void> {
    const element = scroller.value;

    if (!element || !props.hasMore || props.loadingOlder) {
        return;
    }

    restoringFrom = element.scrollHeight - element.scrollTop;
    emit('loadOlder');
}

function onScroll(): void {
    measureBottom();

    if ((scroller.value?.scrollTop ?? 1) < 80) {
        void loadOlder();
    }
}

/* Keep position when older items are prepended; follow new ones at bottom. */
watch(
    () => props.items,
    async (items, previous) => {
        const element = scroller.value;
        const wasAtBottom = atBottom.value;
        /*
         * New items are those whose key was not on screen before, wherever
         * they land: the timeline is ordered by writing time, so a customer
         * message delivered late is inserted above a reply already shown and
         * still has to be announced and counted. Items placed before the
         * previous first one are an older page being prepended, not news.
         */
        const previousKeys = new Set(previous?.map((item) => item.key) ?? []);
        const previousFirst = previous?.[0]?.key;
        const firstKeptIndex = items.findIndex(
            (item) => item.key === previousFirst,
        );
        const appended =
            previous !== undefined && previous.length > 0
                ? items
                      .slice(firstKeptIndex + 1)
                      .filter((item) => !previousKeys.has(item.key))
                : [];

        if (appended.length > 0 && appended.length <= 3) {
            fresh.value = new Set(appended.map((item) => item.key));
        }

        await nextTick();

        if (!element) {
            return;
        }

        if (restoringFrom !== null && !props.loadingOlder) {
            element.scrollTop = element.scrollHeight - restoringFrom;
            restoringFrom = null;

            return;
        }

        if (previous === undefined || previous.length === 0) {
            scrollToBottom();

            return;
        }

        if (appended.length === 0) {
            return;
        }

        const inbound = appended.filter(
            (item) =>
                item.kind === 'message' && item.message.direction === 'inbound',
        );

        if (inbound.length > 0) {
            announce(
                inbound.length === 1
                    ? `Nuevo mensaje de ${props.conversation.contact.display_name}`
                    : `${inbound.length} mensajes nuevos de ${props.conversation.contact.display_name}`,
            );
        }

        const ownReply = appended.some(
            (item) =>
                (item.kind === 'message' &&
                    item.message.direction === 'outbound') ||
                item.kind === 'note',
        );

        if (wasAtBottom || ownReply) {
            scrollToBottom(true);
        } else {
            unseen.value += appended.length;
        }
    },
);

watch(
    () => props.conversation.id,
    async () => {
        unseen.value = 0;
        announcement.value = '';
        restoringFrom = null;
        await nextTick();
        scrollToBottom();
    },
);

onMounted(() => scrollToBottom());

/* A long first page may not fill the panel: load more until it scrolls. */
watch(
    () => [props.items.length, props.hasMore, props.loadingOlder] as const,
    async () => {
        await nextTick();
        const element = scroller.value;

        if (
            element &&
            props.hasMore &&
            !props.loadingOlder &&
            element.scrollHeight <= element.clientHeight
        ) {
            void loadOlder();
        }
    },
);

function jumpTo(messageId: number): void {
    const target = scroller.value?.querySelector<HTMLElement>(
        `[data-message-id="${messageId}"]`,
    );

    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.classList.remove('message-flash');
        // Restart the highlight when the same message is jumped to again.
        void target.offsetWidth;
        target.classList.add('message-flash');
        setTimeout(() => target.classList.remove('message-flash'), 1500);
    } else {
        toast.info(
            'El mensaje citado es más antiguo: desplázate hacia arriba para cargarlo.',
        );
    }
}

/* Authorized manual retry of a failed reply (messaging module endpoint). */
const retryHttp = useHttp<{ confirm: boolean }, unknown>({
    confirm: false,
});
const retryingId = ref<number | null>(null);

/* A failure of unknown outcome may have reached the customer: ask first. */
const duplicateRisk = ref<ConversationMessage | null>(null);
const duplicateRiskOpen = computed({
    get: () => duplicateRisk.value !== null,
    set: (open: boolean) => {
        if (!open) {
            duplicateRisk.value = null;
        }
    },
});

function requestRetry(message: ConversationMessage): void {
    if (message.error_code === 'delivery_unknown') {
        duplicateRisk.value = message;

        return;
    }

    void retry(message);
}

function confirmRiskyRetry(): void {
    const message = duplicateRisk.value;

    duplicateRisk.value = null;

    if (message) {
        void retry(message);
    }
}

async function retry(message: ConversationMessage): Promise<void> {
    if (retryingId.value !== null) {
        return;
    }

    const unknownDelivery = message.error_code === 'delivery_unknown';

    retryingId.value = message.id;
    retryHttp.confirm = unknownDelivery;

    try {
        await retryHttp.post(
            retryRoute.url({
                conversation: props.conversation.id,
                message: message.id,
            }),
            {
                onError: (errors) => {
                    toast.error(
                        Object.values(errors)[0] ??
                            'No se pudo reintentar el envío.',
                    );
                },
            },
        );
        emit('refresh', { messages: [message.id] });
    } catch (failure) {
        const response =
            typeof failure === 'object' &&
            failure !== null &&
            'response' in failure
                ? (failure as { response: { status: number; data: string } })
                      .response
                : null;
        let detail: string | undefined;

        try {
            detail = response
                ? (JSON.parse(response.data) as { message?: string }).message
                : undefined;
        } catch {
            detail = undefined;
        }

        toast.error(
            detail ?? 'No se pudo reintentar el envío. Inténtalo de nuevo.',
        );
    } finally {
        retryingId.value = null;
    }
}

/*
 * Editing and "delete for everyone" of a message the team sent by a QR
 * connection (WhatsApp Web): WhatsApp applies it first, then the chat
 * refreshes the message.
 */
const editHttp = useHttp<{ body: string }, unknown>({ body: '' });
const deleteHttp = useHttp<Record<string, never>, unknown>({});
const editing = ref<ConversationMessage | null>(null);
const deleting = ref<ConversationMessage | null>(null);
const changingMessage = ref(false);
const editOpen = computed({
    get: () => editing.value !== null,
    set: (open: boolean) => {
        if (!open && !changingMessage.value) {
            editing.value = null;
        }
    },
});
const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (open: boolean) => {
        if (!open && !changingMessage.value) {
            deleting.value = null;
        }
    },
});

function requestEdit(message: ConversationMessage): void {
    editHttp.body = message.body ?? '';
    editing.value = message;
}

function requestDelete(message: ConversationMessage): void {
    deleting.value = message;
}

function firstError(errors: Record<string, string>, fallback: string): string {
    return Object.values(errors)[0] ?? fallback;
}

async function confirmEdit(): Promise<void> {
    const message = editing.value;

    if (!message || changingMessage.value) {
        return;
    }

    changingMessage.value = true;
    let rejected = false;

    try {
        await editHttp.put(
            updateMessageRoute.url({
                conversation: props.conversation.id,
                message: message.id,
            }),
            {
                onError: (errors) => {
                    rejected = true;
                    toast.error(
                        firstError(errors, 'No se pudo editar el mensaje.'),
                    );
                },
            },
        );

        if (!rejected) {
            toast.success('Mensaje editado en WhatsApp.');
            emit('refresh', { messages: [message.id] });
            changingMessage.value = false;
            editing.value = null;
        }
    } catch {
        toast.error('No se pudo editar el mensaje. Inténtalo de nuevo.');
    } finally {
        changingMessage.value = false;
    }
}

async function confirmDelete(): Promise<void> {
    const message = deleting.value;

    if (!message || changingMessage.value) {
        return;
    }

    changingMessage.value = true;
    let rejected = false;

    try {
        await deleteHttp.delete(
            destroyMessageRoute.url({
                conversation: props.conversation.id,
                message: message.id,
            }),
            {
                onError: (errors) => {
                    rejected = true;
                    toast.error(
                        firstError(errors, 'No se pudo eliminar el mensaje.'),
                    );
                },
            },
        );

        if (!rejected) {
            toast.success('Mensaje eliminado para todos en WhatsApp.');
            emit('refresh', { messages: [message.id] });
        }
    } catch {
        toast.error('No se pudo eliminar el mensaje. Inténtalo de nuevo.');
    } finally {
        changingMessage.value = false;
        deleting.value = null;
    }
}

/* Revocation of a customer's secure download link (link-delivered files). */
const revokeHttp = useHttp<Record<string, never>, unknown>({});
const revokingAttachmentId = ref<number | null>(null);
const pendingRevocation = ref<{
    message: ConversationMessage;
    attachment: MessageAttachment;
} | null>(null);
const revocationOpen = computed({
    get: () => pendingRevocation.value !== null,
    set: (open: boolean) => {
        if (!open && revokingAttachmentId.value === null) {
            pendingRevocation.value = null;
        }
    },
});

function requestRevokeLink(
    message: ConversationMessage,
    attachment: MessageAttachment,
): void {
    pendingRevocation.value = { message, attachment };
}

async function confirmRevokeLink(): Promise<void> {
    const pending = pendingRevocation.value;

    if (!pending || revokingAttachmentId.value !== null) {
        return;
    }

    revokingAttachmentId.value = pending.attachment.id;
    let rejected = false;

    try {
        await revokeHttp.post(
            revokeLinkRoute.url({
                conversation: props.conversation.id,
                message: pending.message.id,
                attachment: pending.attachment.id,
            }),
            {
                onError: (errors) => {
                    rejected = true;
                    toast.error(
                        Object.values(errors)[0] ??
                            'No se pudo revocar el enlace.',
                    );
                },
            },
        );

        if (rejected) {
            return;
        }

        toast.success(
            'Enlace revocado: el cliente ya no podrá descargar el archivo.',
        );
        emit('refresh', { messages: [pending.message.id] });
    } catch (failure) {
        const response =
            typeof failure === 'object' &&
            failure !== null &&
            'response' in failure
                ? (failure as { response: { status: number; data: string } })
                      .response
                : null;
        let detail: string | undefined;

        try {
            detail = response
                ? (JSON.parse(response.data) as { message?: string }).message
                : undefined;
        } catch {
            detail = undefined;
        }

        toast.error(
            detail ?? 'No se pudo revocar el enlace. Inténtalo de nuevo.',
        );
    } finally {
        revokingAttachmentId.value = null;
        pendingRevocation.value = null;
    }
}

defineExpose({ scrollToBottom });
</script>

<template>
    <!--
        Below 20rem of window height the chat pane scrolls as a whole and
        the history takes the pane's height minus the chat header (the top
        bar is 3rem there; --chat-header-h is set by ConversationChat), so
        it never collapses under the header and the composer.
    -->
    <div
        class="relative min-h-0 flex-1 [@media(max-height:20rem)]:h-[max(6rem,calc(100dvh-3rem-var(--chat-header-h,0px)))] [@media(max-height:20rem)]:flex-none"
    >
        <div
            ref="scroller"
            class="h-full overflow-y-auto overscroll-contain px-3 sm:px-6 lg:px-8 [@media(max-height:20rem)]:overscroll-auto"
            :class="[
                CHAT_UNDER_HEADER,
                showJumpButton
                    ? 'pb-16 [@media(max-height:20rem)]:pb-10'
                    : 'pb-4',
            ]"
            tabindex="0"
            role="log"
            :aria-label="`Historial de la conversación con ${conversation.contact.display_name}`"
            aria-live="off"
            @scroll.passive="onScroll"
        >
            <div class="mx-auto grid w-full max-w-[64rem] grid-cols-1">
                <ChatHistoryStart
                    :has-more="hasMore"
                    :loading="loadingOlder"
                    :has-items="items.length > 0"
                    :custom="customColors"
                    @load="loadOlder"
                />
                <p
                    v-if="failed"
                    class="text-center text-xs text-destructive"
                    :class="
                        customColors &&
                        'mx-auto w-fit rounded-full bg-background px-2.5 py-0.5'
                    "
                    role="alert"
                >
                    No se pudieron cargar los mensajes anteriores.
                </p>

                <EmptyState
                    v-if="items.length === 0"
                    class="border-none"
                    :class="customColors ? 'bg-background' : 'bg-transparent'"
                    :icon="MessagesSquare"
                    title="Sin mensajes todavía"
                    description="Aquí verás los mensajes del chat."
                />

                <!--
                    Each day is its own section and its separator sticks only
                    inside it: the next day's section pushes it away, so two
                    separators never pile up on top of each other.
                -->
                <section
                    v-for="group in dayGroups"
                    :key="group.day"
                    class="grid grid-cols-1 pt-1 pb-1.5"
                    :data-day="group.day"
                >
                    <ChatDaySeparator
                        as="h3"
                        :label="group.label"
                        data-day-separator
                    />
                    <template
                        v-for="{
                            item,
                            groupedWithPrevious,
                            groupedWithNext,
                        } in group.rows"
                        :key="item.key"
                    >
                        <!-- A Whaticket operation line: who did what, as an event. -->
                        <TimelineEvent
                            v-if="
                                item.kind === 'message' &&
                                item.message.imported?.event
                            "
                            class="mt-2.5"
                            :event="importedEvent(item.message)"
                        />
                        <MessageBubble
                            v-else-if="item.kind === 'message'"
                            :message="item.message"
                            :contact-name="conversation.contact.display_name"
                            :channel-label="
                                conversation.connection.channel_label
                            "
                            :can-retry="
                                conversation.can.reply &&
                                conversation.connection.can_send &&
                                item.message.direction === 'outbound' &&
                                item.message.status === 'failed'
                            "
                            :retrying="retryingId === item.message.id"
                            :can-quote="conversation.can.reply"
                            :grouped-with-previous="groupedWithPrevious"
                            :grouped-with-next="groupedWithNext"
                            :class="
                                fresh.has(item.key) &&
                                arriveClass(
                                    item.message.direction === 'outbound'
                                        ? 'out'
                                        : 'in',
                                )
                            "
                            @retry="requestRetry"
                            @quote="(message) => emit('quote', message)"
                            :revoking-attachment-id="revokingAttachmentId"
                            @jump="jumpTo"
                            @revoke-link="requestRevokeLink"
                            @edit="requestEdit"
                            @delete="requestDelete"
                        />
                        <TimelineNote
                            v-else-if="item.kind === 'note'"
                            :note="item.note"
                            :conversation-id="conversation.id"
                            :max-length="noteMaxLength"
                            class="mt-2.5"
                            :class="fresh.has(item.key) && arriveClass('out')"
                            @saved="
                                (noteId) => emit('refresh', { notes: [noteId] })
                            "
                        />
                        <TimelineEvent
                            v-else
                            class="mt-2.5"
                            :event="item.event"
                        />
                        <ChatDivider
                            v-if="item.key === historyEndsAt"
                            class="mt-3"
                            label="Historial importado de WhatsApp"
                            :custom="customColors"
                            role="separator"
                            data-history-divider
                        />
                    </template>
                </section>
            </div>
        </div>

        <ChatJumpButton
            :visible="showJumpButton"
            :count="unseen"
            :compact="compactJump"
            @click="scrollToBottom(true)"
        />

        <MediaViewer
            v-if="viewerOpen && gallery.length > 0"
            v-model:open="viewerOpen"
            v-model:index="viewerIndex"
            :items="gallery"
        />

        <ConfirmDialog
            v-model:open="duplicateRiskOpen"
            title="¿Reintentar el envío?"
            :description="`No se sabe si ${conversation.connection.channel_label} llegó a recibir este mensaje. Si lo reintentas, el cliente podría recibirlo dos veces.`"
            confirm-label="Reintentar de todas formas"
            destructive
            @confirm="confirmRiskyRetry"
        />

        <FormDialog
            v-model:open="editOpen"
            title="Editar mensaje"
            description="WhatsApp cambia el texto para el cliente y lo marca como editado. Solo se puede durante los primeros 15 minutos."
            submit-label="Guardar cambio"
            :processing="changingMessage"
            :submit-disabled="editHttp.body.trim() === ''"
            @submit="confirmEdit"
        >
            <Textarea
                v-model="editHttp.body"
                rows="4"
                maxlength="4096"
                aria-label="Nuevo texto del mensaje"
            />
        </FormDialog>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="¿Eliminar para todos?"
            description="El mensaje se borra también en el WhatsApp del cliente; aquí queda como «Mensaje eliminado»."
            confirm-label="Eliminar para todos"
            destructive
            :processing="changingMessage"
            @confirm="confirmDelete"
        />

        <ConfirmDialog
            v-model:open="revocationOpen"
            title="¿Revocar el enlace de descarga?"
            description="El cliente ya no podrá descargar el archivo con este enlace."
            confirm-label="Revocar enlace"
            destructive
            :processing="revokingAttachmentId !== null"
            @confirm="confirmRevokeLink"
        />

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{ announcement }}
        </p>
    </div>
</template>
