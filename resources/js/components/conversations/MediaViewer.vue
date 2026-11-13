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
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import { formatDateTime } from '@/lib/format';
import type { ViewerItem } from '@/lib/conversations/media';

/**
 * Full-screen viewer of the pictures, videos and PDFs of a chat. It keeps
 * the person inside the app (an installed PWA has no tabs to come back
 * from) and moves between the files of the conversation with the arrows of
 * the keyboard, the side buttons or a horizontal swipe.
 *
 * Pictures zoom with the buttons, the wheel, a double click or the + and −
 * keys, and are panned by dragging once zoomed. The original file is only
 * requested when it is shown.
 */
const props = defineProps<{
    items: ViewerItem[];
}>();

const open = defineModel<boolean>('open', { required: true });
const index = defineModel<number>('index', { default: 0 });

const ZOOM_STEPS = [1, 1.5, 2.5, 4] as const;

const zoom = ref(0);
const failed = ref(false);
const loading = ref(true);
const stage = ref<HTMLElement | null>(null);

const current = computed<ViewerItem | null>(
    () => props.items[Math.min(index.value, props.items.length - 1)] ?? null,
);
const many = computed(() => props.items.length > 1);
const scale = computed(() => ZOOM_STEPS[zoom.value]);
const zoomable = computed(
    () => current.value?.kind === 'image' && !failed.value,
);
const details = computed(() =>
    [current.value?.author, formatDateTime(current.value?.at) || null]
        .filter(Boolean)
        .join(' · '),
);

function reset(): void {
    zoom.value = 0;
    failed.value = false;
    loading.value = true;
}

watch(open, (value) => value && reset());
watch(() => current.value?.id, reset);

/* The list shrank (a message was removed) while the viewer was open. */
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
    const target = index.value + step;

    if (target >= 0 && target < props.items.length) {
        index.value = target;
    }
}

/* Zoom keeping the point under the cursor (or the centre) in place. */
async function setZoom(
    level: number,
    anchor?: { x: number; y: number },
): Promise<void> {
    const box = stage.value;
    const next = Math.min(ZOOM_STEPS.length - 1, Math.max(0, level));

    if (!zoomable.value || next === zoom.value) {
        return;
    }

    const rect = box?.getBoundingClientRect();
    const x =
        anchor && rect ? (anchor.x - rect.left) / Math.max(1, rect.width) : 0.5;
    const y =
        anchor && rect ? (anchor.y - rect.top) / Math.max(1, rect.height) : 0.5;
    const ratioX =
        box && box.scrollWidth > 0
            ? (box.scrollLeft + box.clientWidth * x) / box.scrollWidth
            : 0.5;
    const ratioY =
        box && box.scrollHeight > 0
            ? (box.scrollTop + box.clientHeight * y) / box.scrollHeight
            : 0.5;

    zoom.value = next;
    await nextTick();

    if (box) {
        box.scrollLeft = box.scrollWidth * ratioX - box.clientWidth * x;
        box.scrollTop = box.scrollHeight * ratioY - box.clientHeight * y;
    }
}

function onWheel(event: WheelEvent): void {
    if (!zoomable.value) {
        return;
    }

    event.preventDefault();
    void setZoom(zoom.value + (event.deltaY < 0 ? 1 : -1), {
        x: event.clientX,
        y: event.clientY,
    });
}

function onDoubleClick(event: MouseEvent): void {
    void setZoom(zoom.value === 0 ? 2 : 0, {
        x: event.clientX,
        y: event.clientY,
    });
}

/* Drag to pan a zoomed picture; swipe sideways to change file otherwise. */
let drag: {
    x: number;
    y: number;
    left: number;
    top: number;
    moved: boolean;
} | null = null;

function onPointerDown(event: PointerEvent): void {
    const box = stage.value;

    if (!box || event.button !== 0 || current.value?.kind !== 'image') {
        return;
    }

    drag = {
        x: event.clientX,
        y: event.clientY,
        left: box.scrollLeft,
        top: box.scrollTop,
        moved: false,
    };
}

