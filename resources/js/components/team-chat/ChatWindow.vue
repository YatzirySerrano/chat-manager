<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import { Archive, ArrowDown, ArrowLeft, BellOff, Settings2 } from '@lucide/vue';
import { useDocumentVisibility, useIntervalFn } from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import MessageBubble from '@/components/team-chat/MessageBubble.vue';
import MessageComposer from '@/components/team-chat/MessageComposer.vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useChannel } from '@/composables/useChannel';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { onReconnect, realtimeState } from '@/lib/realtime';
import { formatDate } from '@/lib/format';
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
 */
const props = defineProps<{
    thread: TeamThreadDetail;
    page: TeamMessagePage;
    around: number | null;
    limits: TeamChatLimits;
}>();

const emit = defineEmits<{
    openSettings: [];
    unreadChanged: [total: number];
}>();

const FALLBACK_INTERVAL_MS = 15_000;
const TYPING_TTL_MS = 5_000;

const messages = ref<TeamMessage[]>([...props.page.data]);
const hasMoreBefore = ref(props.page.has_more_before);
const hasMoreAfter = ref(props.page.has_more_after);
const loadingOlder = ref(false);
const loadingNewer = ref(false);
const scroller = ref<HTMLElement | null>(null);
const composer = ref<InstanceType<typeof MessageComposer> | null>(null);
const atBottom = ref(true);
const newBelow = ref(0);
const replyTo = ref<TeamMessage | null>(null);
const highlightedId = ref<number | null>(props.around);
const toDelete = ref<TeamMessage | null>(null);
const deleting = ref(false);
const savingId = ref<number | null>(null);
const actionError = ref<string | null>(null);
const visibility = useDocumentVisibility();

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

        return count === 1 ? '1 participante' : `${count} participantes`;
    }

    const partner = props.thread.partner;

    if (!partner || partner.deleted) {
        return 'Cuenta eliminada';
    }

    const availability =
        partner.availability === 'available' ? 'Disponible' : 'No disponible';

    return partnerOnline.value ? `En línea · ${availability}` : availability;
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

function dayOf(message: TeamMessage): string {
    return formatDate(message.created_at);
}

function startsDay(index: number): boolean {
    return (
        index === 0 ||
        dayOf(messages.value[index]) !== dayOf(messages.value[index - 1])
    );
}

function showsAuthor(index: number): boolean {
    const message = messages.value[index];
    const previous = messages.value[index - 1];

    return (
        props.thread.type === 'group' &&
        (startsDay(index) ||
            !previous ||
            previous.kind === 'system' ||
            previous.author?.id !== message.author?.id)
    );
}

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

    if (element.scrollTop < 120 && hasMoreBefore.value) {
        void loadOlder();
    }
}

async function scrollToBottom(smooth = false): Promise<void> {
    await nextTick();
    scroller.value?.scrollTo({
        top: scroller.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
    });
}

async function scrollToMessage(messageId: number): Promise<void> {
    await nextTick();
    document
        .getElementById(`team-message-${messageId}`)
        ?.scrollIntoView({ block: 'center' });
}

