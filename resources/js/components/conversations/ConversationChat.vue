<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    Palette,
    PanelRight,
    PanelRightClose,
    Paperclip,
} from '@lucide/vue';
import {
    useDocumentVisibility,
    useElementSize,
    watchDebounced,
} from '@vueuse/core';
import { computed, onScopeDispose, provide, ref, toRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import ChatComposer from '@/components/conversations/ChatComposer.vue';
import ChatTimeline from '@/components/conversations/ChatTimeline.vue';
import ConversationActions from '@/components/conversations/ConversationActions.vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import SlaBadge from '@/components/sla/SlaBadge.vue';
import CategoryBadge from '@/components/categories/CategoryBadge.vue';
import AcceptanceNotice from '@/components/conversations/AcceptanceNotice.vue';
import ReadinessBadge from '@/components/conversations/ReadinessBadge.vue';
import TemplatePickerDialog from '@/components/conversations/TemplatePickerDialog.vue';
import { useConversationTimeline } from '@/components/conversations/useConversationTimeline';
import { reportMessageStatus } from '@/components/conversations/useInboxMessageStatus';
import DepartmentTag from '@/components/DepartmentTag.vue';
import ChatAppearanceDialog from '@/components/personalization/ChatAppearanceDialog.vue';
import { Button } from '@/components/ui/button';
import { useChannel } from '@/composables/useChannel';
import { useInitials } from '@/composables/useInitials';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';
import { CUSTOM_CHAT_COLORS, chatColorVariables } from '@/lib/chatAppearance';
import { afterUserVisits, onReconnect } from '@/lib/realtime';
import { formatPhone } from '@/lib/phone';
import { read as markRead } from '@/routes/conversations';
import type {
    ComposerSettings,
    ConversationMessage,
    ConversationSummary,
    ConversationTimelinePage,
    CustomerWindow,
    ViewerChatAppearance,
} from '@/types';

/**
 * Center panel of the inbox: header with the customer and the allowed
 * operations, the chat history and the composer. While open it listens to
 * the conversation's private channel (falling back to periodic refresh when
 * real-time is not available) and moves the viewer's read marker.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    window: CustomerWindow;
    timeline: ConversationTimelinePage | null;
    transferReasonRequired: boolean;
    composer: ComposerSettings;
    detailsOpen: boolean;
}>();

const emit = defineEmits<{
    back: [];
    toggleDetails: [];
    read: [conversationId: number];
}>();

/** Props of the selected conversation reloaded after a change signal. */
const SELECTION_RELOAD = [
    'selectedId',
    'selectionMissing',
    'conversation',
    'window',
    'details',
    'timeline',
];

/** Header props (state, 24 h window) without the timeline or the details. */
const HEADER_RELOAD = [
    'selectedId',
    'selectionMissing',
    'conversation',
    'window',
];

const conversationId = computed<number | null>(() => props.conversation.id);
const timeline = useConversationTimeline(
    conversationId,
    toRef(props, 'timeline'),
);
const replyTo = ref<ConversationMessage | null>(null);
const templatesOpen = ref(false);

watch(conversationId, () => {
    replyTo.value = null;
    templatesOpen.value = false;
});

function reloadSelection(): void {
    afterUserVisits(() => router.reload({ only: SELECTION_RELOAD }));
}

/*
 * Timeline requests caused by real-time signals also wait for the user's
 * own visit in flight: the signal of an operation (transfer, close…) arrives
 * while its request is still redirecting, and any request reaching the
 * server in between consumes the one-time session flash, so the success
 * toast of that operation was lost.
 */
let disposed = false;
onScopeDispose(() => (disposed = true));

function afterVisit(task: () => void): void {
    afterUserVisits(() => {
        if (!disposed) {
            task();
        }
    });
}

/* Real-time signals of this conversation (ids only; data is refetched). */
useChannel(
    () =>
        conversationId.value ? `conversations.${conversationId.value}` : null,
    {
        /*
         * The only signal of a new message: a customer message also reopens
         * the 24 h window, so the conversation header is refreshed too.
         */
        '.message.recorded': (payload) => {
            if (payload.direction === 'inbound') {
                afterUserVisits(() => router.reload({ only: HEADER_RELOAD }));
            }

            afterVisit(() => void timeline.fetchNewer());
        },
        '.message.status': (payload) => {
            const id = Number(payload.message_id);
            // The inbox row of this conversation follows the thread (INBOX).
            reportMessageStatus(payload);

            if (Number.isInteger(id) && id > 0) {
                afterVisit(() => void timeline.refresh({ messages: [id] }));
            }
        },
        '.note.saved': (payload) => {
            const id = Number(payload.note_id);

            afterVisit(() => {
                void timeline.fetchNewer();

                if (Number.isInteger(id) && id > 0) {
                    void timeline.refresh({ notes: [id] });
                }
            });
        },
        '.conversation.changed': (payload) => {
            if (payload.reason === 'read') {
                return;
            }

            /*
             * Someone erased this chat for good: nothing of it can be read
             * again, so the list is refreshed and the selection is dropped.
             */
            if (payload.reason === 'deleted') {
                toast.info('Este chat se eliminó definitivamente.');
                router.reload();

                return;
            }

            /*
             * A change of one message (its media finished downloading, a
             * download link was revoked) names that message: only it is
             * read again. Without the id, the whole selection is reloaded.
             */
            const messageId = Number(payload.message_id);

            if (
                payload.reason === 'message' &&
                Number.isInteger(messageId) &&
                messageId > 0
            ) {
                afterVisit(
                    () => void timeline.refresh({ messages: [messageId] }),
                );

                return;
            }

            reloadSelection();
            afterVisit(() => void timeline.fetchNewer());
        },
    },
);

/* Without real-time, refresh the open chat periodically. */
const { getInitials } = useInitials();
const { state: realtimeState } = useRealtimeStatus();
const visibility = useDocumentVisibility();
const FALLBACK_MS = 20_000;
let fallbackTimer: ReturnType<typeof setInterval> | null = null;

function pendingDeliveryIds(): number[] {
    return timeline.items.value
        .filter(
            (item) =>
                item.kind === 'message' &&
                item.message.direction === 'outbound' &&
                ['queued', 'sending', 'accepted', 'sent', 'delivered'].includes(
                    item.message.status,
                ),
        )
        .slice(-50)
        .map((item) => item.id);
}

function catchUp(): void {
    void timeline.fetchNewer();
    void timeline.refresh({ messages: pendingDeliveryIds() });
    afterUserVisits(() => router.reload({ only: HEADER_RELOAD }));
}

watch(
    () => realtimeState.value === 'connected',
    (live) => {
        if (fallbackTimer) {
            clearInterval(fallbackTimer);
            fallbackTimer = null;
        }

        if (!live) {
            fallbackTimer = setInterval(() => {
                if (visibility.value === 'visible') {
                    catchUp();
                }
            }, FALLBACK_MS);
        }
    },
    { immediate: true },
);

const stopReconnect = onReconnect(catchUp);

onScopeDispose(() => {
    stopReconnect();

    if (fallbackTimer) {
        clearInterval(fallbackTimer);
    }
});

/* Read marker: moves forward while the chat is visible. */
const readHttp = useHttp<{ message_id: number | null }, { moved: boolean }>({
    message_id: null,
});
const lastMarked = new Map<number, number>();

watchDebounced(
    () =>
        [
            conversationId.value,
            timeline.latestMessageId.value,
            visibility.value,
        ] as const,
    async ([id, latest, visible]) => {
        if (id === null || latest === 0 || visible !== 'visible') {
            return;
        }

        if ((lastMarked.get(id) ?? 0) >= latest) {
            return;
        }

        lastMarked.set(id, latest);
        readHttp.message_id = latest;

        try {
            await readHttp.post(markRead.url(id));
            emit('read', id);
        } catch {
            lastMarked.delete(id);
        }
    },
    { debounce: 800, immediate: true },
);

/*
 * The reply returned by the server is already in the timeline. With
 * real-time connected, its own signals (".message.recorded" for the chat and
 * ".inbox.changed" for the list) bring the rest, so only the header is
 * reloaded; without them, the chat and the list are refreshed here.
 */
function onSent(message: ConversationMessage): void {
    timeline.upsertMessage(message);

    if (realtimeState.value === 'connected') {
        router.reload({ only: ['conversation'] });

        return;
    }

    void timeline.fetchNewer();
    router.reload({ only: ['conversation', 'conversations'] });
}

function onTemplateSent(): void {
    void timeline.fetchNewer();
    router.reload({ only: ['conversation', 'window', 'conversations'] });
}

const phone = computed(() =>
    props.conversation.contact.phone_e164
        ? formatPhone(props.conversation.contact.phone_e164)
        : null,
);

/*
 * The viewer's own chat colors (PERSONALIZATION): CSS variables on the chat
 * container only, replaced right after saving without reloading the page.
 */
const appearance = ref<ViewerChatAppearance | null>(
    props.conversation.appearance ?? null,
);
const appearanceOpen = ref(false);

watch(
    () => props.conversation.appearance,
    (value) => (appearance.value = value ?? null),
);

const customColors = computed(() => Boolean(appearance.value?.colors));
const chatStyle = computed(() => chatColorVariables(appearance.value?.colors));

provide(CUSTOM_CHAT_COLORS, customColors);

/*
 * The header's height, for the history's own height on very short windows
 * (pane minus header: the history and its «Ir al final» button fill the
 * first screen; the composer follows below).
 */
const header = ref<HTMLElement | null>(null);
const { height: headerHeight } = useElementSize(header, undefined, {
    box: 'border-box',
});
const paneStyle = computed(() => ({
    '--chat-header-h': `${Math.round(headerHeight.value)}px`,
}));

/*
 * Files dragged over the conversation: the whole pane is the drop target
 * and says, while the files hover, whether they can be attached. What is
 * dropped goes to the composer, which checks it against the channel's
 * rules exactly as a file chosen with the clip.
 */
const composerRef = ref<InstanceType<typeof ChatComposer> | null>(null);
const dragDepth = ref(0);
const dropActive = computed(() => dragDepth.value > 0);
const dropAllowed = computed(() => composerRef.value?.acceptsFiles ?? false);

function carriesFiles(event: DragEvent): boolean {
    return [...(event.dataTransfer?.types ?? [])].includes('Files');
}

function onDragEnter(event: DragEvent): void {
    if (carriesFiles(event)) {
        event.preventDefault();
        dragDepth.value++;
    }
}

function onDragOver(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    event.preventDefault();

    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = dropAllowed.value ? 'copy' : 'none';
    }
}

