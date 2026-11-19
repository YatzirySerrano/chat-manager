<script setup lang="ts">
import { Download, FileText, RotateCw } from '@lucide/vue';
import { computed } from 'vue';
import type { LocalStatus, ThreadMessage } from '../../types';

/**
 * One message of the thread. The visitor's own messages show their
 * delivery state (Enviando, Enviado, Entregado, Leído, or Fallido with its
 * reason and "Reintentar"); the agent's show the agent's name. Files (sent
 * by either side) open through short-lived signed links: images are shown,
 * audio and video play in place, anything else downloads. A file the
 * visitor is uploading shows its name and a progress bar. The bubble colors
 * come from the widget's settings (--cm-visitor-*, --cm-agent-*).
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

/** The upload in progress, while the server has not stored it yet. */
const uploading = computed(() =>
    props.message.upload && props.message.attachments.length === 0
        ? props.message.upload
        : null,
);

const uploadPercent = computed(() =>
    Math.round((uploading.value?.progress ?? 0) * 100),
);

function size(bytes: number | null): string {
    if (!bytes) {
        return '';
    }

    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function kindOf(mime: string | null): 'image' | 'video' | 'audio' | 'file' {
    const type = (mime ?? '').toLowerCase();

    if (type.startsWith('image/')) {
        return 'image';
    }

    if (type.startsWith('video/')) {
        return 'video';
    }

    return type.startsWith('audio/') ? 'audio' : 'file';
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
            class="px-1 text-xs text-(--cm-muted)"
        >
            {{ message.agent_name }}
        </p>
        <div
            class="max-w-[85%] min-w-0 rounded-2xl px-3.5 py-2 text-[15px] leading-snug wrap-anywhere whitespace-pre-wrap"
            :class="
                mine
                    ? 'rounded-br-md bg-(--cm-visitor-bg) text-(--cm-visitor-fg)'
                    : 'rounded-bl-md bg-(--cm-agent-bg) text-(--cm-agent-fg)'
            "
        >
            <template v-if="message.body">{{ message.body }}</template>
            <div
                v-if="uploading"
                class="grid min-w-48 gap-1.5"
                :class="{ 'mt-2': message.body }"
            >
                <img
                    v-if="
                        uploading.previewUrl &&
                        uploading.file.type.startsWith('image/')
                    "
                    :src="uploading.previewUrl"
                    :alt="uploading.file.name"
                    class="max-h-48 rounded-lg object-cover opacity-80"
                />
                <span class="flex min-w-0 items-center gap-2 text-sm">
                    <FileText class="size-4 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 truncate">{{
                        uploading.file.name
                    }}</span>
                    <span class="shrink-0 text-xs opacity-80">{{
                        size(uploading.file.size)
                    }}</span>
                </span>
                <span
                    v-if="status === 'sending'"
                    class="block h-1.5 overflow-hidden rounded-full bg-current/20"
                    role="progressbar"
                    :aria-valuenow="uploadPercent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="`Subiendo ${uploading.file.name}`"
                >
                    <span
                        class="block h-full rounded-full bg-current transition-[width]"
                        :style="{ width: `${uploadPercent}%` }"
                    />
                </span>
            </div>
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
                        v-if="kindOf(attachment.mime_type) === 'image'"
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
                    <video
                        v-else-if="kindOf(attachment.mime_type) === 'video'"
                        :src="attachment.url"
                        controls
                        preload="metadata"
                        playsinline
                        class="max-h-60 w-full rounded-lg bg-black"
                        :aria-label="attachment.name"
                    />
                    <audio
                        v-else-if="kindOf(attachment.mime_type) === 'audio'"
                        :src="attachment.url"
                        controls
                        preload="metadata"
                        class="w-60 max-w-full"
                        :aria-label="attachment.name"
                    />
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
        <p class="flex items-center gap-1.5 px-1 text-xs text-(--cm-muted)">
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
                            : status === 'sending' && uploading
                              ? `${statusLabels.sending} ${uploadPercent} %`
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
