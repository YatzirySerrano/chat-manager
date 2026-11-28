<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    ExternalLink,
    History,
    Loader2,
    RefreshCw,
} from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import ChatPreviewBubble from '@/components/chat-preview/ChatPreviewBubble.vue';
import type { ChatPreviewItem } from '@/components/chat-preview/types';
import { dayLabel } from '@/components/conversations/messageFormat';
import { toPreviewItem } from '@/components/conversations/previewItem';
import RequestBoundaryDialog from '@/components/conversations/RequestBoundaryDialog.vue';
import {
    requestClient,
    requestFacts,
    resultClasses,
    resultLabel,
} from '@/components/conversations/requestSummary';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import {
    CUSTOM_BUBBLE_CLASSES,
    chatColorVariables,
} from '@/lib/chatAppearance';
import { formatDate, formatDateTime } from '@/lib/format';
import {
    fullHistory as fullHistoryRoute,
    show as showRoute,
} from '@/routes/conversations';
import type {
    ChatColors,
    FullHistoryPage,
    FullHistorySegment,
    RequestBoundaryResult,
} from '@/types';

/**
 * «Conversación completa»: everything the contact wrote and was answered,
 * across all their conversations the viewer may open, in chronological
 * order and not cut by request. A separator marks where each request starts
 * («Solicitud · 26 nov 2026 · Cliente: … · Crédito aprobado $3,000 ·
 * atendió …»), with a link to open that conversation.
 *
 * It only reads (GET conversations.full-history): nothing is marked as
 * read. The newest page loads first, scrolled to the latest message; older
 * pages load when the top is reached (or with «Cargar anteriores»), keeping
 * the reader's place. Files come through the same authorized attachment
 * route as the chat, and the bubbles take the viewer's own chat colors.
 */
const props = defineProps<{
    /** The conversation it is opened from (authorizes the request). */
    conversationId: number;
    /** The contact's name until the first page says how the viewer sees it. */
    contactName: string;
}>();

const open = defineModel<boolean>('open', { required: true });

type Segment = Omit<FullHistorySegment, 'messages'> & {
    items: ChatPreviewItem[];
};

const http = useHttp<Record<string, never>, FullHistoryPage>({});
const segments = ref<Segment[]>([]);
const next = ref<FullHistoryPage['next']>(null);
const loaded = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);
const colors = ref<ChatColors | null>(null);
const name = ref(props.contactName);
const scroller = ref<HTMLElement | null>(null);
const sentinel = ref<HTMLElement | null>(null);
let generation = 0;

function toSegment(segment: FullHistorySegment): Segment {
    return {
        conversation: segment.conversation,
        starts: segment.starts,
        can_adjust_boundary: segment.can_adjust_boundary,
        items: segment.messages.map((message) =>
            toPreviewItem(message, name.value),
        ),
    };
}

async function load(older: boolean): Promise<void> {
    if (loading.value || (older && next.value === null)) {
        return;
    }

    const current = ++generation;
    const cursor = older ? next.value : null;
    let more = false;
    loading.value = true;
    error.value = null;

    try {
        const page = await http.get(
            fullHistoryRoute.url(props.conversationId, {
                query: cursor
                    ? {
                          before_conversation: cursor.conversation,
                          ...(cursor.message
                              ? { before_message: cursor.message }
                              : {}),
                      }
                    : {},
            }),
        );

        if (current !== generation) {
            return;
        }

        name.value = page.contact?.display_name || props.contactName;
        colors.value = page.appearance?.colors ?? null;
        next.value = page.next;

        const incoming = page.segments.map(toSegment);
        const fromBottom = scroller.value
            ? scroller.value.scrollHeight - scroller.value.scrollTop
            : 0;

        if (older) {
            // The page may end inside the oldest conversation already shown: joined, one separator.
            const first = segments.value[0];
            const last = incoming.at(-1);

            if (
                first &&
                last &&
                first.conversation.id === last.conversation.id
            ) {
                first.items = [...last.items, ...first.items];
                first.starts = last.starts;
                incoming.pop();
            }

            segments.value = [...incoming, ...segments.value];
        } else {
            segments.value = incoming;
        }

        loaded.value = true;
        await nextTick();

        if (scroller.value) {
            // The first page shows the latest message; an older one keeps the reader's place.
            scroller.value.scrollTop = older
                ? scroller.value.scrollHeight - fromBottom
                : scroller.value.scrollHeight;
            // Still near the top (a short page): the observer will not fire again, so continue.
            more = next.value !== null && scroller.value.scrollTop < 200;
        }
    } catch {
        if (current === generation) {
            error.value =
                'No se pudo cargar el historial. Es posible que ya no tengas acceso a esta conversación.';
        }
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }

    if (more && current === generation) {
        void load(true);
    }
}

