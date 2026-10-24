<script setup lang="ts">
import { Download, RotateCw } from '@lucide/vue';
import { computed } from 'vue';
import type { LocalStatus, ThreadMessage } from '../../types';

/**
 * One message of the thread. The visitor's own messages show their
 * delivery state (Enviando, Enviado, Entregado, Leído, or Fallido with its
 * reason and "Reintentar"); the agent's show the agent's name and the files they sent,
 * which download through short-lived signed links.
 */
const props = defineProps<{
    message: ThreadMessage;
    status: LocalStatus | null;
    error: string | null;
}>();

defineEmits<{ retry: [] }>();

const mine = computed(() => props.message.direction === 'inbound');

const statusLabels: Record<LocalStatus, string> = {
    sending: 'Enviando',
    sent: 'Enviado',
    delivered: 'Entregado',
    read: 'Leído',
    failed: 'Fallido',
};

const time = computed(() => {
    if (!props.message.created_at) {
        return '';
    }

    return new Date(props.message.created_at).toLocaleTimeString('es-MX', {
        hour: '2-digit',
        minute: '2-digit',
    });
});

function size(bytes: number | null): string {
    if (!bytes) {
        return '';
    }

    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
</script>

<template>
    <li
        class="grid min-w-0 grid-cols-1 gap-1"
        :class="mine ? 'justify-items-end' : 'justify-items-start'"
        :data-direction="message.direction"
    >
        <p
            v-if="!mine && message.agent_name"
            class="px-1 text-xs text-gray-500 dark:text-gray-400"
        >
            {{ message.agent_name }}
        </p>
        <div
            class="max-w-[85%] min-w-0 rounded-2xl px-3.5 py-2 text-[15px] leading-snug wrap-anywhere whitespace-pre-wrap"
            :class="
                mine
                    ? 'rounded-br-md bg-(--cm-accent) text-(--cm-accent-fg)'
                    : 'rounded-bl-md bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100'
            "
        >
            <template v-if="message.body">{{ message.body }}</template>
            <ul
                v-if="message.attachments.length > 0"
                class="grid grid-cols-1 gap-2"
                :class="{ 'mt-2': message.body }"
            >
                <li
                    v-for="attachment in message.attachments"
                    :key="attachment.url"
                    class="min-w-0"
                >
                    <a
                        v-if="attachment.mime_type?.startsWith('image/')"
                        :href="attachment.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block"
                    >
                        <img
                            :src="attachment.url"
                            :alt="attachment.name"
                            class="max-h-48 rounded-lg object-cover"
                            loading="lazy"
                        />
                    </a>
                    <a
                        v-else
                        :href="attachment.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        download
                        class="flex min-w-0 items-center gap-2 rounded-lg bg-white/70 px-2.5 py-2 text-sm text-gray-900 underline-offset-2 hover:underline dark:bg-gray-900/60 dark:text-gray-100"
                    >
                        <Download class="size-4 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 truncate">{{
                            attachment.name
                        }}</span>
                        <span
                            v-if="attachment.size_bytes"
                            class="shrink-0 text-xs text-gray-500"
                            >{{ size(attachment.size_bytes) }}</span
                        >
                        <span class="sr-only">(descargar)</span>
                    </a>
                </li>
            </ul>
        </div>
        <p
            class="flex items-center gap-1.5 px-1 text-xs text-gray-500 dark:text-gray-400"
        >
            <span v-if="time">{{ time }}</span>
            <template v-if="mine && status">
                <span aria-hidden="true">·</span>
                <span
                    :class="{
                        'text-red-600 dark:text-red-400': status === 'failed',
                    }"
                    :data-status="status"
                    >{{
                        status === 'failed' && error
                            ? `${statusLabels.failed} · ${error}`
                            : statusLabels[status]
                    }}</span
                >
                <button
                    v-if="status === 'failed'"
                    type="button"
                    class="inline-flex min-h-8 items-center gap-1 rounded px-1.5 font-medium text-(--cm-accent-text) underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-(--cm-ring)"
                    @click="$emit('retry')"
                >
                    <RotateCw class="size-3.5" aria-hidden="true" />
                    Reintentar
                </button>
            </template>
        </p>
    </li>
</template>
