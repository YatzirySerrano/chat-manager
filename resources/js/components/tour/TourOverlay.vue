<script setup lang="ts">
import { useWindowSize } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import TourTooltip from '@/components/tour/TourTooltip.vue';
import { useGuidedTour } from '@/composables/useGuidedTour';

/**
 * The guided tour layer, mounted once in the signed-in layout: a
 * transparent layer that catches clicks (the page is not usable during the
 * tour; only «Cerrar» or Escape end it), a spotlight that frames the real
 * control and dims the rest, the step card and a polite live region that
 * announces each step (only the progress on the first one, whose title
 * takes the focus).
 */
const tours = useGuidedTour();
const { width: viewportWidth, height: viewportHeight } = useWindowSize();

/** Room around the control inside the frame. */
const PAD = 6;

/**
 * The framed area: the control plus a small margin, cut to the visible part
 * of the viewport when the control is taller than 70 % of it.
 */
const spot = computed(() => {
    const rect = tours.targetRect.value;

    if (!rect) {
        return null;
    }

    let top = rect.top - PAD;
    let height = rect.height + PAD * 2;

    if (rect.height > viewportHeight.value * 0.7) {
        const visibleTop = Math.max(top, 8);
        const visibleBottom = Math.min(top + height, viewportHeight.value - 8);
        top = visibleTop;
        height = Math.max(0, visibleBottom - visibleTop);
    }

    // The frame (and its outline) stays inside the viewport horizontally,
    // e.g. around the sidebar that touches the left edge.
    const left = Math.max(rect.left - PAD, 3);
    const right = Math.min(
        rect.left + rect.width + PAD,
        viewportWidth.value - 3,
    );

    return {
        top,
        left,
        width: Math.max(0, right - left),
        height,
    };
});

/* Placed with a transform: following a scroll needs no layout work. */
const spotStyle = computed(() =>
    spot.value
        ? {
              top: '0px',
              left: '0px',
              transform: `translate3d(${spot.value.left}px, ${spot.value.top}px, 0)`,
              width: `${spot.value.width}px`,
              height: `${spot.value.height}px`,
          }
        : undefined,
);

/*
 * The frame glides to the next control only while the step changes; on
 * scroll or resize it follows the control at once instead of trailing it.
 */
const stepChanging = ref(false);
let stepChangeTimer: ReturnType<typeof setTimeout> | undefined;

watch(tours.index, () => {
    stepChanging.value = true;
    clearTimeout(stepChangeTimer);
    stepChangeTimer = setTimeout(() => (stepChanging.value = false), 400);
});

onBeforeUnmount(() => clearTimeout(stepChangeTimer));
</script>

<template>
    <Teleport to="body">
        <div class="sr-only" aria-live="polite" aria-atomic="true">
            {{ tours.announcement.value }}
        </div>
        <Transition
            enter-active-class="transition-opacity duration-(--duration-base) ease-standard"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-(--duration-fast) ease-standard"
            leave-to-class="opacity-0"
        >
            <div
                v-if="tours.active.value"
                class="tour-layer"
                data-tour-layer
                data-tour-overlay
            >
                <!-- Catches every click on the page while the tour is open. -->
                <div
                    class="fixed inset-0 z-[60] cursor-default"
                    data-tour-blocker
                    aria-hidden="true"
                    :class="!spot && 'bg-black/40'"
                />
                <div
                    v-if="spot"
                    class="tour-spot pointer-events-none fixed z-[61]"
                    :class="
                        !tours.reducedMotion.value &&
                        stepChanging &&
                        'tour-spot-moves'
                    "
                    :style="spotStyle"
                    data-tour-spotlight
                    aria-hidden="true"
                />
            </div>
        </Transition>
        <Transition
            :enter-active-class="
                tours.reducedMotion.value
                    ? ''
                    : 'transition-[opacity,translate]! duration-(--duration-base)! ease-emphasized!'
            "
            :enter-from-class="
                tours.reducedMotion.value ? '' : 'opacity-0 translate-y-1'
            "
            :leave-active-class="
                tours.reducedMotion.value
                    ? ''
                    : 'transition-opacity! duration-(--duration-fast)! ease-standard!'
            "
            :leave-to-class="tours.reducedMotion.value ? '' : 'opacity-0'"
        >
            <TourTooltip
                v-if="tours.active.value && tours.current.value"
                :step="tours.current.value"
                :position="tours.index.value"
                :total="tours.steps.value.length"
                :rect="spot"
                :is-desktop="tours.isDesktop.value"
                :reduced-motion="tours.reducedMotion.value"
                @next="tours.next"
                @back="tours.back"
                @close="tours.close"
                @ready="tours.registerTooltip"
            />
        </Transition>
    </Teleport>
</template>

<style scoped>
.tour-spot {
    border-radius: var(--radius-lg);
    outline: 2px solid var(--focus-ring-strong, var(--primary));
    outline-offset: 2px;
    box-shadow: var(--shadow-focus-spot);
}

.tour-spot-moves {
    transition:
        transform var(--duration-panel) var(--ease-standard),
        width var(--duration-panel) var(--ease-standard),
        height var(--duration-panel) var(--ease-standard);
}

@media (forced-colors: active) {
    .tour-spot {
        outline: 2px solid Highlight;
    }

    [data-tour-blocker] {
        background: color-mix(in srgb, Canvas 40%, transparent);
    }
}
</style>