function reset(): void {
    generation++;
    segments.value = [];
    next.value = null;
    loaded.value = false;
    loading.value = false;
    error.value = null;
    name.value = props.contactName;
}

watch(
    () => [open.value, props.conversationId] as const,
    ([isOpen]) => {
        reset();

        if (isOpen) {
            void load(false);
        }
    },
    { immediate: true },
);

/* Reaching the top asks for the previous page. */
useIntersectionObserver(
    sentinel,
    ([entry]) => {
        if (entry?.isIntersecting && loaded.value && next.value !== null) {
            void load(true);
        }
    },
    { root: scroller, rootMargin: '200px 0px 0px 0px' },
);

const customColors = computed(() => Boolean(colors.value));
const chatStyle = computed(() => chatColorVariables(colors.value));
const onChatBackground = computed(() =>
    customColors.value
        ? 'mx-auto w-fit rounded-full bg-background px-2.5 py-0.5'
        : '',
);

/** The items of one segment grouped by calendar day. */
function days(
    segment: Segment,
): { key: string; label: string; items: ChatPreviewItem[] }[] {
    const groups: { key: string; label: string; items: ChatPreviewItem[] }[] =
        [];

    for (const item of segment.items) {
        const label = item.at ? dayLabel(item.at) : '';
        const last = groups.at(-1);

        if (last && last.label === label) {
            last.items.push(item);
        } else {
            groups.push({ key: `${label}-${item.id}`, label, items: [item] });
        }
    }

    return groups;
}

const requestCount = computed(() => segments.value.length);

/* «Corregir tamaño»: the boundary between a request and the one before it. */
const boundaryOpen = ref(false);
const boundaryFor = ref<number | null>(null);

function adjustBoundary(conversationId: number): void {
    boundaryFor.value = conversationId;
    boundaryOpen.value = true;
}

/**
 * After a correction the history is read again; when the conversation it
 * was opened from was absorbed by the other request, that one is opened.
 */
