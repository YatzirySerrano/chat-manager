<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import ChatPreviewDialog from '@/components/chat-preview/ChatPreviewDialog.vue';
import type {
    ChatPreviewAppearance,
    ChatPreviewItem,
} from '@/components/chat-preview/types';
import { useChannel } from '@/composables/useChannel';
import { preview as previewRoute } from '@/routes/team-chat/threads';
import type { TeamMessage, TeamThreadSummary } from '@/types/team-chat';

/**
 * The preview of an internal chat (long press, right click or «Vista
 * previa» on its row), with the same window as the inbox preview. It only
 * reads (GET team-chat.threads.preview): the read position, the unread
 * counters and the notifications stay as they were, and no typing
 * indicator is sent. While open it follows the thread's channel.
 */
const props = defineProps<{ thread: TeamThreadSummary | null }>();

const emit = defineEmits<{ close: []; open: [id: number] }>();

type PreviewPage = {
    data: TeamMessage[];
    has_more: boolean;
    /** The viewer's own effective chat colors, as in the chat. */
    appearance: ChatPreviewAppearance;
};

const http = useHttp<Record<string, never>, PreviewPage>({});
const messages = ref<TeamMessage[]>([]);
const hasMore = ref(false);
const appearance = ref<ChatPreviewAppearance | null>(null);
const error = ref<string | null>(null);
let generation = 0;

async function load(): Promise<void> {
    const id = props.thread?.id;

    if (!id) {
        return;
    }

    const current = ++generation;
    error.value = null;

    try {
        const page = await http.get(previewRoute.url(id));

        if (current === generation) {
            messages.value = page.data;
            hasMore.value = page.has_more;
            appearance.value = page.appearance;
        }
    } catch {
        if (current === generation) {
            error.value =
                'No se pudo cargar la vista previa. Es posible que ya no participes en este chat.';
        }
    }
}

watch(
    () => props.thread?.id ?? null,
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

const reload = useDebounceFn(() => void load(), 400);

useChannel(
    () => (props.thread ? `team-chat.threads.${props.thread.id}` : null),
    {
        'message.created': () => void reload(),
        'thread.changed': () => void reload(),
    },
);

const items = computed<ChatPreviewItem[]>(() =>
    messages.value.map((message) => ({
        id: message.id,
        author:
            message.kind === 'system'
                ? 'Chat interno'
                : message.is_mine
                  ? 'Tú'
                  : (message.author?.name ?? 'Persona eliminada'),
        mine: message.is_mine,
        text: message.deleted
            ? null
            : message.kind === 'system'
              ? message.system_text
              : message.body,
        attachments: message.deleted
            ? []
            : message.attachments.map((attachment) => ({
                  kind: attachment.kind,
                  label: attachment.name,
                  src:
                      attachment.kind === 'image'
                          ? (attachment.thumb_url ?? attachment.preview_url)
                          : (attachment.stream_url ?? null),
                  href: attachment.preview_url,
              })),
        at: message.created_at,
        deleted: message.deleted,
        note: message.deleted
            ? 'Mensaje eliminado'
            : message.edited
              ? 'Editado'
              : null,
    })),
);
</script>

<template>
    <ChatPreviewDialog
        :open="thread !== null"
        :title="thread?.title ?? ''"
        :subtitle="
            thread?.type === 'group'
                ? `Grupo · ${thread.participants_count} participantes`
                : 'Chat interno'
        "
        :items="items"
        :loading="http.processing"
        :error="error"
        :has-more="hasMore"
        :colors="appearance?.colors ?? null"
        :unread="thread?.unread ?? 0"
        open-label="Abrir chat"
        @update:open="(value) => !value && emit('close')"
        @open="thread && emit('open', thread.id)"
        @retry="load"
    />
</template>
