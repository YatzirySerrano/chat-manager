<script setup lang="ts">
import { ArrowLeft, ArrowRight, Check, X } from '@lucide/vue';
import { useElementSize, useWindowSize } from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import type { TourRect } from '@/composables/useGuidedTour';
import type { ResolvedStep, TourPlacement } from '@/tours/types';

/**
 * Card of the current tour step: progress, title, a short text and the
 * Atrás / Siguiente (Terminar on the last step) / Cerrar controls.
 *
 * Positioned by hand next to the spotlighted control: on the preferred side,
 * or on the side with most room, always inside the viewport with a 12 px
 * margin. On phones it is a compact sheet at the bottom, or at the top when
 * the control is in the lower half of the screen.
 */
const props = defineProps<{
    step: ResolvedStep;
    position: number;
    total: number;
    rect: TourRect | null;
    isDesktop: boolean;
    reducedMotion: boolean;
}>();

const emit = defineEmits<{
    next: [];
    back: [];
    close: [];
    ready: [element: HTMLElement | null];
}>();

const MARGIN = 12;
const GAP = 14;

const card = ref<HTMLElement | null>(null);
const heading = ref<HTMLElement | null>(null);
const { width: cardWidth, height: cardHeight } = useElementSize(
    card,
    undefined,
    {
        box: 'border-box',
    },
);
const { width: viewportWidth, height: viewportHeight } = useWindowSize();

const isLast = computed(() => props.position >= props.total - 1);
const titleId = 'tour-step-title';
const bodyId = 'tour-step-body';

type Side = Exclude<TourPlacement, 'auto'>;

/*
 * Phone sheet: at the bottom, or at the top when the control is low. A
 * tall control can sit under either side, so the side that would cover it
 * gives way to the other one, or to the side that covers less of it.
 */
const sheetSide = computed<'top' | 'bottom'>(() => {
    if (props.step.placement === 'top' || props.step.placement === 'bottom') {
        return props.step.placement;
    }

    const rect = props.rect;

    if (!rect) {
        return 'bottom';
    }

    const preferred: 'top' | 'bottom' =
        rect.top + rect.height / 2 > viewportHeight.value / 2
            ? 'top'
            : 'bottom';
    const h = cardHeight.value || 180;
    const covered = {
        // Height of the control hidden by a card at each side.
        top: Math.max(
            0,
            Math.min(rect.top + rect.height, MARGIN + h) - rect.top,
        ),
        bottom: Math.max(
            0,
            rect.top +
                rect.height -
                Math.max(rect.top, viewportHeight.value - MARGIN - h),
        ),
    };
    const other = preferred === 'top' ? 'bottom' : 'top';

    if (covered[preferred] === 0) {
        return preferred;
    }

    return covered[other] < covered[preferred] ? other : preferred;
});

const desktopPosition = computed(() => {
    const rect = props.rect;
    const w = cardWidth.value || 320;
    const h = cardHeight.value || 180;
    const vw = viewportWidth.value;
    const vh = viewportHeight.value;

    if (!rect) {
        return { top: vh - h - MARGIN * 2, left: vw - w - MARGIN * 2 };
    }

    const room: Record<Side, number> = {
        bottom: vh - (rect.top + rect.height) - GAP,
        top: rect.top - GAP,
        right: vw - (rect.left + rect.width) - GAP,
        left: rect.left - GAP,
    };
    const fits = (side: Side): boolean =>
        side === 'top' || side === 'bottom'
            ? room[side] >= h + MARGIN
            : room[side] >= w + MARGIN;

    let side: Side;
    const preferred = props.step.placement;

    if (preferred !== 'auto' && fits(preferred)) {
        side = preferred;
    } else {
        const order: Side[] = ['bottom', 'top', 'right', 'left'];
        side =
            order.find(fits) ??
            order.reduce((best, candidate) =>
                room[candidate] > room[best] ? candidate : best,
            );
    }

    let top: number;
    let left: number;

    switch (side) {
        case 'bottom':
            top = rect.top + rect.height + GAP;
            left = rect.left + rect.width / 2 - w / 2;
            break;
        case 'top':
            top = rect.top - GAP - h;
            left = rect.left + rect.width / 2 - w / 2;
            break;
        case 'right':
            left = rect.left + rect.width + GAP;
            top = rect.top + rect.height / 2 - h / 2;
            break;
        case 'left':
            left = rect.left - GAP - w;
            top = rect.top + rect.height / 2 - h / 2;
            break;
    }

    return {
        top: Math.min(Math.max(top, MARGIN), Math.max(MARGIN, vh - h - MARGIN)),
        left: Math.min(
            Math.max(left, MARGIN),
            Math.max(MARGIN, vw - w - MARGIN),
        ),
    };
});

