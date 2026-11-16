<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import './filters.css';

/**
 * A counter of a quick filter or a row. When the number goes up while it is
 * on screen (a live update), it bumps once: enough to catch the eye of
 * someone working in another pane, never a loop. Decreases and the first
 * render never animate. The number is
 * decorative (aria-hidden) and cannot be selected: the control that holds it
 * says it in words, so copied text carries the figure once.
 */
const props = withDefaults(
    defineProps<{
        value: number;
        /**
         * Color pair: plain (number only), muted, the brand (filled or soft),
         * attention (pending) or done.
         */
        tone?:
            | 'plain'
            | 'muted'
            | 'primary'
            | 'soft'
            | 'warning'
            | 'success'
            | 'inverse';
        /** Larger numbers read as «99+». */
        max?: number;
        /** Kept for compatibility: the bump is the only motion. */
        halo?: boolean;
        class?: string;
    }>(),
    { tone: 'muted', max: 99, halo: false, class: undefined },
);

const bumping = ref(false);
/* The number rolls up when it grows and down when it shrinks. */
const direction = ref<'count-up' | 'count-down'>('count-up');
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.value,
    (value, previous) => {
        direction.value = value < previous ? 'count-down' : 'count-up';

        if (value <= previous) {
            return;
        }

        bumping.value = false;
        clearTimeout(timer);
        // Next frame, so a second increase restarts the animation.
        requestAnimationFrame(() => {
            bumping.value = true;
            timer = setTimeout(() => (bumping.value = false), 950);
        });
    },
);

const text = computed(() =>
    props.value > props.max ? `${props.max}+` : String(props.value),
);

const tones: Record<NonNullable<typeof props.tone>, string> = {
    plain: 'px-0.5 text-muted-foreground',
    muted: 'bg-muted text-muted-foreground',
    primary: 'bg-primary text-primary-foreground',
    soft: 'bg-primary-soft text-primary-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    success: 'bg-success-soft text-success-soft-foreground',
    /* On a filled accent surface. */
    inverse: 'bg-primary-foreground/20 text-primary-foreground',
};
</script>

<template>
    <span
        :class="
            cn(
                'relative inline-flex h-[1.125rem] min-w-[1.125rem] shrink-0 items-center justify-center rounded-full px-1.5 text-[0.6875rem] leading-none font-semibold tabular-nums transition-[background-color,color] duration-(--duration-fast) select-none',
                tones[tone],
                bumping && 'filter-count-bump',
                props.class,
            )
        "
        aria-hidden="true"
        data-count-badge
        ><span class="relative inline-grid overflow-hidden"
            ><Transition :name="direction"
                ><span :key="text" class="col-start-1 row-start-1">{{
                    text
                }}</span></Transition
            ></span
        ></span
    >
</template>
