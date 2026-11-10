<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import ChatPreviewDialog from '@/components/chat-preview/ChatPreviewDialog.vue';
import type {
    ChatPreviewAppearance,
    ChatPreviewAttachment,
    ChatPreviewItem,
} from '@/components/chat-preview/types';
import {
    previewText,
    typeLabel,
} from '@/components/conversations/messageFormat';
import { useChannel } from '@/composables/useChannel';
import { preview as previewRoute } from '@/routes/conversations';
import type { ConversationMessage, ConversationSummary } from '@/types';

/**
 * The inbox preview of one conversation (long press, right click or
 * «Vista previa» on its row). It only reads (GET conversations.preview):
 * the chat is not opened, so the read marker, the unread counter, the
 * notifications and the conversation itself stay as they were. While it is
 * open it follows the conversation's channel and reads the latest messages
 * again when a new one arrives.
 */
const props = defineProps<{
    conversation: ConversationSummary | null;
    /** The row's unread counter, shown untouched. */
    unread: number;
}>();

const emit = defineEmits<{
    close: [];
    open: [id: number];
}>();

type PreviewPage = {
    messages: ConversationMessage[];
    has_more: boolean;
    /** The viewer's own effective chat colors, as in the chat. */
    appearance: ChatPreviewAppearance;
};

const http = useHttp<Record<string, never>, PreviewPage>({});
const messages = ref<ConversationMessage[]>([]);
const hasMore = ref(false);
const appearance = ref<ChatPreviewAppearance | null>(null);
const error = ref<string | null>(null);
let generation = 0;

async function load(): Promise<void> {
    const id = props.conversation?.id;

    if (!id) {
        return;
    }

    const current = ++generation;
    error.value = null;

    try {
        const page = await http.get(previewRoute.url(id));

        if (current === generation) {
            messages.value = page.messages;
            hasMore.value = page.has_more;
            appearance.value = page.appearance;
        }
    } catch {
        if (current === generation) {
            error.value =
                'No se pudo cargar la vista previa. Es posible que ya no tengas acceso a esta conversación.';
        }
    }
}

watch(
    () => props.conversation?.id ?? null,
    (id) => {
        messages.value = [];
        hasMore.value = false;
        appearance.value = null;

        if (id !== null) {
            void load();
        }
    },
    { immediate: true },
);

/* A message arriving while it is open shows up here too. */
const reload = useDebounceFn(() => void load(), 400);

useChannel(
    () =>
        props.conversation ? `conversations.${props.conversation.id}` : null,
    {
        '.message.recorded': () => void reload(),
        '.message.status': () => void reload(),
    },
);

const contactName = computed(
    () =>
        props.conversation?.contact.display_name ||
        props.conversation?.contact.name ||
        'Cliente',
);

function attachmentKind(type: string): ChatPreviewAttachment['kind'] {
    return (
        ['image', 'sticker', 'audio', 'video', 'document'] as const
    ).includes(type as never)
        ? (type as ChatPreviewAttachment['kind'])
        : 'other';
}

function author(message: ConversationMessage): string {
    if (message.direction === 'inbound') {
        return contactName.value;
    }

    if (message.automatic) {
        return 'Mensaje automático';
    }

    return (
        message.sender?.name ??
        message.imported?.sender_name ??
        message.integration?.name ??
        'Equipo'
    );
}

const items = computed<ChatPreviewItem[]>(() =>
    messages.value.map((message) => {
        const media = ['image', 'sticker', 'audio', 'video', 'document'];
        const attachments: ChatPreviewAttachment[] = message.attachments.map(
            (attachment) => ({
                kind: attachmentKind(message.type),
                label: attachment.file_name ?? typeLabel(message.type),
            }),
        );

        if (attachments.length === 0 && media.includes(message.type)) {
            attachments.push({
                kind: attachmentKind(message.type),
                label: typeLabel(message.type),
            });
        }

        const text =
            message.type === 'text' || media.includes(message.type)
                ? (message.body?.trim() ?? '') || null
                : previewText(message);

        return {
            id: message.id,
            author: author(message),
            mine: message.direction === 'outbound',
            text,
            attachments,
            at: message.sent_at ?? message.created_at,
            note:
                message.status === 'failed'
                    ? 'No se pudo entregar'
                    : message.template
                      ? `Plantilla ${message.template.name}`
                      : null,
        };
    }),
);
</script>

<template>
    <ChatPreviewDialog
        :open="conversation !== null"
        :title="contactName"
        :subtitle="conversation?.status_label ?? null"
        :items="items"
        :loading="http.processing"
        :error="error"
        :has-more="hasMore"
        :colors="appearance?.colors ?? null"
        :unread="unread"
        open-label="Abrir conversación"
        @update:open="(value) => !value && emit('close')"
        @open="conversation && emit('open', conversation.id)"
        @retry="load"
    />
</template>
