<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ArrowDown, MessagesSquare } from '@lucide/vue';
import {
    useDocumentVisibility,
    useElementSize,
    useIntervalFn,
} from '@vueuse/core';
import { computed, inject, nextTick, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import MessageBubble from '@/components/conversations/MessageBubble.vue';
import { groupByCalendarDay } from '@/components/conversations/dayGroups';
import { todayKey } from '@/components/conversations/messageFormat';
import TimelineEvent from '@/components/conversations/TimelineEvent.vue';
import TimelineNote from '@/components/conversations/TimelineNote.vue';
import type { TimelineItem } from '@/components/conversations/useConversationTimeline';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    CUSTOM_BUBBLE_CLASSES,
    CUSTOM_CHAT_COLORS,
} from '@/lib/chatAppearance';
import { platformTimezone } from '@/lib/format';
import { retry as retryRoute } from '@/routes/conversations/messages';
import { revokeLink as revokeLinkRoute } from '@/routes/conversations/messages/attachments';
import type {
    ConversationMessage,
    ConversationSummary,
    MessageAttachment,
} from '@/types';

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
const atBottom = ref(true);
const unseen = ref(0);
const announcement = ref('');

const BOTTOM_THRESHOLD = 96;

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

const dayGroups = computed(() =>
    groupByCalendarDay(
        props.items,
        (item) => item.at,
        today.value,
        platformTimezone(),
    ),
);

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
        target.classList.add('ring-2', 'ring-primary');
        setTimeout(
            () => target.classList.remove('ring-2', 'ring-primary'),
            1500,
        );
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
            class="h-full overflow-y-auto overscroll-contain px-3 pt-4 sm:px-6 [@media(max-height:20rem)]:overscroll-auto [@media(max-height:20rem)]:pt-2"
            :class="[
                showJumpButton
                    ? 'pb-16 [@media(max-height:20rem)]:pb-10'
                    : 'pb-4',
                customColors ? CUSTOM_BUBBLE_CLASSES.chat : 'bg-muted/30',
            ]"
            tabindex="0"
            role="log"
            :aria-label="`Historial de la conversación con ${conversation.contact.display_name}`"
            aria-live="off"
            @scroll.passive="onScroll"
        >
            <div class="mx-auto grid max-w-4xl grid-cols-1 gap-2">
                <div class="flex justify-center py-1">
                    <Button
                        v-if="hasMore"
                        variant="ghost"
                        size="sm"
                        class="pointer-coarse:h-10"
                        :disabled="loadingOlder"
                        @click="loadOlder"
                    >
                        <Spinner v-if="loadingOlder" />
                        Cargar mensajes anteriores
                    </Button>
                    <p
                        v-else-if="items.length > 0"
                        class="text-xs text-muted-foreground"
                    >
                        Inicio de la conversación
                    </p>
                </div>
                <p
                    v-if="failed"
                    class="text-center text-xs text-destructive"
                    role="alert"
                >
                    No se pudieron cargar los mensajes anteriores.
                </p>

                <EmptyState
                    v-if="items.length === 0"
                    class="border-none bg-transparent"
                    :icon="MessagesSquare"
                    title="Sin mensajes todavía"
                    description="Cuando el cliente escriba o alguien responda, los mensajes aparecerán aquí."
                />

                <!--
                    Each day is its own section and its separator sticks only
                    inside it: the next day's section pushes it away, so two
                    separators never pile up on top of each other.
                -->
                <section
                    v-for="group in dayGroups"
                    :key="group.day"
                    class="grid grid-cols-1 gap-2 pt-0.5"
                    :data-day="group.day"
                >
                    <h3
                        class="pointer-events-none sticky top-0 z-10 flex justify-center py-1"
                        data-day-separator
                    >
                        <span
                            class="max-w-full rounded-full border bg-background px-3 py-0.5 text-center text-xs font-medium text-balance break-words text-muted-foreground shadow-xs"
                            >{{ group.label }}</span
                        >
                    </h3>
                    <template v-for="item in group.items" :key="item.key">
                        <MessageBubble
                            v-if="item.kind === 'message'"
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
                            @retry="requestRetry"
                            @quote="(message) => emit('quote', message)"
                            :revoking-attachment-id="revokingAttachmentId"
                            @jump="jumpTo"
                            @revoke-link="requestRevokeLink"
                        />
                        <TimelineNote
                            v-else-if="item.kind === 'note'"
                            :note="item.note"
                            :conversation-id="conversation.id"
                            :max-length="noteMaxLength"
                            @saved="
                                (noteId) => emit('refresh', { notes: [noteId] })
                            "
                        />
                        <TimelineEvent v-else :event="item.event" />
                    </template>
                </section>
            </div>
        </div>

        <Button
            v-if="showJumpButton"
            size="sm"
            variant="secondary"
            class="absolute right-4 bottom-4 rounded-full shadow-elevated pointer-coarse:h-10 [@media(max-height:20rem)]:right-2 [@media(max-height:20rem)]:bottom-2 [@media(max-height:20rem)]:h-7 [@media(max-height:20rem)]:px-2.5"
            @click="scrollToBottom(true)"
        >
            <ArrowDown aria-hidden="true" />
            {{
                unseen > 0
                    ? unseen === 1
                        ? '1 nuevo'
                        : `${unseen} nuevos`
                    : 'Ir al final'
            }}
        </Button>

        <ConfirmDialog
            v-model:open="duplicateRiskOpen"
            title="¿Reintentar el envío?"
            :description="`No se sabe si ${conversation.connection.channel_label} llegó a recibir este mensaje. Si lo reintentas, el cliente podría recibirlo dos veces.`"
            confirm-label="Reintentar de todas formas"
            destructive
            @confirm="confirmRiskyRetry"
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
