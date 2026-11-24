<script setup lang="ts">
import { Download, FileText, RotateCw, X } from "@lucide/vue";
import { computed, onBeforeUnmount, ref, watch } from "vue";
import type { LocalStatus, ThreadMessage } from "../../types";
import AgentAvatar from "./AgentAvatar.vue";

/**
 * One message of the thread. The visitor's own messages show their
 * delivery state (Enviando, Enviado, Entregado, Leído, or Fallido with its
 * reason and "Reintentar"). The first message of each group from the team
 * says who wrote it: the agent's name and photo (initials without one), or
 * «Asistente virtual» with the bot icon for automatic messages. Files (sent
 * by either side) open through short-lived signed links: images are shown,
 * audio and video play in place, anything else downloads. A file the
 * visitor is uploading shows its name and a progress bar. The bubble colors
 * come from the widget's settings (--cm-visitor-*, --cm-agent-*).
 */
const props = defineProps<{
    message: ThreadMessage;
    status: LocalStatus | null;
    error: string | null;
    /** First message of a group from the same author: shows who wrote it. */
    showAuthor?: boolean;
}>();

defineEmits<{ retry: [] }>();

const mine = computed(() => props.message.direction === "inbound");

const author = computed(() => {
    if (mine.value || !props.showAuthor) {
        return null;
    }

    if (props.message.assistant) {
        return { name: "Asistente virtual", bot: true };
    }

    return props.message.agent_name
        ? { name: props.message.agent_name, bot: false }
        : null;
});

const statusLabels: Record<LocalStatus, string> = {
    sending: "Enviando",
    sent: "Enviado",
    delivered: "Entregado",
    read: "Leído",
    failed: "Fallido",
};

const time = computed(() => {
    if (!props.message.created_at) {
        return "";
    }

    return new Date(props.message.created_at).toLocaleTimeString("es-MX", {
        hour: "2-digit",
        minute: "2-digit",
    });
});

/*
 * A picture opens enlarged inside the widget, never in another window; a
 * click with a modifier key keeps the link's own behaviour. Escape closes it.
 */
const enlarged = ref<{ url: string; name: string } | null>(null);

function enlarge(event: MouseEvent, url: string, name: string): void {
    if (
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.button !== 0
    ) {
        return;
    }

    event.preventDefault();
    enlarged.value = { url, name };
}

function closeOnEscape(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        enlarged.value = null;
    }
}

watch(enlarged, (value) => {
    if (value) {
        window.addEventListener("keydown", closeOnEscape);
    } else {
        window.removeEventListener("keydown", closeOnEscape);
    }
});

onBeforeUnmount(() => window.removeEventListener("keydown", closeOnEscape));

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
        return "";
    }

    return bytes >= 1048576
        ? `${(bytes / 1048576).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function kindOf(mime: string | null): "image" | "video" | "audio" | "file" {
    const type = (mime ?? "").toLowerCase();

    if (type.startsWith("image/")) {
        return "image";
    }

    if (type.startsWith("video/")) {
        return "video";
    }

    return type.startsWith("audio/") ? "audio" : "file";
}
</script>

<template>
    <li
        class="grid min-w-0 grid-cols-1 gap-1"
        :class="mine ? 'justify-items-end' : 'justify-items-start'"
        :data-direction="message.direction"
    >
        <p
            v-if="author"
            class="flex min-w-0 items-center gap-1.5 px-1 text-xs text-(--cm-muted)"
            data-author
        >
            <AgentAvatar
                :name="author.name"
                :url="author.bot ? null : message.agent_avatar_url"
                :bot="author.bot"
                class="size-6 bg-(--cm-agent-bg) text-[0.625rem] text-(--cm-agent-fg)"
            />
            <span class="truncate font-medium">{{ author.name }}</span>
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
                        class="block cursor-zoom-in"
                        :aria-label="`Ver ${attachment.name} en grande`"
                        @click="
                            enlarge($event, attachment.url, attachment.name)
                        "
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
                        status === "failed" && error
                            ? `${statusLabels.failed} · ${error}`
                            : status === "sending" && uploading
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
        <Teleport to="body">
            <div
                v-if="enlarged"
                class="fixed inset-0 z-50 flex flex-col bg-black/90"
                role="dialog"
                aria-modal="true"
                :aria-label="enlarged.name"
                @click.self="enlarged = null"
            >
                <div
                    class="flex shrink-0 items-center gap-1 px-3 py-2 text-white"
                >
                    <span class="min-w-0 flex-1 truncate text-sm">{{
                        enlarged.name
                    }}</span>
                    <a
                        :href="enlarged.url"
                        download
                        class="grid size-10 place-items-center rounded-full hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-white"
                        :aria-label="`Descargar ${enlarged.name}`"
                    >
                        <Download class="size-5" aria-hidden="true" />
                    </a>
                    <button
                        type="button"
                        class="grid size-10 place-items-center rounded-full hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-white"
                        aria-label="Cerrar imagen"
                        @click="enlarged = null"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </button>
                </div>
                <div
                    class="flex min-h-0 flex-1 items-center justify-center p-3"
                    @click.self="enlarged = null"
                >
                    <img
                        :src="enlarged.url"
                        :alt="enlarged.name"
                        class="max-h-full max-w-full object-contain"
                    />
                </div>
            </div>
        </Teleport>
    </li>
</template>
