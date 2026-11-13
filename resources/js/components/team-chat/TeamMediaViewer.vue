<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    X,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Full-screen viewer of the internal chat: the images, videos and PDF of
 * the messages on screen, one after another. Images zoom (buttons, wheel,
 * double click or tap, + and − keys) and are panned by dragging; ← and →
 * (or a swipe on a phone) move to the previous and next file; every file
 * can be downloaded. It keeps the person inside the app: an installed PWA
 * has no tab to come back from. The original is only requested here, never
 * by the bubbles (they show the small preview).
 */
export type ViewerItem = {
    id: number;
    kind: 'image' | 'video' | 'pdf';
    src: string;
    name: string;
    /** Null for a file that was not sent yet. */
    downloadUrl: string | null;
    /** Who sent it and when. */
    caption: string;
};

const props = defineProps<{ items: ViewerItem[] }>();

const open = defineModel<boolean>('open', { required: true });
const index = defineModel<number>('index', { required: true });

const MAX_ZOOM = 5;

const stage = ref<HTMLElement | null>(null);
const image = ref<HTMLImageElement | null>(null);
const zoom = ref(1);
/* Width of the image when it fits the screen: the base every zoom multiplies. */
const fitWidth = ref(0);
const failed = ref(false);
const loaded = ref(false);

const item = computed<ViewerItem | null>(
    () => props.items[index.value] ?? null,
);
const many = computed(() => props.items.length > 1);
const zoomed = computed(() => zoom.value > 1);

function reset(): void {
    zoom.value = 1;
    fitWidth.value = 0;
    failed.value = false;
    loaded.value = false;
}

watch([open, () => item.value?.id], reset);

/* The file on screen was deleted while it was open. */
watch(
    () => props.items.length,
    (length) => {
        if (length === 0) {
            open.value = false;
        } else if (index.value > length - 1) {
            index.value = length - 1;
        }
    },
);

function go(step: number): void {
    if (!many.value) {
        return;
    }

    index.value =
        (index.value + step + props.items.length) % props.items.length;
}

/** Zoom keeping the point under the pointer (or the center) in place. */
async function zoomTo(
    value: number,
    anchor?: { x: number; y: number },
): Promise<void> {
    const box = stage.value;
    const picture = image.value;

    if (!box || !picture || item.value?.kind !== 'image') {
        return;
    }

    if (fitWidth.value === 0 || zoom.value === 1) {
        fitWidth.value = picture.getBoundingClientRect().width;
    }

    const next = Math.max(1, Math.min(MAX_ZOOM, value));
    const rect = picture.getBoundingClientRect();
    const frame = box.getBoundingClientRect();
    const point = anchor ?? {
        x: frame.left + frame.width / 2,
        y: frame.top + frame.height / 2,
    };
    const relativeX = (point.x - rect.left) / Math.max(1, rect.width);
    const relativeY = (point.y - rect.top) / Math.max(1, rect.height);

    zoom.value = next;
    await nextTick();

    if (next === 1) {
        return;
    }

    const grown = picture.getBoundingClientRect();

    box.scrollLeft += grown.left + grown.width * relativeX - point.x;
    box.scrollTop += grown.top + grown.height * relativeY - point.y;
}

function onWheel(event: WheelEvent): void {
    if (item.value?.kind !== 'image') {
        return;
    }

    event.preventDefault();
    void zoomTo(zoom.value * (event.deltaY < 0 ? 1.25 : 0.8), {
        x: event.clientX,
        y: event.clientY,
    });
}

/* Dragging pans a zoomed image; a click that did not drag toggles the zoom. */
let drag: { x: number; y: number; moved: boolean } | null = null;

function onPointerDown(event: PointerEvent): void {
    if (event.pointerType !== 'mouse' || event.button !== 0) {
        return;
    }

    drag = { x: event.clientX, y: event.clientY, moved: false };
}

function onPointerMove(event: PointerEvent): void {
    const box = stage.value;

    if (!drag || !box || event.buttons !== 1) {
        return;
    }

    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;

    if (Math.abs(dx) + Math.abs(dy) > 3) {
        drag.moved = true;
    }

    if (zoomed.value) {
        box.scrollLeft -= dx;
        box.scrollTop -= dy;
        drag.x = event.clientX;
        drag.y = event.clientY;
    }
}

function onImageClick(event: MouseEvent): void {
    const moved = drag?.moved ?? false;
    drag = null;

    if (moved) {
        return;
    }

    void zoomTo(zoomed.value ? 1 : 2.5, { x: event.clientX, y: event.clientY });
}

/* A horizontal swipe on a phone moves between files (only when not zoomed). */
let touchStart: { x: number; y: number } | null = null;

function onTouchStart(event: TouchEvent): void {
    touchStart =
        event.touches.length === 1 && !zoomed.value
            ? { x: event.touches[0].clientX, y: event.touches[0].clientY }
            : null;
}

function onTouchEnd(event: TouchEvent): void {
    const start = touchStart;
    touchStart = null;

    if (!start || event.changedTouches.length !== 1) {
        return;
    }

    const dx = event.changedTouches[0].clientX - start.x;
    const dy = event.changedTouches[0].clientY - start.y;

    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) {
        go(dx < 0 ? 1 : -1);
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        go(1);
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        go(-1);
    } else if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        void zoomTo(zoom.value * 1.5);
    } else if (event.key === '-') {
        event.preventDefault();
        void zoomTo(zoom.value / 1.5);
    } else if (event.key === '0') {
        event.preventDefault();
        void zoomTo(1);
    }
}

