<script setup lang="ts">
import { Download, Eye, Maximize2, Play } from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, nextTick, reactive, ref } from 'vue';
import TeamAudioPlayer from '@/components/team-chat/TeamAudioPlayer.vue';
import {
    fileLook,
    groupAttachments,
    isPdf,
    mediaClock,
} from '@/components/team-chat/teamChatFormat';
import { formatFileSize } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TeamAttachment } from '@/types/team-chat';

/**
 * The files of an internal message, shown in place: images as a tidy grid
 * of small previews (the original opens in the viewer), videos with their
 * first frame and a player, audios with their own player, and every other
 * file as a card with its type, name and size (a PDF opens in the viewer).
 *
 * Every box has its size before its content loads, so the history does not
 * jump while media arrives. A video asks for nothing until it is near the
 * screen, and then only for its header (byte ranges).
 */
const props = defineProps<{
    attachments: TeamAttachment[];
    mine: boolean;
    /** The viewer chose personal chat colors: only currentColor is safe. */
    custom: boolean;
}>();

const emit = defineEmits<{ view: [attachmentId: number] }>();

const groups = computed(() => groupAttachments(props.attachments));

/* At most four tiles; the last one counts what is left. */
const tiles = computed(() => groups.value.images.slice(0, 4));
const hiddenImages = computed(() =>
    Math.max(0, groups.value.images.length - tiles.value.length),
);

const brokenImages = reactive(new Set<number>());

function tileClass(index: number): string {
    const count = tiles.value.length;

    if (count === 3 && index === 0) {
        return 'col-span-2 aspect-[2/1]';
    }

    return 'aspect-square';
}

/* ---------------------------------------------------------------- videos */

const root = ref<HTMLElement | null>(null);
const near = ref(false);
const started = reactive(new Set<number>());
const brokenVideos = reactive(new Set<number>());
const durations = reactive(new Map<number, number>());
const players = new Map<number, HTMLVideoElement>();

const { stop } = useIntersectionObserver(
    root,
    ([entry]) => {
        if (entry?.isIntersecting) {
            near.value = true;
            stop();
        }
    },
    { rootMargin: '600px 0px' },
);

function setPlayer(id: number, element: unknown): void {
    if (element instanceof HTMLVideoElement) {
        players.set(id, element);
    } else {
        players.delete(id);
    }
}

async function play(video: TeamAttachment): Promise<void> {
    started.add(video.id);
    await nextTick();

    try {
        await players.get(video.id)?.play();
    } catch {
        // The controls are on screen: the person can press play again.
    }
}

function onVideoMetadata(video: TeamAttachment, event: Event): void {
    const { duration } = event.target as HTMLVideoElement;

    if (Number.isFinite(duration) && duration > 0) {
        durations.set(video.id, duration);
    }
}

const quiet = computed(() => (props.custom ? '' : 'opacity-75'));
const cardSurface = computed(() =>
    props.custom
        ? 'border border-current/30'
        : props.mine
          ? 'bg-primary-foreground/12 hover:bg-primary-foreground/20'
          : 'bg-muted/70 hover:bg-accent',
);
</script>

