<script setup lang="ts">
import {
    Download,
    ExternalLink,
    FileText,
    FileWarning,
    ImageOff,
    Play,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import { CUSTOM_CHAT_COLORS } from '@/lib/chatAppearance';
import { formatFileSize } from '@/lib/format';
import type { MessageAttachment } from '@/types';

/**
 * One file of a message, always loaded through the authorized attachment
 * route (never a public URL). Images show inline, audio and video get the
 * browser's players and documents a card to open or download them.
 */
const props = withDefaults(
    defineProps<{
        attachment: MessageAttachment;
        /** Name of the conversation's channel the file came from. */
        channelLabel?: string;
        /**
         * A WhatsApp sticker: shown at sticker size, without the image frame.
         * The WebP is served as received, so an animated one keeps moving.
         */
        sticker?: { animated: boolean } | null;
    }>(),
    { channelLabel: 'el canal', sticker: null },
);

const broken = ref(false);

/**
 * What the bubble paints: the small preview when the server offers one (a
 * sticker is always its own file, so an animated one keeps moving). The
 * original only travels when the image is opened. If the preview fails, the
 * original is tried once before giving up.
 */
const previewFailed = ref(false);
const loaded = ref(false);
const imageSource = computed(() =>
    props.sticker || previewFailed.value || !props.attachment.thumb_url
        ? props.attachment.url
        : props.attachment.thumb_url,
);

function onImageError(): void {
    if (imageSource.value !== props.attachment.url) {
        previewFailed.value = true;

        return;
    }

    broken.value = true;
}

/**
 * Size of the box, known before the file arrives when the image was already
 * measured: the timeline does not jump while pictures load.
 */
const intrinsic = computed(() => {
    const { width, height } = props.attachment;

    return !props.sticker && width && height ? { width, height } : null;
});

/**
 * A video downloads nothing until someone asks to play it: not even its
 * metadata, which for a long timeline meant one request per video.
 */
const playing = ref(false);
const stickerLabel = computed(() =>
    props.sticker?.animated ? 'Sticker animado' : 'Sticker',
);

/**
 * Inside a bubble with personal colors only its text color is guaranteed to
 * contrast, so muted text and translucent fills follow it (currentColor).
 */
const customColors = inject(
    CUSTOM_CHAT_COLORS,
    computed(() => false),
);
const mutedText = computed(() =>
    customColors.value ? '' : 'text-muted-foreground',
);
const iconButton = computed(() =>
    customColors.value
        ? 'hover:bg-current/10'
        : 'text-muted-foreground hover:bg-accent hover:text-foreground',
);

/*
 * Types the attachment route serves inline (MessageAttachmentController);
 * any other file is always a download, so it gets the document card.
 */
const INLINE_IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const INLINE_AUDIO = [
    'audio/aac',
    'audio/mp4',
    'audio/mpeg',
    'audio/amr',
    'audio/ogg',
    'audio/opus',
    // WhatsApp voice notes (Ogg Opus) may be detected under these names.
    'video/ogg',
    'application/ogg',
];
const INLINE_VIDEO = ['video/mp4', 'video/3gpp'];

const kind = computed(() => {
    const mime = props.attachment.mime_type.toLowerCase();

    if (INLINE_IMAGES.includes(mime)) {
        return 'image';
    }

    if (INLINE_AUDIO.includes(mime)) {
        return 'audio';
    }

    if (INLINE_VIDEO.includes(mime)) {
        return 'video';
    }

    return 'document';
});

const name = computed(() => props.attachment.file_name ?? 'Archivo adjunto');

/**
 * The reason a customer's file was given up (DownloadInboundMedia), so a
 * file the channel no longer keeps is not confused with one Chat Manager
 * refused or with a connection problem.
 */
const UNAVAILABLE_DETAILS: Record<string, (channel: string) => string> = {
    expired: (channel) =>
        `${channel} ya no conserva este archivo o no permitió descargarlo.`,
    too_large: () =>
        'El archivo recibido supera el tamaño que Chat Manager acepta para este tipo de mensaje.',
    type_not_allowed: () =>
        'El contenido del archivo recibido no corresponde a un tipo permitido para este mensaje.',
    empty: () => 'El archivo recibido estaba vacío.',
    credentials_rejected: (channel) =>
        `${channel} rechazó la descarga: revisa las credenciales de la conexión.`,
    credentials_missing: () =>
        'La conexión no tiene credenciales para descargar el archivo.',
    download_failed: (channel) =>
        `No se pudo descargar el archivo desde ${channel} después de varios intentos.`,
};

/**
 * Why the file cannot be shown. Without a stored copy there is no link at
 * all: a history import whose file never arrived, or the file storage not
 * answering, say so instead of pointing to a file that is not there.
 */
const unavailable = computed<{ title: string; detail: string | null }>(() => {
    const { status, error_code: code } = props.attachment;

    if (status === 'unavailable' && code === 'historical_missing') {
        return {
            title: 'Archivo histórico no disponible',
            detail: `${name.value}: el historial importado no incluía este archivo.`,
        };
    }

    if (status === 'unavailable' && code === 'historical_ambiguous') {
        return {
            title: 'Archivo histórico no disponible',
            detail: `${name.value}: había varios archivos con este nombre en los ZIP del historial y no se pudo saber cuál era.`,
        };
    }

    if (status === 'unavailable' && code === 'zip_entry_invalid') {
        return {
            title: 'Archivo histórico no disponible',
            detail: `${name.value}: el archivo estaba dañado dentro del ZIP del historial.`,
        };
    }

    if (code === 'storage_unavailable') {
        return {
            title: 'Archivo no disponible',
            detail: 'El almacenamiento de archivos no respondió. Inténtalo más tarde o avisa a un administrador.',
        };
    }

    if (status === 'unavailable') {
        return {
            title: 'Archivo no disponible',
            detail:
                UNAVAILABLE_DETAILS[code ?? '']?.(props.channelLabel) ??
                `No se pudo descargar el archivo desde ${props.channelLabel}.`,
        };
    }

    return {
        title: 'Archivo en camino',
        detail: `El archivo todavía se está descargando desde ${props.channelLabel}.`,
    };
});
const size = computed(() => formatFileSize(props.attachment.size_bytes));
const opensInline = computed(
    () => props.attachment.mime_type === 'application/pdf',
);
</script>

<template>
    <div class="max-w-full min-w-0" data-layout-box="attachment">
        <div
            v-if="!attachment.available"
            class="flex items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-xs"
            :class="mutedText"
            :data-attachment-unavailable="
                attachment.error_code ?? attachment.status ?? 'pending'
            "
        >
            <FileWarning class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0">
                <span class="block font-medium">{{ unavailable.title }}</span>
                <span v-if="unavailable.detail" class="block">{{
                    unavailable.detail
                }}</span>
            </span>
        </div>

        <template v-else-if="kind === 'image'">
            <a
                v-if="!broken"
                :href="attachment.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block overflow-hidden rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="sticker ? 'w-fit' : ''"
                :aria-label="
                    sticker
                        ? `Abrir ${stickerLabel.toLowerCase()} en una pestaña nueva`
                        : `Abrir imagen ${name} en una pestaña nueva`
                "
            >
                <img
                    :src="imageSource"
                    :alt="sticker ? stickerLabel : name"
                    loading="lazy"
                    decoding="async"
                    :width="intrinsic?.width"
                    :height="intrinsic?.height"
                    :class="[
                        sticker
                            ? 'size-32 object-contain sm:size-40'
                            : 'max-h-72 w-auto max-w-full rounded-lg object-contain',
                        intrinsic ? 'h-auto' : '',
                        !sticker && !loaded
                            ? 'min-h-24 min-w-32 bg-current/10'
                            : '',
                    ]"
                    :data-preview="
                        imageSource === attachment.url ? 'original' : 'thumb'
                    "
                    @load="loaded = true"
                    :data-sticker="
                        sticker
                            ? sticker.animated
                                ? 'animated'
                                : 'static'
                            : undefined
                    "
                    @error="onImageError"
                />
            </a>
            <p
                v-else
                class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-xs"
                :class="mutedText"
            >
                <ImageOff class="size-4" aria-hidden="true" />
                La imagen ya no está disponible.
            </p>
        </template>

        <audio
            v-else-if="kind === 'audio'"
            :src="attachment.url"
            controls
            preload="none"
            class="w-64 max-w-full"
            :aria-label="`Audio ${name}`"
        />

        <template v-else-if="kind === 'video'">
            <video
                v-if="playing"
                :src="attachment.url"
                controls
                autoplay
                playsinline
                preload="auto"
                class="max-h-72 max-w-full rounded-lg"
                :aria-label="`Video ${name}`"
            />
            <button
                v-else
                type="button"
                class="flex aspect-video w-64 max-w-full flex-col items-center justify-center gap-2 rounded-lg bg-current/10 px-3 text-center outline-none focus-visible:ring-2 focus-visible:ring-ring"
                data-video-placeholder
                :aria-label="`Reproducir video ${name}`"
                @click="playing = true"
            >
                <span
                    class="flex size-11 items-center justify-center rounded-full bg-background/80 text-foreground shadow-sm"
                >
                    <Play class="size-5 translate-x-px" aria-hidden="true" />
                </span>
                <span class="max-w-full truncate text-xs" :class="mutedText">
                    {{ name }}
                </span>
                <span v-if="size" class="text-xs" :class="mutedText">
                    {{ size }}
                </span>
            </button>
        </template>

        <div
            v-else
            class="flex items-center gap-3 rounded-lg border px-3 py-2"
            :class="customColors ? 'border-current/30' : 'bg-background/60'"
        >
            <FileText
                class="size-8 shrink-0"
                :class="mutedText"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium" :title="name">
                    {{ name }}
                </p>
                <p class="text-xs" :class="mutedText">
                    {{ size }}
                </p>
            </div>
            <a
                v-if="opensInline"
                :href="attachment.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10"
                :class="iconButton"
                :title="`Abrir ${name}`"
            >
                <ExternalLink class="size-4" aria-hidden="true" />
                <span class="sr-only">Abrir {{ name }}</span>
            </a>
            <a
                :href="attachment.download_url"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10"
                :class="iconButton"
                :title="`Descargar ${name}`"
            >
                <Download class="size-4" aria-hidden="true" />
                <span class="sr-only">Descargar {{ name }}</span>
            </a>
        </div>
    </div>
</template>