const control =
    'grid size-11 shrink-0 place-items-center rounded-full outline-none transition-colors hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white disabled:opacity-40 disabled:hover:bg-transparent';
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/92 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col text-white outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
                data-media-lightbox
                data-team-media-viewer
                @keydown="onKeydown"
            >
                <header
                    class="flex shrink-0 items-center gap-1 bg-black/60 pt-[env(safe-area-inset-top,0px)] pr-[max(0.5rem,env(safe-area-inset-right,0px))] pl-[max(1rem,env(safe-area-inset-left,0px))]"
                >
                    <div class="min-w-0 flex-1 py-2">
                        <DialogTitle class="truncate text-sm font-medium">
                            {{ item?.name }}
                        </DialogTitle>
                        <DialogDescription
                            class="truncate text-xs text-white/70"
                        >
                            {{ item?.caption }}
                            <template v-if="many">
                                · {{ index + 1 }} de {{ items.length }}
                            </template>
                        </DialogDescription>
                    </div>
                    <template v-if="item?.kind === 'image' && !failed">
                        <button
                            type="button"
                            :class="control"
                            aria-label="Alejar imagen"
                            title="Alejar (−)"
                            :disabled="!zoomed"
                            @click="zoomTo(zoom / 1.5)"
                        >
                            <ZoomOut class="size-5" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            :class="control"
                            aria-label="Acercar imagen"
                            title="Acercar (+)"
                            :disabled="zoom >= MAX_ZOOM"
                            @click="zoomTo(zoom * 1.5)"
                        >
                            <ZoomIn class="size-5" aria-hidden="true" />
                        </button>
                    </template>
                    <a
                        v-if="item?.kind === 'pdf'"
                        :href="item.src"
                        target="_blank"
                        rel="noopener"
                        :class="control"
                        :aria-label="`Abrir ${item.name} en una pestaña nueva`"
                        title="Abrir en una pestaña nueva"
                    >
                        <ExternalLink class="size-5" aria-hidden="true" />
                    </a>
                    <a
                        v-if="item?.downloadUrl"
                        :href="item.downloadUrl"
                        :class="control"
                        :aria-label="`Descargar ${item.name}`"
                        title="Descargar"
                    >
                        <Download class="size-5" aria-hidden="true" />
                    </a>
                    <DialogClose
                        :class="control"
                        aria-label="Cerrar visor"
                        title="Cerrar (Esc)"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </DialogClose>
                </header>

                <div class="relative min-h-0 flex-1">
                    <div
                        v-if="item"
                        ref="stage"
                        class="flex h-full w-full overscroll-contain pb-[env(safe-area-inset-bottom,0px)]"
                        :class="
                            zoomed
                                ? 'overflow-auto'
                                : 'overflow-hidden p-2 sm:p-6'
                        "
                        @click.self="open = false"
                        @wheel="onWheel"
                        @touchstart.passive="onTouchStart"
                        @touchend.passive="onTouchEnd"
                    >
                        <p
                            v-if="failed"
                            class="m-auto max-w-sm px-6 text-center text-sm text-white/80"
                        >
                            Este archivo no se pudo mostrar. Revisa tu conexión
                            o descárgalo para abrirlo en tu equipo.
                        </p>
                        <template v-else-if="item.kind === 'image'">
                            <span
                                v-if="!loaded"
                                class="pointer-events-none absolute inset-0 m-auto size-8 animate-spin rounded-full border-2 border-white/25 border-t-white"
                                aria-hidden="true"
                            />
                            <img
                                ref="image"
                                :key="item.id"
                                :src="item.src"
                                :alt="item.name"
                                decoding="async"
                                draggable="false"
                                class="m-auto shrink-0 select-none motion-safe:transition-opacity"
                                :class="[
                                    zoomed
                                        ? 'max-w-none cursor-grab active:cursor-grabbing'
                                        : 'max-h-full max-w-full cursor-zoom-in object-contain',
                                    loaded ? 'opacity-100' : 'opacity-0',
                                ]"
                                :style="
                                    zoomed
                                        ? { width: `${fitWidth * zoom}px` }
                                        : undefined
                                "
                                @pointerdown="onPointerDown"
                                @pointermove="onPointerMove"
                                @click="onImageClick"
                                @load="loaded = true"
                                @error="failed = true"
                            />
                        </template>
                        <video
                            v-else-if="item.kind === 'video'"
                            :key="item.id"
                            :src="item.src"
                            controls
                            autoplay
                            playsinline
                            class="m-auto max-h-full max-w-full rounded-lg bg-black"
                            :aria-label="`Video ${item.name}`"
                            @error="failed = true"
                        />
                        <iframe
                            v-else
                            :key="item.id"
                            :src="item.src"
                            :title="item.name"
                            class="h-full w-full rounded-lg bg-white"
                        />
                    </div>

                    <template v-if="many">
                        <button
                            type="button"
                            class="absolute top-1/2 left-2 -translate-y-1/2 bg-black/55 sm:left-4"
                            :class="control"
                            aria-label="Archivo anterior"
                            title="Anterior (←)"
                            @click="go(-1)"
                        >
                            <ChevronLeft class="size-6" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            class="absolute top-1/2 right-2 -translate-y-1/2 bg-black/55 sm:right-4"
                            :class="control"
                            aria-label="Archivo siguiente"
                            title="Siguiente (→)"
                            @click="go(1)"
                        >
                            <ChevronRight class="size-6" aria-hidden="true" />
                        </button>
                    </template>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
