<script setup lang="ts">
import {
    Eye,
    FileText,
    Image as ImageIcon,
    MessageSquareText,
    Mic,
    Paperclip,
    RefreshCw,
    Sticker,
    Video,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import type { Component } from 'vue';
import type {
    ChatPreviewAttachment,
    ChatPreviewItem,
} from '@/components/chat-preview/types';
import { dayLabel, formatTime } from '@/components/conversations/messageFormat';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import {
    CUSTOM_BUBBLE_CLASSES,
    chatColorVariables,
} from '@/lib/chatAppearance';
import { formatDateTime } from '@/lib/format';
import type { ChatColors } from '@/types';

/**
 * Floating preview of a chat (customer conversation or internal chat): its
 * latest messages, with their author, time and a safe representation of
 * each file (an icon and its name; files are never loaded here). Looking at
 * it changes nothing: whoever shows it must only read. Closing it (Escape,
 * the close button or clicking outside) never navigates; «Abrir» opens the
 * chat normally, which is when messages count as read. With the viewer's
 * personal colors it looks like their chat: the same CSS variables and
 * bubble classes, light and dark side by side.
 */
const props = defineProps<{
    open: boolean;
    title: string;
    /** Second line under the title (status, department...). */
    subtitle?: string | null;
    items: ChatPreviewItem[];
    loading: boolean;
    error: string | null;
    hasMore: boolean;
    /** Unread messages for the viewer, shown as they are (never cleared here). */
    unread?: number;
    openLabel: string;
    /** The viewer's effective personal colors (null: the theme's). */
    colors?: ChatColors | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    open: [];
    retry: [];
}>();

const ATTACHMENT_ICONS: Record<ChatPreviewAttachment['kind'], Component> = {
    image: ImageIcon,
    sticker: Sticker,
    audio: Mic,
    video: Video,
    document: FileText,
    other: Paperclip,
};

const customColors = computed(() => Boolean(props.colors));
const chatStyle = computed(() => chatColorVariables(props.colors));

/**
 * A bubble with personal colors (a deleted message keeps the theme's look,
 * as in the chat). Only its chosen text color is guaranteed readable on it,
 * so secondary text and boxes inside use that color (currentColor) instead
 * of theme tones, like the chat's bubbles.
 */
function customBubble(item: ChatPreviewItem): boolean {
    return customColors.value && !item.deleted;
}

function secondaryText(item: ChatPreviewItem): string {
    return customBubble(item) ? '' : 'text-muted-foreground';
}

function bubbleClasses(item: ChatPreviewItem): string {
    const custom = customBubble(item);

    if (item.mine) {
        return `rounded-br-md ${custom ? CUSTOM_BUBBLE_CLASSES.sent : 'bg-primary-soft text-primary-soft-foreground'}`;
    }

    return `rounded-bl-md ${custom ? CUSTOM_BUBBLE_CLASSES.received : 'bg-background'}`;
}

/* Text placed on the chat background gets the theme's backdrop. */
const onChatBackground = computed(() =>
    customColors.value
        ? 'mx-auto w-fit rounded-full bg-background px-2.5 py-0.5'
        : '',
);

/** Items grouped by calendar day, in the order they were written. */
const days = computed(() => {
    const groups: { key: string; label: string; items: ChatPreviewItem[] }[] =
        [];

    for (const item of props.items) {
        const label = item.at ? dayLabel(item.at) : '';
        const last = groups.at(-1);

        if (last && last.label === label) {
            last.items.push(item);
        } else {
            groups.push({ key: `${label}-${item.id}`, label, items: [item] });
        }
    }

    return groups;
});

/* The latest message is the one in view, as in the chat. */
const scroller = ref<HTMLElement | null>(null);

watch(
    () => [props.open, props.items.length, props.loading] as const,
    async ([open]) => {
        if (open) {
            await nextTick();
            scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent
            class="flex max-h-[min(40rem,calc(100dvh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-md"
            data-chat-preview
        >
            <DialogHeader class="border-b px-5 pt-5 pb-3 text-left">
                <DialogTitle class="flex items-center gap-2 pr-6 text-base">
                    <Eye
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ title }}</span>
                </DialogTitle>
                <DialogDescription class="text-xs">
                    <span v-if="subtitle">{{ subtitle }} · </span>
                    Vista previa: no marca los mensajes como leídos.
                    <span v-if="unread && unread > 0" class="font-medium">
                        {{
                            unread === 1
                                ? '1 mensaje sin leer.'
                                : `${unread} mensajes sin leer.`
                        }}
                    </span>
                </DialogDescription>
            </DialogHeader>

            <div
                ref="scroller"
                class="min-h-40 flex-1 overflow-y-auto px-4 py-3"
                :class="
                    customColors ? CUSTOM_BUBBLE_CLASSES.chat : 'bg-muted/30'
                "
                :style="chatStyle"
                tabindex="0"
                aria-label="Mensajes recientes"
                aria-live="polite"
                :aria-busy="loading"
            >
                <div
                    v-if="loading && items.length === 0"
                    class="grid gap-3"
                    aria-hidden="true"
                >
                    <Skeleton class="h-10 w-3/4" />
                    <Skeleton class="ml-auto h-10 w-2/3" />
                    <Skeleton class="h-14 w-4/5" />
                </div>

                <div
                    v-else-if="error"
                    class="flex h-full flex-col items-center justify-center gap-3 py-8 text-center text-sm"
                    role="alert"
                >
                    <p class="text-muted-foreground" :class="onChatBackground">
                        {{ error }}
                    </p>
                    <Button variant="outline" size="sm" @click="emit('retry')">
                        <RefreshCw aria-hidden="true" />
                        Reintentar
                    </Button>
                </div>

                <p
                    v-else-if="items.length === 0"
                    class="flex h-full items-center justify-center py-8 text-sm text-muted-foreground"
                >
                    <span :class="onChatBackground"
                        >Todavía no hay mensajes.</span
                    >
                </p>

                <ol v-else class="grid gap-2">
                    <li
                        v-if="hasMore"
                        class="text-center text-xs text-muted-foreground"
                        :class="onChatBackground"
                    >
                        Se muestran los mensajes más recientes.
                    </li>
                    <template v-for="day in days" :key="day.key">
                        <li
                            v-if="day.label"
                            class="sticky top-0 z-10 mx-auto w-fit rounded-full bg-background/90 px-2 py-0.5 text-xs text-muted-foreground shadow-xs"
                        >
                            {{ day.label }}
                        </li>
                        <li
                            v-for="item in day.items"
                            :key="item.id"
                            class="flex"
                            :class="item.mine ? 'justify-end' : 'justify-start'"
                            data-chat-preview-item
                        >
                            <article
                                class="max-w-[85%] min-w-0 rounded-2xl border px-3 py-2 text-sm shadow-xs"
                                :class="bubbleClasses(item)"
                            >
                                <header
                                    class="flex items-baseline justify-between gap-3 text-xs"
                                >
                                    <span class="truncate font-semibold">{{
                                        item.author
                                    }}</span>
                                    <time
                                        v-if="item.at"
                                        class="shrink-0 tabular-nums"
                                        :class="secondaryText(item)"
                                        :datetime="item.at"
                                        :title="formatDateTime(item.at)"
                                        >{{ formatTime(item.at) }}</time
                                    >
                                </header>
                                <p
                                    v-if="item.note"
                                    class="mt-0.5 text-xs italic"
                                    :class="secondaryText(item)"
                                >
                                    {{ item.note }}
                                </p>
                                <ul
                                    v-if="item.attachments.length > 0"
                                    class="mt-1 grid gap-1"
                                >
                                    <li
                                        v-for="(
                                            attachment, index
                                        ) in item.attachments"
                                        :key="index"
                                        class="flex min-w-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs"
                                        :class="
                                            customBubble(item)
                                                ? 'border border-current/30'
                                                : 'bg-muted/60'
                                        "
                                    >
                                        <component
                                            :is="
                                                ATTACHMENT_ICONS[
                                                    attachment.kind
                                                ]
                                            "
                                            class="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span class="truncate">{{
                                            attachment.label
                                        }}</span>
                                    </li>
                                </ul>
                                <p
                                    v-if="item.text"
                                    class="mt-0.5 [overflow-wrap:anywhere] whitespace-pre-line"
                                >
                                    {{ item.text }}
                                </p>
                            </article>
                        </li>
                    </template>
                </ol>
            </div>

            <DialogFooter
                class="flex-row justify-end gap-2 border-t px-5 py-3 sm:justify-end"
            >
                <Button variant="ghost" @click="emit('update:open', false)">
                    Cerrar
                </Button>
                <Button @click="emit('open')">
                    <MessageSquareText aria-hidden="true" />
                    {{ openLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
