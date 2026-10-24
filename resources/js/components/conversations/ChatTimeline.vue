<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ArrowDown, MessagesSquare } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import MessageBubble from '@/components/conversations/MessageBubble.vue';
import { dayKey, dayLabel } from '@/components/conversations/messageFormat';
import TimelineEvent from '@/components/conversations/TimelineEvent.vue';
import TimelineNote from '@/components/conversations/TimelineNote.vue';
import type { TimelineItem } from '@/components/conversations/useConversationTimeline';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { retry as retryRoute } from '@/routes/conversations/messages';
import type { ConversationMessage, ConversationSummary } from '@/types';

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
const showJumpButton = computed(() => unseen.value > 0 || !atBottom.value);

type Row =
    | { type: 'day'; key: string; label: string }
    | { type: 'item'; key: string; item: TimelineItem };

const rows = computed<Row[]>(() => {
    const list: Row[] = [];
    let currentDay = '';

    for (const item of props.items) {
        const at =
            item.kind === 'message'
                ? (item.message.sent_at ?? item.at)
                : item.at;
        const day = dayKey(at);

        if (day !== currentDay) {
            currentDay = day;
            list.push({
                type: 'day',
                key: `d${day}-${item.key}`,
                label: dayLabel(at),
            });
        }

        list.push({ type: 'item', key: item.key, item });
    }

    return list;
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
                    ? `Nuevo mensaje de ${props.conversation.contact.name}`
                    : `${inbound.length} mensajes nuevos de ${props.conversation.contact.name}`,
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

defineExpose({ scrollToBottom });
</script>

<template>
    <div class="relative min-h-0 flex-1">
        <div
            ref="scroller"
            class="h-full overflow-y-auto overscroll-contain bg-muted/30 px-3 pt-4 sm:px-6"
            :class="showJumpButton ? 'pb-16' : 'pb-4'"
            tabindex="0"
            role="log"
            :aria-label="`Historial de la conversación con ${conversation.contact.name}`"
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

                <template v-for="row in rows" :key="row.key">
                    <div
                        v-if="row.type === 'day'"
                        class="sticky top-0 z-10 flex justify-center py-1"
                        role="separator"
                        :aria-label="row.label"
                    >
                        <span
                            class="rounded-full border bg-background/95 px-3 py-0.5 text-xs font-medium text-muted-foreground shadow-xs first-letter:uppercase"
                            >{{ row.label }}</span
                        >
                    </div>
                    <MessageBubble
                        v-else-if="row.item.kind === 'message'"
                        :message="row.item.message"
                        :contact-name="conversation.contact.name"
                        :can-retry="
                            conversation.can.reply &&
                            conversation.connection.can_send &&
                            row.item.message.direction === 'outbound' &&
                            row.item.message.status === 'failed'
                        "
                        :retrying="retryingId === row.item.message.id"
                        :can-quote="conversation.can.reply"
                        @retry="requestRetry"
                        @quote="(message) => emit('quote', message)"
                        @jump="jumpTo"
                    />
                    <TimelineNote
                        v-else-if="row.item.kind === 'note'"
                        :note="row.item.note"
                        :conversation-id="conversation.id"
                        :max-length="noteMaxLength"
                        @saved="
                            (noteId) => emit('refresh', { notes: [noteId] })
                        "
                    />
                    <TimelineEvent v-else :event="row.item.event" />
                </template>
            </div>
        </div>

        <Button
            v-if="showJumpButton"
            size="sm"
            variant="secondary"
            class="absolute right-4 bottom-4 rounded-full shadow-elevated pointer-coarse:h-10"
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
            description="No se sabe si WhatsApp llegó a recibir este mensaje. Si lo reintentas, el cliente podría recibirlo dos veces."
            confirm-label="Reintentar de todas formas"
            destructive
            @confirm="confirmRiskyRetry"
        />

        <p class="sr-only" aria-live="polite" aria-atomic="true">
            {{ announcement }}
        </p>
    </div>
</template>