function onPointerMove(event: PointerEvent): void {
    const box = stage.value;

    if (!drag || !box) {
        return;
    }

    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;

    if (Math.abs(dx) + Math.abs(dy) > 6) {
        drag.moved = true;
    }

    if (zoom.value > 0 && event.pointerType === 'mouse') {
        box.scrollLeft = drag.left - dx;
        box.scrollTop = drag.top - dy;
    }
}

function onPointerUp(event: PointerEvent): void {
    const started = drag;

    drag = null;

    if (!started || zoom.value > 0) {
        return;
    }

    const dx = event.clientX - started.x;

    if (Math.abs(dx) > 70 && Math.abs(event.clientY - started.y) < 60) {
        go(dx < 0 ? 1 : -1);
    }
}

function onStageClick(event: MouseEvent): void {
    // A click on the empty area closes; a drag that ended there does not.
    if (event.target === event.currentTarget) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    // The video's own controls keep their keys.
    if (
        target?.tagName === 'VIDEO' &&
        [' ', 'ArrowLeft', 'ArrowRight'].includes(event.key)
    ) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        go(-1);
    } else if (event.key === 'ArrowRight') {
        event.preventDefault();
        go(1);
    } else if (event.key === '+' || event.key === '=') {
        void setZoom(zoom.value + 1);
    } else if (event.key === '-') {
        void setZoom(zoom.value - 1);
    } else if (event.key === '0') {
        void setZoom(0);
    }
}

