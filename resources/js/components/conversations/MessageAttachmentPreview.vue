<script setup lang="ts">
import {
    Download,
    Eye,
    FileWarning,
    ImageOff,
    LoaderCircle,
    Maximize2,
    Play,
} from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, inject, ref } from 'vue';
import AudioPlayer from '@/components/conversations/AudioPlayer.vue';
import MediaViewer from '@/components/conversations/MediaViewer.vue';
import { CUSTOM_CHAT_COLORS } from '@/lib/chatAppearance';
import {
    FILE_TONE_CLASSES,
    MEDIA_GALLERY,
    claimPlayback,
    fileBadge,
    formatClock,
    mediaKind,
} from '@/lib/conversations/media';
import type { ViewerItem } from '@/lib/conversations/media';
import { formatFileSize } from '@/lib/format';
import type { MessageAttachment } from '@/types';

/**
 * One file of a message, always loaded through the authorized attachment
 * route (never a public URL):
 *
 * - a picture shows its small preview and opens in the media viewer;
 * - a GIF (an animated picture, or the short silent video WhatsApp sends in
 *   its place) plays by itself, in a loop and without sound;
 * - a video shows its first frame with its length and plays in place;
 * - an audio or a voice note gets the chat's own player (AudioPlayer);
 * - a sticker is drawn at sticker size, without a frame;
 * - anything else is a card with its kind, name and size; a PDF can be read
 *   without leaving the chat.
 *
 * A file that has not arrived yet, or never will, says so and why.
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
        /** A voice note (recorded in the chat), not a shared audio file. */
        voice?: boolean;
        /** Length announced by the channel for an audio or a video, in seconds. */
        seconds?: number | null;
        /** A video the channel marks as a GIF: plays by itself, muted, in a loop. */
        gif?: boolean;
        /** Caption, author and time of the message, shown by the viewer. */
        caption?: string | null;
        author?: string | null;
        at?: string | null;
    }>(),
    {
        channelLabel: 'el canal',
        sticker: null,
        voice: false,
        seconds: null,
        gif: false,
        caption: null,
        author: null,
        at: null,
    },
);

const root = ref<HTMLElement | null>(null);
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

const kind = computed(() => mediaKind(props.attachment.mime_type));
const isGifImage = computed(
    () => props.attachment.mime_type.toLowerCase() === 'image/gif',
);

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
const pending = computed(
    () =>
        !props.attachment.available &&
        props.attachment.status !== 'unavailable',
);
const size = computed(() => formatFileSize(props.attachment.size_bytes));
const badge = computed(() =>
    fileBadge(props.attachment.file_name, props.attachment.mime_type),
);

/*
 * Pictures, videos and PDFs open in a viewer inside the app (an installed
 * PWA has no tab to come back from). Inside a chat history the viewer is
 * the history's, which knows the other files of the conversation; alone (a
 * preview dialog) the file opens by itself. A click with a modifier key
 * keeps the link's own behaviour.
 */
const gallery = inject(MEDIA_GALLERY, null);
const viewerOpen = ref(false);
const viewerItem = computed<ViewerItem | null>(() => {
    const viewer = kind.value;

    return viewer === 'image' || viewer === 'video' || viewer === 'pdf'
        ? {
              id: props.attachment.id,
              kind: viewer,
              src: props.attachment.url,
              downloadUrl: props.attachment.download_url,
              name: name.value,
              caption: props.caption,
              author: props.author,
              at: props.at,
          }
        : null;
});

function openViewer(event?: MouseEvent): void {
    if (
        props.sticker ||
        (event &&
            (event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.button !== 0))
    ) {
        return;
    }

    event?.preventDefault();
    pauseVideo();

    if (!gallery?.open(props.attachment.id)) {
        viewerOpen.value = true;
    }
}

/*
 * A video asks for nothing until it is near the screen, and then only for
 * its header (first frame and length): a long history does not start one
 * download per video.
 */
const near = ref(false);

useIntersectionObserver(
    root,
    ([entry]) => {
        if (entry?.isIntersecting) {
            near.value = true;
        }
    },
    { rootMargin: '300px' },
);