function onDragLeave(event: DragEvent): void {
    if (carriesFiles(event)) {
        dragDepth.value = Math.max(0, dragDepth.value - 1);
    }
}

function onDrop(event: DragEvent): void {
    if (!carriesFiles(event)) {
        return;
    }

    event.preventDefault();
    dragDepth.value = 0;

    // A dragged folder has no type and no size: it is not a file to send.
    const files = [...(event.dataTransfer?.files ?? [])].filter(
        (file) => file.size > 0 || file.type !== '',
    );

    if (files.length === 0) {
        toast.error(
            'No se pudo leer lo que soltaste. Las carpetas no se pueden adjuntar: suelta los archivos.',
        );

        return;
    }

    const refusal = composerRef.value?.addFiles(files) ?? null;

    if (refusal) {
        toast.error(refusal);
    }
}

/* A drag that ends outside the window never fires "dragleave" here. */
watch(conversationId, () => (dragDepth.value = 0));
</script>

<template>
    <!--
        Very short viewports (a laptop at 400 % zoom is 320 × 200 CSS px):
        the header, the history and the composer no longer fit in one
        screen, so the pane scrolls as a whole instead. The header scrolls
        away and the history keeps a usable height of its own.
    -->
    <section
        class="relative flex h-full min-h-0 min-w-0 flex-col bg-background [@media(max-height:20rem)]:overflow-y-auto"
        @dragenter="onDragEnter"
        @dragover="onDragOver"
        @dragleave="onDragLeave"
        @drop="onDrop"
        :aria-label="`Conversación con ${conversation.contact.display_name}`"
        :style="paneStyle"
        data-chat-pane
    >
        <header
            ref="header"
            class="flex items-center gap-2 border-b px-2 py-2 sm:gap-3 sm:px-4 [@media(max-height:32rem)]:py-0.5"
        >
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 lg:hidden pointer-coarse:size-10"
                title="Volver a la lista"
                aria-label="Volver a la lista de conversaciones"
                @click="emit('back')"
            >
                <ArrowLeft aria-hidden="true" />
            </Button>
            <span
                class="hidden size-10 shrink-0 place-items-center rounded-full bg-primary-soft text-sm font-semibold text-primary-soft-foreground sm:grid [@media(max-height:32rem)]:hidden!"
                aria-hidden="true"
                >{{ getInitials(conversation.contact.display_name) }}</span
            >
            <button
                type="button"
                class="@container min-w-0 flex-1 rounded-lg px-1.5 py-0.5 text-left transition-colors duration-(--duration-fast) outline-none hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring"
                :title="
                    detailsOpen
                        ? 'Ocultar detalles'
                        : 'Ver detalles del cliente'
                "
                @click="emit('toggleDetails')"
            >
                <h2
                    class="flex min-w-0 items-center gap-2 font-display text-lg leading-snug font-semibold"
                >
                    <!-- On phones the channel shows here, next to the name. -->
                    <ChannelIcon
                        :channel="conversation.connection.channel"
                        class="size-4 shrink-0 text-muted-foreground sm:hidden"
                        aria-hidden="true"
                    />
                    <span
                        class="truncate"
                        :title="conversation.contact.display_name"
                        >{{ conversation.contact.display_name }}</span
                    >
                    <span
                        v-if="conversation.contact.alias"
                        class="shrink-0 rounded-full border px-1.5 py-px text-xs font-medium text-muted-foreground"
                        :title="`Alias personal: solo tú lo ves. Nombre oficial: ${conversation.contact.name}`"
                        data-alias-badge
                    >
                        Alias personal
                        <span class="sr-only"
                            >. Nombre oficial:
                            {{ conversation.contact.name }}</span
                        >
                    </span>
                </h2>
                <!--
                    One line on phones and short screens: the state, then
                    the phone (cut with an ellipsis); the channel moves next
                    to the name and the department and agent stay in
                    «Detalles». On very short windows (400 % zoom) only the
                    name shows and this line is left to screen readers.
                -->
                <p
                    class="mt-0.5 flex min-w-0 flex-nowrap items-center gap-x-2 overflow-hidden text-xs text-muted-foreground [@media(max-height:20rem)]:sr-only"
                >
                    <ConversationStatusBadge
                        class="shrink-0"
                        :status="conversation.status"
                        :label="conversation.status_label"
                    />
                    <!-- Not the interactive variant: this line lives inside
                     the button that opens the details (which show the
                     full SLA detail), and a button cannot hold another. -->
                    <SlaBadge
                        v-if="conversation.sla"
                        :sla="conversation.sla"
                        variant="compact"
                        announce
                    />
                    <ReadinessBadge
                        v-if="
                            conversation.readiness &&
                            conversation.status !== 'closed' &&
                            (conversation.readiness.value === 'needs_info' ||
                                (conversation.readiness.tracked &&
                                    conversation.status === 'pending'))
                        "
                        class="shrink-0"
                        :value="conversation.readiness.value"
                        :label="conversation.readiness.label"
                    />
                    <CategoryBadge
                        v-if="conversation.category"
                        class="shrink-0 max-sm:hidden"
                        :name="conversation.category.name"
                        :color="conversation.category.color"
                        :deleted="conversation.category.is_active === false"
                    />
                    <span
                        class="inline-flex shrink-0 items-center gap-1 max-sm:sr-only"
                        :title="`Canal: ${conversation.connection.channel_label}`"
                        data-channel-indicator
                    >
                        <ChannelIcon
                            :channel="conversation.connection.channel"
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="sr-only">Canal:</span>
                        {{ conversation.connection.channel_label }}
                    </span>
                    <span
                        v-if="phone"
                        class="min-w-0 truncate tabular-nums"
                        :title="phone"
                        >{{ phone }}</span
                    >
                    <DepartmentTag
                        v-if="conversation.department"
                        class="min-w-0 shrink-0 @max-md:hidden"
                        :name="conversation.department.name"
                        :color="conversation.department.color"
                        :muted="!conversation.department.is_active"
                    />
                    <span class="min-w-0 truncate @max-lg:hidden">
                        {{
                            conversation.assignee
                                ? `Atiende ${conversation.assignee.name}`
                                : 'Sin agente'
                        }}
                    </span>
                </p>
            </button>
            <ConversationActions
                data-tour="chat.actions"
                :conversation="conversation"
                :transfer-reason-required="transferReasonRequired"
            />
            <Button
                v-if="appearance"
                variant="ghost"
                size="icon"
                class="shrink-0 pointer-coarse:size-10"
                title="Apariencia del chat"
                aria-label="Cambiar los colores de tu chat"
                @click="appearanceOpen = true"
            >
                <Palette aria-hidden="true" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 pointer-coarse:size-10"
                :title="detailsOpen ? 'Ocultar detalles' : 'Mostrar detalles'"
                :aria-label="
                    detailsOpen
                        ? 'Ocultar el panel de detalles'
                        : 'Mostrar el panel de detalles'
                "
                :aria-pressed="detailsOpen"
                data-tour="chat.details"
                @click="emit('toggleDetails')"
            >
                <PanelRightClose v-if="detailsOpen" aria-hidden="true" />
                <PanelRight v-else aria-hidden="true" />
            </Button>
        </header>

        <!-- Why this pending request cannot be taken yet (describes «Aceptar»). -->
        <AcceptanceNotice
            v-if="conversation.status === 'pending'"
            :id="`acceptance-chat-${conversation.id}`"
            :conversation="conversation"
            class="border-b bg-muted/40 px-3 py-2 sm:px-4"
        />

        <ChatTimeline
            :style="chatStyle"
            :conversation="conversation"
            :items="timeline.items.value"
            :has-more="timeline.hasMore.value"
            :loading-older="timeline.loadingOlder.value"
            :failed="timeline.failed.value"
            :note-max-length="composer.note_max"
            @load-older="timeline.loadOlder"
            @refresh="timeline.refresh"
            @quote="(message) => (replyTo = message)"
        />

        <ChatComposer
            ref="composerRef"
            data-tour="chat.composer"
            v-model:reply-to="replyTo"
            :conversation="conversation"
            :window="window"
            :settings="composer"
            @sent="onSent"
            @note-saved="(note) => timeline.upsertNote(note)"
            @open-templates="templatesOpen = true"
        />

        <!-- Shown only while files hover over the conversation. -->
        <div
            v-if="dropActive"
            class="pointer-events-none absolute inset-0 z-30 grid place-items-center drop-veil p-4"
            data-drop-overlay
            :data-drop-allowed="dropAllowed"
            aria-hidden="true"
        >
            <div
                class="grid w-full max-w-md justify-items-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center"
                :class="
                    dropAllowed
                        ? 'border-primary bg-primary-soft/70 text-primary-soft-foreground'
                        : 'border-border-strong bg-card text-muted-foreground'
                "
            >
                <span
                    class="grid size-12 place-items-center rounded-full"
                    :class="
                        dropAllowed
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted'
                    "
                >
                    <Paperclip v-if="dropAllowed" class="size-5" />
                    <Ban v-else class="size-5" />
                </span>
                <p class="font-display text-lg font-semibold">
                    {{
                        dropAllowed
                            ? 'Suelta para adjuntar'
                            : 'Ahora no se pueden adjuntar archivos'
                    }}
                </p>
                <p class="max-w-prose text-sm">
                    {{
                        dropAllowed
                            ? `Los archivos se preparan para ${conversation.contact.display_name}. Nada se envía hasta que pulses «Enviar».`
                            : 'Para enviar archivos necesitas poder responder al cliente en esta conversación.'
                    }}
                </p>
            </div>
        </div>

        <TemplatePickerDialog
            v-model:open="templatesOpen"
            :conversation="conversation"
            @sent="onTemplateSent"
        />

        <ChatAppearanceDialog
            v-if="appearance"
            v-model:open="appearanceOpen"
            :target="{ type: 'conversation', id: conversation.id }"
            :appearance="appearance"
            @saved="(value) => (appearance = value)"
        />
    </section>
</template>