const barButton =
    'grid size-11 shrink-0 place-items-center rounded-full outline-none transition-colors duration-(--duration-fast) hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white disabled:opacity-35 disabled:hover:bg-transparent';
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/92 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col text-white outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
                :aria-describedby="undefined"
                data-media-lightbox
                :data-media-kind="current?.kind"
                @keydown="onKeydown"
            >
                <header
                    class="flex shrink-0 items-center gap-1 bg-black/55 pt-[env(safe-area-inset-top,0px)] pr-[max(0.5rem,env(safe-area-inset-right,0px))] pl-[max(1rem,env(safe-area-inset-left,0px))]"
                >
                    <div class="min-w-0 flex-1 py-2">
                        <DialogTitle class="truncate text-sm font-medium">
                            {{ current?.name ?? 'Archivo' }}
                        </DialogTitle>
                        <p
                            v-if="details || many"
                            class="truncate text-xs text-white/65 tabular-nums"
                        >
                            <span v-if="many" data-media-counter
                                >{{ index + 1 }} de {{ items.length }}</span
                            >
                            <span v-if="many && details"> · </span>
                            {{ details }}
                        </p>
                    </div>
                    <template v-if="zoomable">
                        <button
                            type="button"
                            :class="barButton"
                            class="max-sm:hidden"
                            :disabled="zoom === 0"
                            aria-label="Alejar imagen"
                            title="Alejar (−)"
                            @click="setZoom(zoom - 1)"
                        >
                            <ZoomOut class="size-5" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            :class="barButton"
                            :disabled="zoom === ZOOM_STEPS.length - 1"
                            aria-label="Acercar imagen"
                            title="Acercar (+)"
                            @click="setZoom(zoom + 1)"
                        >
                            <ZoomIn class="size-5" aria-hidden="true" />
                        </button>
                    </template>
                    <a
                        v-if="current?.kind === 'pdf'"
                        :href="current.src"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="barButton"
                        :aria-label="`Abrir ${current.name} en una pestaña nueva`"
                        title="Abrir en una pestaña nueva"
                    >
                        <ExternalLink class="size-5" aria-hidden="true" />
                    </a>
                    <a
                        v-if="current?.downloadUrl"
                        :href="current.downloadUrl"
                        :class="barButton"
                        :aria-label="`Descargar ${current.name}`"
                        title="Descargar"
                    >
                        <Download class="size-5" aria-hidden="true" />
                    </a>
                    <DialogClose
                        :class="barButton"
                        aria-label="Cerrar el visor"
                        title="Cerrar (Esc)"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </DialogClose>
                </header>

                <div class="relative min-h-0 flex-1">
                    <div
                        v-if="current"
                        ref="stage"
                        class="size-full overscroll-contain"
                        :class="[
                            zoom > 0 && current.kind === 'image'
                                ? 'cursor-grab overflow-auto active:cursor-grabbing'
                                : 'flex items-center justify-center overflow-hidden',
                            current.kind === 'pdf'
                                ? 'p-0 sm:px-16 sm:py-4'
                                : '',
                        ]"
                        @click="onStageClick"
                        @wheel="onWheel"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="drag = null"
                    >
                        <p
                            v-if="failed"
                            class="max-w-sm px-6 text-center text-sm text-white/80"
                            role="alert"
                        >
                            El archivo no se pudo cargar. Revisa tu conexión e
                            inténtalo de nuevo, o descárgalo.
                        </p>
                        <img
                            v-else-if="current.kind === 'image'"
                            :key="current.id"
                            :src="current.src"
                            :alt="current.name"
                            decoding="async"
                            draggable="false"
                            class="select-none"
                            :class="
                                zoom > 0
                                    ? 'max-w-none'
                                    : 'max-h-full max-w-full cursor-zoom-in object-contain'
                            "
                            :style="
                                zoom > 0
                                    ? { width: `${scale * 100}%` }
                                    : undefined
                            "
                            @load="loading = false"
                            @error="failed = true"
                            @dblclick="onDoubleClick"
                        />
                        <video
                            v-else-if="current.kind === 'video'"
                            :key="`video-${current.id}`"
                            :src="current.src"
                            controls
                            autoplay
                            playsinline
                            class="max-h-full max-w-full bg-black outline-none"
                            :aria-label="`Video ${current.name}`"
                            @loadeddata="loading = false"
                            @error="failed = true"
                        />
                        <iframe
                            v-else
                            :key="`pdf-${current.id}`"
                            :src="current.src"
                            :title="current.name"
                            class="size-full border-0 bg-white sm:rounded-lg"
                            @load="loading = false"
                        />
                    </div>

                    <template v-if="many">
                        <button
                            type="button"
                            class="absolute top-1/2 left-2 grid size-11 -translate-y-1/2 place-items-center rounded-full media-scrim transition-opacity duration-(--duration-fast) outline-none hover:bg-black/80 focus-visible:ring-2 focus-visible:ring-white disabled:pointer-events-none disabled:opacity-0 sm:left-4"
                            :disabled="index === 0"
                            aria-label="Archivo anterior"
                            title="Anterior (←)"
                            data-media-previous
                            @click="go(-1)"
                        >
                            <ChevronLeft class="size-6" aria-hidden="true" />
                        </button>
                        <button
                            type="button"
                            class="absolute top-1/2 right-2 grid size-11 -translate-y-1/2 place-items-center rounded-full media-scrim transition-opacity duration-(--duration-fast) outline-none hover:bg-black/80 focus-visible:ring-2 focus-visible:ring-white disabled:pointer-events-none disabled:opacity-0 sm:right-4"
                            :disabled="index >= items.length - 1"
                            aria-label="Archivo siguiente"
                            title="Siguiente (→)"
                            data-media-next
                            @click="go(1)"
                        >
                            <ChevronRight class="size-6" aria-hidden="true" />
                        </button>
                    </template>
                </div>

                <p
                    v-if="current?.caption"
                    class="max-h-24 shrink-0 overflow-y-auto bg-black/55 px-4 py-2.5 pb-[max(0.625rem,env(safe-area-inset-bottom,0px))] text-center text-sm wrap-anywhere whitespace-pre-line text-white/90"
                >
                    {{ current.caption }}
                </p>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