async function loadOlder(): Promise<void> {
    const first = messages.value[0];

    if (loadingOlder.value || !first) {
        return;
    }

    loadingOlder.value = true;
    const element = scroller.value;
    const previousHeight = element?.scrollHeight ?? 0;

    try {
        const page = await olderHttp.get(
            messagesIndex.url(props.thread.id, { query: { before: first.id } }),
            {
                onHttpException: () => false,
            },
        );
        merge(page.data);
        hasMoreBefore.value = page.has_more_before;
        await nextTick();

        if (element) {
            element.scrollTop += element.scrollHeight - previousHeight;
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
 */
async function loadNewer(): Promise<void> {
    const last = messages.value[messages.value.length - 1];

    if (loadingNewer.value) {
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
                (message) => !message.is_mine,
            ).length;
        }
    } catch {
        // A failed refresh is retried on the next signal or interval.
    } finally {
        loadingNewer.value = false;
    }
}

async function refreshMessages(ids: number[]): Promise<void> {
    try {
        const response = await refreshHttp.get(
            messagesIndex.url(props.thread.id, { query: { ids } }),
            {
                onHttpException: () => false,
            },
        );
        merge(response.data);
    } catch {
        // Retried on the next signal.
    }
}

let lastReadSent = props.thread.me.last_read_message_id ?? 0;

async function markLatestRead(): Promise<void> {
    const latest = messages.value[messages.value.length - 1];

    if (
        !latest ||
        latest.id <= lastReadSent ||
        visibility.value !== 'visible' ||
        !atBottom.value ||
        hasMoreAfter.value
    ) {
        return;
    }

    lastReadSent = latest.id;
    readHttp.message_id = latest.id;

    try {
        const response = await readHttp.post(markRead.url(props.thread.id), {
            onHttpException: () => false,
        });
        emit('unreadChanged', response.unread_total);
    } catch {
        lastReadSent = 0;
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
    savingId.value = message.id;
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
        actionError.value =
            editHttp.errors.body ?? 'No se pudo editar el mensaje.';
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
        toDelete.value = null;
    } catch {
        actionError.value = 'No se pudo eliminar el mensaje.';
    } finally {
        deleting.value = false;
    }
}

async function toggleReaction(
    message: TeamMessage,
    emoji: string,
): Promise<void> {
    actionError.value = null;
    reactionHttp.emoji = emoji;
    const mine = message.reactions.some(
        (reaction) => reaction.emoji === emoji && reaction.mine,
    );
    const target = { thread: props.thread.id, message: message.id };

    try {
        const response = mine
            ? await reactionHttp.delete(removeReaction.url(target), {
                  onHttpException: () => false,
              })
            : await reactionHttp.put(addReaction.url(target), {
                  onHttpException: () => false,
              });
        merge([response.data]);
    } catch {
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

    router.visit(`${window.location.pathname}?around=${messageId}`, {
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
    reloadThread();
});

useIntervalFn(() => {
    if (realtimeState.value !== 'connected' && visibility.value === 'visible') {
        void loadNewer();
    }
}, FALLBACK_INTERVAL_MS);

watch(visibility, (state) => {
    if (state === 'visible') {
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

onMounted(async () => {
    if (props.around !== null) {
        await scrollToMessage(props.around);
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
        class="flex h-full min-h-0 flex-col"
        :aria-label="`Chat: ${thread.title}`"
    >
        <header
            class="flex shrink-0 items-center gap-2 border-b px-2 py-2 sm:px-4"
        >
            <Button
                variant="ghost"
                size="icon"
                class="lg:hidden"
                as-child
                aria-label="Volver a la lista de chats"
            >
                <Link :href="teamChatIndex()" preserve-state>
                    <ArrowLeft />
                </Link>
            </Button>
            <TeamAvatar
                :name="thread.title"
                :group="thread.type === 'group'"
                :deleted="thread.partner?.deleted ?? false"
                :availability="thread.partner?.availability ?? null"
                :online="partnerOnline"
            />
            <div class="min-w-0 flex-1">
                <h2
                    class="flex items-center gap-1.5 truncate text-sm font-semibold"
                >
                    <span class="truncate">{{ thread.title }}</span>
                    <BellOff
                        v-if="thread.me.muted"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="Silenciado"
                    />
                </h2>
                <p class="truncate text-xs text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="
                    thread.type === 'group'
                        ? 'Información y ajustes del grupo'
                        : 'Información del chat'
                "
                :title="
                    thread.type === 'group'
                        ? 'Información y ajustes del grupo'
                        : 'Información del chat'
                "
                @click="emit('openSettings')"
            >
                <Settings2 />
            </Button>
        </header>

        <div
            v-if="thread.archived"
            class="flex shrink-0 items-center gap-2 border-b bg-muted/60 px-4 py-2 text-xs text-muted-foreground"
            role="status"
        >
            <Archive class="size-3.5" aria-hidden="true" />
            Grupo archivado: puedes consultar su historial, pero ya no se pueden
            enviar mensajes.
        </div>

        <div class="relative min-h-0 flex-1">
            <div
                ref="scroller"
                class="h-full overflow-y-auto overscroll-contain py-3"
                role="log"
                aria-live="polite"
                aria-relevant="additions"
                :aria-busy="loadingOlder || loadingNewer"
                @scroll.passive="onScroll"
            >
                <div v-if="hasMoreBefore" class="flex justify-center py-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="loadingOlder"
                        @click="loadOlder"
                    >
                        <Spinner v-if="loadingOlder" />
                        Cargar mensajes anteriores
                    </Button>
                </div>
                <p
                    v-else-if="messages.length > 0"
                    class="px-4 pb-2 text-center text-xs text-muted-foreground"
                >
                    Inicio de la conversación
                </p>

                <p
                    v-if="messages.length === 0"
                    class="mx-auto mt-10 max-w-xs px-4 text-center text-sm text-muted-foreground"
                >
                    Aún no hay mensajes. Escribe el primero: solo
                    {{
                        thread.type === 'group'
                            ? 'los participantes del grupo'
                            : 'ustedes dos'
                    }}
                    pueden leerlo.
                </p>

                <template
                    v-for="(message, index) in messages"
                    :key="message.id"
                >
                    <div
                        v-if="startsDay(index)"
                        class="flex justify-center py-2"
                    >
                        <span
                            class="rounded-full border bg-background px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                        >
                            {{ dayOf(message) }}
                        </span>
                    </div>
                    <MessageBubble
                        :message="message"
                        :show-author="showsAuthor(index)"
                        :highlighted="highlightedId === message.id"
                        :seen="
                            message.id === lastOwnMessageId &&
                            partnerReadUpTo !== null &&
                            partnerReadUpTo >= message.id
                        "
                        :can-reply="thread.can.post"
                        :reactions="thread.can.post ? limits.reactions : []"
                        :saving="savingId === message.id"
                        @react="toggleReaction"
                        @reply="reply"
                        @edit="saveEdit"
                        @delete="toDelete = $event"
                        @jump="jumpTo"
                    />
                </template>

                <div v-if="hasMoreAfter" class="flex justify-center py-2">
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="loadingNewer"
                        @click="loadNewer"
                    >
                        <Spinner v-if="loadingNewer" />
                        Cargar mensajes más recientes
                    </Button>
                </div>
            </div>

            <Button
                v-if="!atBottom"
                size="sm"
                variant="secondary"
                class="absolute right-4 bottom-3 rounded-full shadow-md"
                :aria-label="
                    newBelow > 0
                        ? `${newBelow} mensajes nuevos, ir al final`
                        : 'Ir al final'
                "
                @click="
                    hasMoreAfter
                        ? router.visit(`${$page.url.split('?')[0]}`)
                        : scrollToBottom(true)
                "
            >
                <ArrowDown />
                <span v-if="newBelow > 0">{{ newBelow }} nuevos</span>
            </Button>
        </div>

        <p
            v-if="actionError"
            class="shrink-0 border-t bg-destructive/5 px-4 py-1.5 text-xs text-destructive"
            role="alert"
        >
            {{ actionError }}
        </p>

        <p
            v-if="typingLabel && thread.can.post"
            class="shrink-0 truncate px-4 pt-1 text-xs text-muted-foreground italic"
            aria-live="polite"
        >
            {{ typingLabel }}
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
            class="shrink-0 border-t bg-muted/40 px-4 py-3 text-center text-sm text-muted-foreground"
        >
            {{
                thread.post_blocked_reason ??
                'No puedes enviar mensajes en este chat.'
            }}
        </p>

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
    </section>
</template>
