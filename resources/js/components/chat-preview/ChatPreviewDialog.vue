<script setup lang="ts">
import { Eye, MessageSquareText, RefreshCw } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ChatPreviewBubble from '@/components/chat-preview/ChatPreviewBubble.vue';
import type { ChatPreviewItem } from '@/components/chat-preview/types';
import { dayLabel } from '@/components/conversations/messageFormat';
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
import type { ChatColors } from '@/types';

/**
 * Floating preview of a chat (customer conversation or internal chat): its
 * latest messages, with their author, time and their files: images, video
 * and audio are shown in place when the chat gives their address, anything
 * else as an icon and its name. Looking at
 * it changes nothing: whoever shows it must only read. Closing it (Escape,
 * the close button or clicking outside) never navigates; «Abrir» opens the
 * chat normally, which is when messages count as read. With the viewer's
 * personal colors it looks like their chat: the same CSS variables and
 * bubble classes, light and dark side by side. Links in the text open in
 * a new tab, with the same preview card as the chat, and a shared location
 * is drawn as a place with «Abrir en Google Maps».
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

const customColors = computed(() => Boolean(props.colors));
const chatStyle = computed(() => chatColorVariables(props.colors));

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
            class="flex max-h-[min(56rem,calc(100dvh-2rem))] flex-col gap-0 overflow-y-auto p-0 sm:max-w-2xl lg:max-w-3xl"
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

            <!-- Optional facts about the previewed chat (the customer
                 conversations use it; the internal chat does not). -->
            <slot name="summary" />

            <div
                ref="scroller"
                class="min-h-40 flex-1 overflow-y-auto px-4 py-3"
                :class="
                    customColors ? CUSTOM_BUBBLE_CLASSES.chat : 'chat-canvas'
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
                    <Skeleton class="h-10 w-3/5 rounded-bubble" />
                    <Skeleton class="ml-auto h-10 w-1/2 rounded-bubble" />
                    <Skeleton class="h-16 w-4/5 rounded-bubble" />
                    <Skeleton class="ml-auto h-10 w-2/5 rounded-bubble" />
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
                    class="flex h-full items-center py-8 text-sm text-muted-foreground"
                >
                    <span :class="onChatBackground"
                        >Todavía no hay mensajes.</span
                    >
                </p>

                <ol v-else class="grid grid-cols-[minmax(0,1fr)] gap-2">
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
                            class="mx-auto my-1 w-fit rounded-full border bg-background/90 px-3 py-1 text-[0.6875rem] leading-none font-semibold text-muted-foreground shadow-(--shadow-control) backdrop-blur-sm"
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
                            <ChatPreviewBubble
                                :item="item"
                                :custom="customColors"
                            />
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