function onBoundaryAdjusted(result: RequestBoundaryResult): void {
    const absorbed =
        result.merged === 'into_previous'
            ? result.current_id
            : result.merged === 'into_current'
              ? result.previous_id
              : null;

    if (absorbed === props.conversationId && result.survivor_id !== null) {
        open.value = false;
        router.visit(showRoute.url(result.survivor_id));

        return;
    }

    reset();
    void load(false);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex h-[min(56rem,calc(100dvh-2rem))] flex-col gap-0 overflow-hidden p-0 max-sm:h-dvh max-sm:max-h-dvh max-sm:max-w-full max-sm:rounded-none sm:max-w-2xl lg:max-w-3xl"
            data-full-history
        >
            <DialogHeader class="border-b px-5 pt-5 pb-3 text-left">
                <DialogTitle class="flex items-center gap-2 pr-6 text-base">
                    <History
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate"
                        >Conversación completa · {{ name }}</span
                    >
                </DialogTitle>
                <DialogDescription class="text-xs">
                    Todas las solicitudes de este contacto que puedes ver, en
                    orden de fecha. Solo lectura: no marca mensajes como leídos.
                </DialogDescription>
            </DialogHeader>

            <div
                ref="scroller"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-3 sm:px-4"
                :class="
                    customColors ? CUSTOM_BUBBLE_CLASSES.chat : 'chat-canvas'
                "
                :style="chatStyle"
                tabindex="0"
                aria-label="Historial completo del contacto"
                :aria-busy="loading"
            >
                <div
                    v-if="!loaded && loading"
                    class="grid gap-3"
                    aria-hidden="true"
                >
                    <Skeleton class="mx-auto h-16 w-4/5 rounded-xl" />
                    <Skeleton class="h-10 w-3/5 rounded-bubble" />
                    <Skeleton class="ml-auto h-10 w-1/2 rounded-bubble" />
                    <Skeleton class="h-16 w-4/5 rounded-bubble" />
                </div>

                <div
                    v-else-if="error && !loaded"
                    class="flex h-full flex-col items-center justify-center gap-3 py-8 text-center text-sm"
                    role="alert"
                >
                    <p class="text-muted-foreground" :class="onChatBackground">
                        {{ error }}
                    </p>
                    <Button variant="outline" size="sm" @click="load(false)">
                        <RefreshCw aria-hidden="true" />
                        Reintentar
                    </Button>
                </div>

                <p
                    v-else-if="loaded && segments.length === 0"
                    class="flex h-full items-center justify-center py-8 text-sm text-muted-foreground"
                >
                    <span :class="onChatBackground"
                        >Todavía no hay mensajes.</span
                    >
                </p>

                <div v-else class="grid grid-cols-[minmax(0,1fr)] gap-2">
                    <div ref="sentinel" class="h-px" aria-hidden="true" />
                    <div
                        v-if="next !== null || (error && loaded)"
                        class="flex justify-center"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            class="rounded-full bg-background"
                            :disabled="loading"
                            data-full-history-older
                            @click="load(true)"
                        >
                            <Loader2
                                v-if="loading"
                                class="animate-spin"
                                aria-hidden="true"
                            />
                            <RefreshCw v-else-if="error" aria-hidden="true" />
                            {{
                                error
                                    ? 'No se pudo cargar. Reintentar'
                                    : 'Cargar mensajes anteriores'
                            }}
                        </Button>
                    </div>
                    <p
                        v-else-if="loaded"
                        class="text-center text-xs text-muted-foreground"
                        :class="onChatBackground"
                    >
                        Inicio del historial · {{ requestCount }}
                        {{
                            requestCount === 1
                                ? 'solicitud cargada'
                                : 'solicitudes cargadas'
                        }}
                    </p>

                    <section
                        v-for="segment in segments"
                        :key="segment.conversation.id"
                        class="grid grid-cols-[minmax(0,1fr)] gap-2"
                        :aria-label="`Solicitud del ${formatDate(segment.conversation.created_at)}`"
                        data-full-history-segment
                        :data-conversation="segment.conversation.id"
                    >
                        <!-- Where the request starts: whose it was and where it ended. -->
                        <header
                            class="my-2 grid gap-1 rounded-xl border bg-card/95 px-3 py-2 text-xs text-card-foreground shadow-(--shadow-control) backdrop-blur-sm"
                            :class="
                                segment.conversation.is_current &&
                                'ring-2 ring-primary/40'
                            "
                            data-full-history-boundary
                        >
                            <p
                                class="flex flex-wrap items-center gap-x-1.5 gap-y-1"
                            >
                                <span class="font-semibold">
                                    Solicitud ·
                                    <time
                                        :datetime="
                                            segment.conversation.created_at ??
                                            undefined
                                        "
                                        :title="
                                            formatDateTime(
                                                segment.conversation.created_at,
                                            )
                                        "
                                        >{{
                                            formatDate(
                                                segment.conversation.created_at,
                                            )
                                        }}</time
                                    >
                                </span>
                                <span
                                    class="inline-flex items-center rounded-full border px-1.5 py-px text-[0.6875rem] font-semibold whitespace-nowrap"
                                    :class="
                                        resultClasses(
                                            segment.conversation.result,
                                        )
                                    "
                                    :data-result="
                                        segment.conversation.result.value
                                    "
                                    >{{
                                        resultLabel(segment.conversation.result)
                                    }}</span
                                >
                                <span
                                    v-if="segment.conversation.is_current"
                                    class="rounded-full bg-primary-soft px-1.5 py-px text-[0.6875rem] font-semibold text-primary-soft-foreground"
                                    >Estás aquí</span
                                >
                                <Link
                                    v-else
                                    :href="
                                        showRoute.url(segment.conversation.id)
                                    "
                                    @click="open = false"
                                    class="ml-auto inline-flex items-center gap-1 rounded-md px-1 font-medium text-primary underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-8"
                                >
                                    <ExternalLink
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    Abrir
                                </Link>
                            </p>
                            <p class="min-w-0 truncate">
                                <span class="mr-1 text-muted-foreground"
                                    >Cliente:</span
                                >
                                <span
                                    class="font-medium"
                                    :class="
                                        !segment.conversation.client &&
                                        'text-muted-foreground italic'
                                    "
                                >
                                    {{ requestClient(segment.conversation) }}
                                </span>
                                <template
                                    v-for="fact in requestFacts(
                                        segment.conversation,
                                    )"
                                    :key="fact"
                                >
                                    · {{ fact }}</template
                                >
                            </p>
                            <p class="truncate text-muted-foreground">
                                {{
                                    segment.conversation.department?.name ??
                                    'Sin departamento'
                                }}
                                ·
                                {{
                                    segment.conversation.assignee
                                        ? `atendió ${segment.conversation.assignee}`
                                        : 'sin agente'
                                }}
                            </p>
                            <p
                                v-if="segment.can_adjust_boundary"
                                class="flex justify-end"
                            >
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs"
                                    :title="'Mover mensajes entre esta solicitud y la anterior'"
                                    data-full-history-adjust
                                    @click="
                                        adjustBoundary(segment.conversation.id)
                                    "
                                >
                                    <ArrowUpDown aria-hidden="true" />
                                    Corregir tamaño de la solicitud
                                </Button>
                            </p>
                        </header>

                        <p
                            v-if="segment.items.length === 0"
                            class="text-center text-xs text-muted-foreground"
                            :class="onChatBackground"
                        >
                            Sin mensajes en esta solicitud.
                        </p>
                        <ol v-else class="grid grid-cols-[minmax(0,1fr)] gap-2">
                            <template
                                v-for="day in days(segment)"
                                :key="day.key"
                            >
                                <li
                                    v-if="day.label"
                                    class="mx-auto my-1 w-fit rounded-full border bg-background/90 px-3 py-1 text-[0.6875rem] leading-none font-semibold text-muted-foreground shadow-(--shadow-control) backdrop-blur-sm"
                                >
                                    {{ day.label }}
                                </li>
                                <li
                                    v-for="item in day.items"
                                    :key="item.id"
                                    class="flex"
                                    :class="
                                        item.mine
                                            ? 'justify-end'
                                            : 'justify-start'
                                    "
                                    data-full-history-item
                                >
                                    <ChatPreviewBubble
                                        :item="item"
                                        :custom="customColors"
                                    />
                                </li>
                            </template>
                        </ol>
                    </section>
                </div>
            </div>
        </DialogContent>
    </Dialog>
    <RequestBoundaryDialog
        v-if="boundaryFor !== null"
        v-model:open="boundaryOpen"
        :conversation-id="boundaryFor"
        @adjusted="onBoundaryAdjusted"
    />
</template>