const video = ref<HTMLVideoElement | null>(null);
const playing = ref(false);
const videoFailed = ref(false);
const videoSeconds = ref<number | null>(null);
const videoLength = computed(() => videoSeconds.value ?? props.seconds);

function onVideoMetadata(): void {
    const value = video.value?.duration;

    if (value !== undefined && Number.isFinite(value) && value > 0) {
        videoSeconds.value = value;
    }
}

async function playVideo(): Promise<void> {
    const element = video.value;

    if (!element) {
        return;
    }

    playing.value = true;
    claimPlayback(element);

    try {
        await element.play();
    } catch (error) {
        if ((error as DOMException | null)?.name !== 'AbortError') {
            playing.value = false;
        }
    }
}

function pauseVideo(): void {
    if (video.value && !video.value.paused && !props.gif) {
        video.value.pause();
    }
}
</script>

<template>
    <div
        ref="root"
        class="max-w-full min-w-0"
        data-layout-box="attachment"
        :data-attachment-kind="sticker ? 'sticker' : kind"
    >
        <div
            v-if="!attachment.available"
            class="flex items-start gap-2.5 rounded-xl border border-dashed px-3 py-2.5 text-xs"
            :class="[mutedText, customColors && 'border-current/40']"
            :data-attachment-unavailable="
                attachment.error_code ?? attachment.status ?? 'pending'
            "
        >
            <LoaderCircle
                v-if="pending"
                class="mt-px size-4 shrink-0 animate-spin"
                aria-hidden="true"
            />
            <FileWarning
                v-else
                class="mt-px size-4 shrink-0"
                aria-hidden="true"
            />
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
                class="group/media relative block w-fit max-w-full overflow-hidden outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="sticker ? 'rounded-lg' : 'rounded-xl'"
                :aria-label="
                    sticker
                        ? `Abrir ${stickerLabel.toLowerCase()} en una pestaña nueva`
                        : `Abrir imagen ${name}`
                "
                @click="openViewer"
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
                            : 'max-h-80 w-auto max-w-full rounded-xl object-contain',
                        intrinsic ? 'h-auto' : '',
                        !sticker && !loaded
                            ? 'min-h-28 min-w-40 bg-current/10'
                            : '',
                    ]"
                    :data-preview="
                        imageSource === attachment.url ? 'original' : 'thumb'
                    "
                    :data-sticker="
                        sticker
                            ? sticker.animated
                                ? 'animated'
                                : 'static'
                            : undefined
                    "
                    @load="loaded = true"
                    @error="onImageError"
                />
                <span
                    v-if="isGifImage"
                    class="absolute top-1.5 left-1.5 rounded-md media-scrim px-1.5 py-0.5 text-[0.625rem] leading-none font-semibold tracking-wide"
                    aria-hidden="true"
                    >GIF</span
                >
                <span
                    v-if="!sticker"
                    class="absolute top-1.5 right-1.5 grid size-7 place-items-center rounded-full media-scrim opacity-0 transition-opacity duration-(--duration-fast) group-hover/media:opacity-100 group-focus-visible/media:opacity-100"
                    aria-hidden="true"
                >
                    <Maximize2 class="size-3.5" />
                </span>
            </a>
            <p
                v-else
                class="flex items-center gap-2 rounded-xl border border-dashed px-3 py-2.5 text-xs"
                :class="mutedText"
            >
                <ImageOff class="size-4 shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1"
                    >La imagen no se pudo mostrar.</span
                >
                <a
                    :href="attachment.download_url"
                    class="shrink-0 font-medium underline underline-offset-2"
                    >Descargar</a
                >
            </p>
        </template>

        <AudioPlayer
            v-else-if="kind === 'audio'"
            :src="attachment.url"
            :name="name"
            :seed="attachment.id"
            :seconds="seconds"
            :voice="voice"
            :download-url="attachment.download_url"
            :inherit-color="customColors"
        />

        <template v-else-if="kind === 'video'">
            <div
                v-if="!videoFailed"
                class="group/media relative w-fit max-w-full overflow-hidden rounded-xl bg-black"
                :class="!near && 'aspect-video w-72'"
                data-video
                :data-video-mode="gif ? 'gif' : 'video'"
            >
                <video
                    v-if="near"
                    ref="video"
                    :src="gif ? attachment.url : `${attachment.url}#t=0.1`"
                    :controls="playing && !gif"
                    :autoplay="gif"
                    :loop="gif"
                    :muted="gif"
                    playsinline
                    :preload="gif ? 'auto' : 'metadata'"
                    class="block max-h-80 max-w-full min-w-40"
                    :class="gif ? 'cursor-pointer' : ''"
                    :aria-label="gif ? `GIF ${name}` : `Video ${name}`"
                    @loadedmetadata="onVideoMetadata"
                    @play="playing = true"
                    @ended="playing = false"
                    @error="videoFailed = true"
                    @click="gif && openViewer()"
                />
                <template v-if="gif">
                    <span
                        class="pointer-events-none absolute top-1.5 left-1.5 rounded-md media-scrim px-1.5 py-0.5 text-[0.625rem] leading-none font-semibold tracking-wide"
                        aria-hidden="true"
                        >GIF</span
                    >
                </template>
                <template v-else>
                    <button
                        v-if="!playing"
                        type="button"
                        class="absolute inset-0 grid place-items-center outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                        data-video-placeholder
                        :aria-label="`Reproducir video ${name}`"
                        @click="playVideo"
                    >
                        <span
                            class="grid size-12 place-items-center rounded-full media-scrim transition-transform duration-(--duration-fast) ease-(--ease-spring) group-hover/media:scale-110"
                        >
                            <Play
                                class="size-5 translate-x-0.5 fill-current"
                                aria-hidden="true"
                            />
                        </span>
                    </button>
                    <span
                        v-if="!playing && (videoLength || size)"
                        class="pointer-events-none absolute bottom-1.5 left-1.5 rounded-md media-scrim px-1.5 py-0.5 text-[0.6875rem] leading-none font-medium tabular-nums"
                    >
                        {{ videoLength ? formatClock(videoLength) : size }}
                    </span>
                    <button
                        type="button"
                        class="absolute top-1.5 right-1.5 grid size-7 place-items-center rounded-full media-scrim opacity-0 transition-opacity duration-(--duration-fast) outline-none group-hover/media:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-white pointer-coarse:size-9 pointer-coarse:opacity-100"
                        :aria-label="`Ver el video ${name} en grande`"
                        title="Ver en grande"
                        @click="openViewer()"
                    >
                        <Maximize2 class="size-3.5" aria-hidden="true" />
                    </button>
                </template>
            </div>
            <!-- A format the browser cannot play (some 3GP, MOV): still downloadable. -->
            <div
                v-else
                class="flex items-center gap-3 rounded-xl border px-3 py-2.5"
                :class="customColors ? 'border-current/30' : 'bg-background/60'"
            >
                <Play
                    class="size-5 shrink-0"
                    :class="mutedText"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium" :title="name">
                        {{ name }}
                    </p>
                    <p class="text-xs" :class="mutedText">
                        Este video no se reproduce en el navegador · {{ size }}
                    </p>
                </div>
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
        </template>

        <div
            v-else
            class="flex w-80 max-w-full items-center gap-3 rounded-xl border p-2 pr-1.5"
            :class="customColors ? 'border-current/30' : 'bg-background/60'"
            data-file-card
        >
            <span
                class="grid size-11 shrink-0 place-items-center rounded-lg text-[0.6875rem] font-bold tracking-wide"
                :class="
                    customColors
                        ? 'border border-current/40'
                        : FILE_TONE_CLASSES[badge.tone]
                "
                aria-hidden="true"
                >{{ badge.label }}</span
            >
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium" :title="name">
                    {{ name }}
                </p>
                <p class="truncate text-xs" :class="mutedText">
                    {{ badge.kind
                    }}<template v-if="size"> · {{ size }}</template>
                </p>
            </div>
            <a
                v-if="kind === 'pdf'"
                :href="attachment.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10"
                :class="iconButton"
                :title="`Ver ${name}`"
                @click="openViewer"
            >
                <Eye class="size-4" aria-hidden="true" />
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

        <MediaViewer
            v-if="viewerOpen && viewerItem"
            v-model:open="viewerOpen"
            :items="[viewerItem]"
        />
    </div>
</template>
