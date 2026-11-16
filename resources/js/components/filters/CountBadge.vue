<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { cn } from '@/lib/utils';
import './filters.css';

/**
 * A counter of a quick filter or a row. When the number goes up while it is
 * on screen (a live update), it bumps once and, with `halo`, sends one soft
 * ring out: enough to catch the eye of someone working in another pane,
 * never a loop. Decreases and the first render never animate. The number is
 * decorative (aria-hidden): the control that holds it says it in words.
 */
const props = withDefaults(
    defineProps<{
        value: number;
        /** Color pair: muted, the brand, attention (pending) or done. */
        tone?: 'muted' | 'primary' | 'warning' | 'success';
        /** Larger numbers read as «99+». */
        max?: number;
        /** One ring leaves the badge when the number goes up. */
        halo?: boolean;
        class?: string;
    }>(),
    { tone: 'muted', max: 99, halo: false, class: undefined },
);

const bumping = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.value,
    (value, previous) => {
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
    muted: 'bg-muted text-muted-foreground',
    primary: 'bg-primary text-primary-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    success: 'bg-success-soft text-success-soft-foreground',
};
</script>

<template>
    <span
        :class="
            cn(
                'relative inline-flex h-[1.125rem] min-w-[1.125rem] shrink-0 items-center justify-center rounded-full px-1.5 text-[0.7rem] leading-none font-semibold tabular-nums transition-[background-color,color] duration-(--duration-fast)',
                tones[tone],
                bumping && 'filter-count-bump',
                bumping && halo && 'filter-count-halo',
                props.class,
            )
        "
        aria-hidden="true"
        data-count-badge
        >{{ text }}</span
    >
</template>
