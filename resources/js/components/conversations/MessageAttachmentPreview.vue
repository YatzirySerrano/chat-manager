<script setup lang="ts">
import {
    Download,
    ExternalLink,
    FileText,
    FileWarning,
    ImageOff,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { formatFileSize } from '@/lib/format';
import type { MessageAttachment } from '@/types';

/**
 * One file of a message, always loaded through the authorized attachment
 * route (never a public URL). Images show inline, audio and video get the
 * browser's players and documents a card to open or download them.
 */
const props = defineProps<{
    attachment: MessageAttachment;
}>();

const broken = ref(false);

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
const size = computed(() => formatFileSize(props.attachment.size_bytes));
const opensInline = computed(
    () => props.attachment.mime_type === 'application/pdf',
);
</script>

<template>
    <div class="max-w-full min-w-0" data-layout-box="attachment">
        <div
            v-if="!attachment.available"
            class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
        >
            <FileWarning class="size-4 shrink-0" aria-hidden="true" />
            {{
                attachment.status === 'unavailable'
                    ? 'No se pudo descargar el archivo desde WhatsApp.'
                    : 'El archivo todavía se está descargando desde WhatsApp.'
            }}
        </div>

        <template v-else-if="kind === 'image'">
            <a
                v-if="!broken"
                :href="attachment.url"
                target="_blank"
                rel="noopener noreferrer"
                class="block overflow-hidden rounded-lg focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-label="`Abrir imagen ${name} en una pestaña nueva`"
            >
                <img
                    :src="attachment.url"
                    :alt="name"
                    loading="lazy"
                    class="max-h-72 w-auto max-w-full rounded-lg object-contain"
                    @error="broken = true"
                />
            </a>
            <p
                v-else
                class="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
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

        <video
            v-else-if="kind === 'video'"
            :src="attachment.url"
            controls
            preload="metadata"
            class="max-h-72 max-w-full rounded-lg"
            :aria-label="`Video ${name}`"
        />

        <div
            v-else
            class="flex items-center gap-3 rounded-lg border bg-background/60 px-3 py-2"
        >
            <FileText
                class="size-8 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium" :title="name">
                    {{ name }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ size }}
                </p>
            </div>
            <a
                v-if="opensInline"
                :href="attachment.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10"
                :title="`Abrir ${name}`"
            >
                <ExternalLink class="size-4" aria-hidden="true" />
                <span class="sr-only">Abrir {{ name }}</span>
            </a>
            <a
                :href="attachment.download_url"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10"
                :title="`Descargar ${name}`"
            >
                <Download class="size-4" aria-hidden="true" />
                <span class="sr-only">Descargar {{ name }}</span>
            </a>
        </div>
    </div>
</template>
