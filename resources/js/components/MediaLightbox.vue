<script setup lang="ts">
import { Download, X, ZoomIn, ZoomOut } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { ref, watch } from 'vue';

/**
 * Full-screen viewer for an image of a chat. It keeps the person inside the
 * app (an installed PWA has no tabs to come back from) and works with one
 * hand on a phone: the bar stays clear of the notch, the close button is a
 * full-size touch target, a tap on the image toggles the zoom and the
 * zoomed image is panned by dragging. Pinching still zooms as usual.
 *
 * The original file is only requested when the viewer opens.
 */
const props = defineProps<{
    src: string;
    name: string;
    downloadUrl?: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const zoomed = ref(false);
const failed = ref(false);
const stage = ref<HTMLElement | null>(null);

watch(open, (value) => {
    if (value) {
        zoomed.value = false;
        failed.value = false;
    }
});

/* Zoom around the point that was tapped, so it stays under the finger. */
function toggleZoom(event: MouseEvent): void {
    const box = stage.value;

    if (!box) {
        zoomed.value = !zoomed.value;

        return;
    }

    const rect = box.getBoundingClientRect();
    const x = (event.clientX - rect.left) / Math.max(1, rect.width);
    const y = (event.clientY - rect.top) / Math.max(1, rect.height);

    zoomed.value = !zoomed.value;

    if (zoomed.value) {
        requestAnimationFrame(() => {
            box.scrollLeft = (box.scrollWidth - box.clientWidth) * x;
            box.scrollTop = (box.scrollHeight - box.clientHeight) * y;
        });
    }
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/90 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col text-white outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
                :aria-describedby="undefined"
                data-media-lightbox
            >
                <header
                    class="flex shrink-0 items-center gap-1 bg-black/60 pt-[env(safe-area-inset-top,0px)] pr-[max(0.5rem,env(safe-area-inset-right,0px))] pl-[max(1rem,env(safe-area-inset-left,0px))]"
                >
                    <DialogTitle
                        class="min-w-0 flex-1 truncate py-3 text-sm font-medium"
                    >
                        {{ name }}
                    </DialogTitle>
                    <button
                        v-if="!failed"
                        type="button"
                        class="grid size-11 shrink-0 place-items-center rounded-full outline-none hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white"
                        :aria-label="
                            zoomed ? 'Alejar imagen' : 'Acercar imagen'
                        "
                        @click="zoomed = !zoomed"
                    >
                        <component
                            :is="zoomed ? ZoomOut : ZoomIn"
                            class="size-5"
                            aria-hidden="true"
                        />
                    </button>
                    <a
                        v-if="downloadUrl"
                        :href="downloadUrl"
                        class="grid size-11 shrink-0 place-items-center rounded-full outline-none hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white"
                        :aria-label="`Descargar ${name}`"
                    >
                        <Download class="size-5" aria-hidden="true" />
                    </a>
                    <DialogClose
                        class="grid size-11 shrink-0 place-items-center rounded-full outline-none hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white"
                        aria-label="Cerrar imagen"
                    >
                        <X class="size-5" aria-hidden="true" />
                    </DialogClose>
                </header>

                <div
                    ref="stage"
                    class="min-h-0 flex-1 overscroll-contain pb-[env(safe-area-inset-bottom,0px)]"
                    :class="
                        zoomed
                            ? 'overflow-auto'
                            : 'flex items-center justify-center overflow-hidden'
                    "
                    @click.self="open = false"
                >
                    <p
                        v-if="failed"
                        class="px-6 text-center text-sm text-white/80"
                    >
                        La imagen no se pudo cargar. Revisa tu conexión e
                        inténtalo de nuevo.
                    </p>
                    <img
                        v-else
                        :src="src"
                        :alt="name"
                        decoding="async"
                        draggable="false"
                        :class="
                            zoomed
                                ? 'w-[250%] max-w-none cursor-zoom-out'
                                : 'max-h-full max-w-full cursor-zoom-in object-contain'
                        "
                        @click="toggleZoom"
                        @error="failed = true"
                    />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
