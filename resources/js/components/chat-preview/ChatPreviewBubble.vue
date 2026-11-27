<script setup lang="ts">
import {
    Eye,
    FileText,
    Image as ImageIcon,
    Mic,
    Paperclip,
    Sticker,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import LinkPreviewCard from '@/components/chat/LinkPreviewCard.vue';
import LocationCard from '@/components/chat/LocationCard.vue';
import MediaViewer from '@/components/conversations/MediaViewer.vue';
import MediaLightbox from '@/components/MediaLightbox.vue';
import type {
    ChatPreviewAttachment,
    ChatPreviewItem,
} from '@/components/chat-preview/types';
import { formatTime } from '@/components/conversations/messageFormat';
import WhatsAppText from '@/components/WhatsAppText.vue';
import { CUSTOM_BUBBLE_CLASSES } from '@/lib/chatAppearance';
import type { ViewerItem } from '@/lib/conversations/media';
import { formatDateTime } from '@/lib/format';

/**
 * One read-only message bubble of a chat preview (ChatPreviewDialog and
 * «Conversación completa»): its author, time and files. Images, video,
 * audio and PDFs are shown in place when the chat gives their address,
 * anything else as an icon and its name. A picture opens enlarged right
 * here (MediaLightbox) and a PDF in the chat's viewer, never in another
 * window; a click with a modifier key keeps the link's own behaviour.
 * With personal colors (`custom`) it uses the chat's bubble classes and
 * only its own text color inside, like the chat's bubbles.
 */
const props = defineProps<{
    item: ChatPreviewItem;
    /** The viewer chose personal chat colors. */
    custom?: boolean;
}>();

const ATTACHMENT_ICONS: Record<ChatPreviewAttachment['kind'], Component> = {
    image: ImageIcon,
    sticker: Sticker,
    audio: Mic,
    video: Video,
    pdf: FileText,
    document: FileText,
    other: Paperclip,
};

/** Files that failed to load fall back to their icon and name. */
const failed = ref(new Set<string>());

const lightbox = ref<{ src: string; name: string } | null>(null);
const lightboxOpen = computed({
    get: () => lightbox.value !== null,
    set: (value: boolean) => {
        if (!value) {
            lightbox.value = null;
        }
    },
});

function plainClick(event: MouseEvent): boolean {
    return !(
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey ||
        event.button !== 0
    );
}

function openImage(event: MouseEvent, attachment: ChatPreviewAttachment): void {
    const src = attachment.href ?? attachment.src;

    if (!src || !plainClick(event)) {
        return;
    }

    event.preventDefault();
    lightbox.value = { src, name: attachment.label };
}

const pdf = ref<ViewerItem | null>(null);
const pdfOpen = computed({
    get: () => pdf.value !== null,
    set: (value: boolean) => {
        if (!value) {
            pdf.value = null;
        }
    },
});

function openPdf(
    event: MouseEvent,
    attachment: ChatPreviewAttachment,
    index: number,
): void {
    if (!attachment.src || !plainClick(event)) {
        return;
    }

    event.preventDefault();
    pdf.value = {
        id: props.item.id * 100 + index,
        kind: 'pdf',
        src: attachment.src,
        downloadUrl: attachment.downloadUrl ?? null,
        name: attachment.label,
        caption: props.item.text,
        author: props.item.author,
        at: props.item.at,
    };
}

function shown(attachment: ChatPreviewAttachment): boolean {
    return (
        Boolean(attachment.src) &&
        ['image', 'sticker', 'video', 'audio', 'pdf'].includes(
            attachment.kind,
        ) &&
        !failed.value.has(attachment.src as string)
    );
}

function markFailed(attachment: ChatPreviewAttachment): void {
    if (attachment.src) {
        failed.value = new Set(failed.value).add(attachment.src);
    }
}

/* A deleted message keeps the theme's look, as in the chat. */
const customBubble = computed(
    () => Boolean(props.custom) && !props.item.deleted,
);
const secondaryText = computed(() =>
    customBubble.value ? '' : 'text-muted-foreground',
);
const bubbleClasses = computed(() => {
    if (props.item.mine) {
        return `rounded-br-sm ${customBubble.value ? CUSTOM_BUBBLE_CLASSES.sent : 'bg-bubble-out text-bubble-out-foreground'}`;
    }

    return `rounded-bl-sm border border-border-strong/60 ${customBubble.value ? CUSTOM_BUBBLE_CLASSES.received : 'bg-bubble-in text-bubble-in-foreground'}`;
});
</script>

<template>
    <article
        class="max-w-[85%] min-w-0 rounded-bubble px-3 py-1.5 text-sm/snug"
        :class="bubbleClasses"
    >
        <header class="flex items-baseline justify-between gap-3 text-xs">
            <span class="truncate font-semibold">{{ item.author }}</span>
            <time
                v-if="item.at"
                class="shrink-0 tabular-nums"
                :class="secondaryText"
                :datetime="item.at"
                :title="formatDateTime(item.at)"
                >{{ formatTime(item.at) }}</time
            >
        </header>
        <p
            v-if="item.note"
            class="mt-0.5 text-xs italic"
            :class="secondaryText"
        >
            {{ item.note }}
        </p>
        <ul v-if="item.attachments.length > 0" class="mt-1 grid gap-1">
            <li
                v-for="(attachment, index) in item.attachments"
                :key="index"
                class="min-w-0"
            >
                <a
                    v-if="
                        shown(attachment) &&
                        (attachment.kind === 'image' ||
                            attachment.kind === 'sticker')
                    "
                    :href="attachment.href ?? attachment.src ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="block w-fit cursor-zoom-in overflow-hidden rounded-lg"
                    :aria-label="`Ver ${attachment.label} en grande`"
                    @click="openImage($event, attachment)"
                >
                    <img
                        :src="attachment.src ?? ''"
                        :alt="attachment.label"
                        loading="lazy"
                        decoding="async"
                        class="w-auto max-w-full rounded-lg object-contain"
                        :class="
                            attachment.kind === 'sticker'
                                ? 'max-h-32'
                                : 'max-h-80'
                        "
                        @error="markFailed(attachment)"
                    />
                </a>
                <a
                    v-else-if="shown(attachment) && attachment.kind === 'pdf'"
                    :href="attachment.src ?? undefined"
                    target="_blank"
                    rel="noopener"
                    class="group/pdf block w-64 max-w-full overflow-hidden rounded-lg border outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        customBubble ? 'border-current/30' : 'bg-background'
                    "
                    :aria-label="`Ver ${attachment.label}`"
                    @click="openPdf($event, attachment, index)"
                >
                    <span
                        class="relative block h-36 overflow-hidden bg-white"
                        aria-hidden="true"
                    >
                        <!-- First page; clicks go to the card. -->
                        <iframe
                            :src="`${attachment.src}#page=1&toolbar=0&navpanes=0&scrollbar=0&view=FitH`"
                            title=""
                            tabindex="-1"
                            loading="lazy"
                            class="pointer-events-none h-[220%] w-full origin-top-left"
                        />
                        <span
                            class="absolute inset-0 grid place-items-center bg-black/0 transition-colors group-hover/pdf:bg-black/25"
                        >
                            <Eye
                                class="size-6 text-white opacity-0 drop-shadow transition-opacity group-hover/pdf:opacity-100"
                            />
                        </span>
                    </span>
                    <span
                        class="flex min-w-0 items-center gap-2 px-2 py-1.5 text-xs"
                    >
                        <span
                            class="shrink-0 rounded bg-red-600 px-1.5 py-0.5 text-[0.625rem] font-bold tracking-wide text-white"
                            >PDF</span
                        >
                        <span class="min-w-0 flex-1 truncate font-medium">{{
                            attachment.label
                        }}</span>
                    </span>
                </a>
                <video
                    v-else-if="shown(attachment) && attachment.kind === 'video'"
                    :src="attachment.src ?? ''"
                    :aria-label="attachment.label"
                    controls
                    playsinline
                    preload="metadata"
                    class="max-h-80 w-full max-w-md rounded-lg bg-black"
                    @error="markFailed(attachment)"
                />
                <audio
                    v-else-if="shown(attachment) && attachment.kind === 'audio'"
                    :src="attachment.src ?? ''"
                    :aria-label="attachment.label"
                    controls
                    preload="none"
                    class="h-10 w-64 max-w-full"
                    @error="markFailed(attachment)"
                />
                <span
                    v-else
                    class="flex min-w-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs"
                    :class="
                        customBubble
                            ? 'border border-current/30'
                            : 'bg-muted/60'
                    "
                >
                    <component
                        :is="ATTACHMENT_ICONS[attachment.kind]"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ attachment.label }}</span>
                </span>
            </li>
        </ul>
        <LocationCard
            v-if="item.location"
            class="mt-1"
            :name="item.location.name"
            :address="item.location.address"
            :latitude="item.location.latitude"
            :longitude="item.location.longitude"
            :live="item.location.live ?? false"
            :website="item.location.website ?? null"
            :comment="item.location.comment ?? null"
            :custom="customBubble"
        />
        <WhatsAppText
            v-if="item.text"
            :text="item.text"
            links
            :plain="!item.formatted"
            :inherit-color="customBubble"
            class="mt-0.5 block [overflow-wrap:anywhere] whitespace-pre-line"
        />
        <LinkPreviewCard
            v-if="item.link"
            :url="item.link.url"
            :endpoint="item.link.endpoint"
            :custom="customBubble"
            class="mt-1"
        />
    </article>
    <MediaViewer v-if="pdf" v-model:open="pdfOpen" :items="[pdf]" />
    <MediaLightbox
        v-if="lightbox"
        v-model:open="lightboxOpen"
        :src="lightbox.src"
        :name="lightbox.name"
    />
</template>