const cardStyle = computed(() => {
    if (!props.isDesktop) {
        return sheetSide.value === 'bottom'
            ? { bottom: 'calc(env(safe-area-inset-bottom) + 12px)' }
            : { top: 'calc(env(safe-area-inset-top) + 12px)' };
    }

    // Placed with a transform (no layout work while it follows a scroll).
    return {
        top: '0px',
        left: '0px',
        transform: `translate3d(${desktopPosition.value.left}px, ${desktopPosition.value.top}px, 0)`,
    };
});

/*
 * The card glides to its new place only when the step changes; while the
 * page scrolls or resizes it follows the control at once, never lagging
 * behind it.
 */
const stepChanging = ref(false);
let stepChangeTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.position,
    () => {
        stepChanging.value = true;
        clearTimeout(stepChangeTimer);
        stepChangeTimer = setTimeout(() => (stepChanging.value = false), 400);
    },
);

onBeforeUnmount(() => clearTimeout(stepChangeTimer));

function focusHeading(): void {
    heading.value?.focus({ preventScroll: true });
}

onMounted(() => {
    emit('ready', card.value);
    void nextTick(focusHeading);
});

onBeforeUnmount(() => emit('ready', null));

// A button that just became disabled (Atrás on the first step) or that is
// gone would leave focus on the page: it moves to «Siguiente» (the live
// region already reads the new title; the title itself would read it twice).
watch(
    () => props.position,
    async () => {
        await nextTick();
        const focused = document.activeElement;

        if (
            !card.value?.contains(focused) ||
            (focused instanceof HTMLButtonElement && focused.disabled)
        ) {
            const next =
                card.value?.querySelector<HTMLElement>('[data-tour-next]');

            if (next) {
                next.focus({ preventScroll: true });
            } else {
                focusHeading();
            }
        }
    },
);
</script>

<template>
    <div
        ref="card"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        :aria-describedby="bodyId"
        data-tour-tooltip
        :data-tour-target="step.target"
        :data-tour-step="step.id"
        class="tour-card fixed z-[62] flex flex-col gap-3 rounded-xl border bg-popover p-4 text-popover-foreground shadow-elevated"
        :class="[
            isDesktop ? 'w-[min(20rem,calc(100vw-2rem))]' : 'inset-x-3',
            !reducedMotion && isDesktop && stepChanging && 'tour-card-moves',
        ]"
        :style="cardStyle"
    >
        <div class="flex items-center justify-between gap-3">
            <p class="text-xs font-medium text-muted-foreground tabular-nums">
                Paso {{ position + 1 }} de {{ total }}
            </p>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                class="-mt-1 -mr-1.5 text-muted-foreground pointer-coarse:size-10"
                aria-label="Cerrar recorrido"
                data-tour-close
                @click="emit('close')"
            >
                <X aria-hidden="true" />
            </Button>
        </div>

        <Transition
            mode="out-in"
            enter-active-class="transition-opacity duration-(--duration-fast) ease-standard"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-100 ease-standard"
            leave-to-class="opacity-0"
        >
            <div :key="step.id" class="flex flex-col gap-1.5">
                <h2
                    :id="titleId"
                    ref="heading"
                    tabindex="-1"
                    class="text-base font-semibold text-balance outline-none"
                >
                    {{ step.title }}
                </h2>
                <p
                    :id="bodyId"
                    class="text-sm text-pretty text-muted-foreground"
                >
                    {{ step.body }}
                </p>
            </div>
        </Transition>

        <div class="flex items-center gap-1.5" aria-hidden="true">
            <span
                v-for="dot in total"
                :key="dot"
                class="size-1.5 rounded-full transition-colors duration-(--duration-fast)"
                :class="dot - 1 <= position ? 'bg-primary' : 'bg-border-strong'"
            />
        </div>

        <div class="flex items-center justify-between gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="pointer-coarse:h-10"
                :disabled="position === 0"
                data-tour-back
                @click="emit('back')"
            >
                <ArrowLeft aria-hidden="true" />
                Atrás
            </Button>
            <Button
                type="button"
                size="sm"
                class="pointer-coarse:h-10"
                data-tour-next
                @click="emit('next')"
            >
                <template v-if="isLast">
                    <Check aria-hidden="true" />
                    Terminar
                </template>
                <template v-else>
                    Siguiente
                    <ArrowRight aria-hidden="true" />
                </template>
            </Button>
        </div>
    </div>
</template>

<style scoped>
.tour-card-moves {
    transition: transform var(--duration-panel) var(--ease-standard);
}

@media (forced-colors: active) {
    .tour-card {
        border: 1px solid CanvasText;
    }
}
</style>