<template>
    <div
        ref="root"
        class="grid min-w-0 grid-cols-[minmax(0,1fr)] justify-items-start gap-1"
    >
        <div
            v-if="tiles.length === 1"
            class="max-w-full overflow-hidden rounded-xl"
        >
            <a
                :href="tiles[0].preview_url ?? tiles[0].download_url"
                target="_blank"
                rel="noopener"
                class="group/image relative block outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :title="tiles[0].name"
                :aria-label="`Ver imagen ${tiles[0].name}`"
                @click.exact.prevent="emit('view', tiles[0].id)"
            >
                <span
                    v-if="brokenImages.has(tiles[0].id)"
                    class="flex h-28 w-64 max-w-full items-center justify-center bg-current/10 px-4 text-center text-xs"
                >
                    No se pudo cargar la vista previa. Toca para abrir la
                    imagen.
                </span>
                <img
                    v-else
                    :src="tiles[0].thumb_url ?? tiles[0].preview_url ?? ''"
                    :alt="tiles[0].name"
                    loading="lazy"
                    decoding="async"
                    class="h-56 w-auto max-w-full min-w-36 bg-current/10 object-cover transition-[filter] duration-(--duration-fast) group-hover/image:brightness-95"
                    @error="brokenImages.add(tiles[0].id)"
                />
            </a>
        </div>
        <div
            v-else-if="tiles.length > 1"
            class="grid w-72 max-w-full grid-cols-2 gap-0.5 overflow-hidden rounded-xl"
        >
            <a
                v-for="(image, index) in tiles"
                :key="image.id"
                :href="image.preview_url ?? image.download_url"
                target="_blank"
                rel="noopener"
                :class="
                    cn(
                        'group/image relative block overflow-hidden bg-current/10 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                        tileClass(index),
                    )
                "
                :title="image.name"
                :aria-label="`Ver imagen ${image.name}`"
                @click.exact.prevent="emit('view', image.id)"
            >
                <img
                    v-if="!brokenImages.has(image.id)"
                    :src="image.thumb_url ?? image.preview_url ?? ''"
                    :alt="image.name"
                    loading="lazy"
                    decoding="async"
                    class="size-full object-cover transition-[filter,scale] duration-(--duration-base) ease-(--ease-out-quint) group-hover/image:scale-[1.03] group-hover/image:brightness-95"
                    @error="brokenImages.add(image.id)"
                />
                <span
                    v-if="hiddenImages > 0 && index === tiles.length - 1"
                    class="absolute inset-0 grid place-items-center bg-black/55 font-display text-2xl font-semibold text-white"
                    :aria-label="`y ${hiddenImages} más`"
                >
                    +{{ hiddenImages }}
                </span>
            </a>
        </div>

        <div
            v-for="video in groups.videos"
            :key="video.id"
            class="w-72 max-w-full"
            data-team-video
        >
            <div
                class="relative aspect-video overflow-hidden rounded-xl bg-black"
            >
                <p
                    v-if="brokenVideos.has(video.id)"
                    class="absolute inset-0 grid place-items-center px-4 text-center text-xs text-white/80"
                >
                    Este video no se puede reproducir en este navegador.
                    Descárgalo para verlo.
                </p>
                <video
                    v-else-if="near || started.has(video.id)"
                    :ref="(element) => setPlayer(video.id, element)"
                    :src="`${video.stream_url}#t=0.1`"
                    :controls="started.has(video.id)"
                    playsinline
                    preload="metadata"
                    class="size-full object-contain"
                    :aria-label="`Video ${video.name}`"
                    @loadedmetadata="onVideoMetadata(video, $event)"
                    @error="brokenVideos.add(video.id)"
                />
                <template
                    v-if="!started.has(video.id) && !brokenVideos.has(video.id)"
                >
                    <button
                        type="button"
                        class="group/play absolute inset-0 grid place-items-center outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-inset"
                        :aria-label="`Reproducir video ${video.name}`"
                        @click="play(video)"
                    >
                        <span
                            class="grid size-12 place-items-center rounded-full bg-black/55 text-white backdrop-blur-sm transition-[scale,background-color] duration-(--duration-fast) group-hover/play:scale-110 group-hover/play:bg-black/70"
                        >
                            <Play
                                class="size-5 translate-x-px fill-current"
                                aria-hidden="true"
                            />
                        </span>
                    </button>
                    <span
                        v-if="durations.has(video.id)"
                        class="pointer-events-none absolute bottom-1.5 left-1.5 rounded-md bg-black/60 px-1.5 py-0.5 text-[0.7rem] font-medium text-white tabular-nums"
                    >
                        {{ mediaClock(durations.get(video.id) ?? 0) }}
                    </span>
                </template>
                <button
                    v-if="!brokenVideos.has(video.id)"
                    type="button"
                    class="absolute top-1.5 right-1.5 grid size-8 place-items-center rounded-full bg-black/55 text-white outline-none hover:bg-black/75 focus-visible:ring-2 focus-visible:ring-white pointer-coarse:size-10"
                    :aria-label="`Ver video ${video.name} en grande`"
                    title="Ver en grande"
                    @click="emit('view', video.id)"
                >
                    <Maximize2 class="size-4" aria-hidden="true" />
                </button>
            </div>
            <a
                :href="video.download_url"
                class="mt-1 flex min-h-7 items-center gap-1.5 px-1.5 text-xs underline-offset-2 hover:underline pointer-coarse:min-h-10"
                :title="`Descargar ${video.name}`"
            >
                <Download class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="min-w-0 truncate">{{ video.name }}</span>
                <span class="shrink-0" :class="quiet"
                    >· {{ formatFileSize(video.size_bytes) }}</span
                >
            </a>
        </div>

        <TeamAudioPlayer
            v-for="audio in groups.audios"
            :key="audio.id"
            :src="audio.stream_url ?? ''"
            :name="audio.name"
            :download-url="audio.download_url"
            :size-bytes="audio.size_bytes"
        />

        <div
            v-for="file in groups.files"
            :key="file.id"
            :class="
                cn(
                    'flex w-72 max-w-full items-center gap-2.5 rounded-xl p-2 transition-colors duration-(--duration-fast)',
                    cardSurface,
                )
            "
            data-team-file
        >
            <component
                :is="isPdf(file) ? 'button' : 'a'"
                :type="isPdf(file) ? 'button' : undefined"
                :href="isPdf(file) ? undefined : file.download_url"
                class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg text-left outline-none focus-visible:ring-2 focus-visible:ring-current"
                :aria-label="
                    isPdf(file) ? `Ver ${file.name}` : `Descargar ${file.name}`
                "
                :title="
                    isPdf(file) ? 'Ver el documento' : 'Descargar el archivo'
                "
                @click="isPdf(file) && emit('view', file.id)"
            >
                <span
                    :class="
                        cn(
                            'grid size-10 shrink-0 place-items-center rounded-lg',
                            fileLook(file.name, file.kind).tone,
                        )
                    "
                    aria-hidden="true"
                >
                    <component
                        :is="fileLook(file.name, file.kind).icon"
                        class="size-5"
                    />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{
                        file.name
                    }}</span>
                    <span class="block text-xs" :class="quiet">
                        {{ fileLook(file.name, file.kind).label }} ·
                        {{ formatFileSize(file.size_bytes) }}
                    </span>
                </span>
                <component
                    :is="isPdf(file) ? Eye : Download"
                    class="mr-1 size-4 shrink-0"
                    :class="quiet"
                    aria-hidden="true"
                />
            </component>
            <a
                v-if="isPdf(file)"
                :href="file.download_url"
                class="grid size-8 shrink-0 place-items-center rounded-full outline-none hover:bg-current/15 focus-visible:ring-2 focus-visible:ring-current pointer-coarse:size-10"
                :aria-label="`Descargar ${file.name}`"
                title="Descargar"
            >
                <Download class="size-4" aria-hidden="true" />
            </a>
        </div>
    </div>
</template>
