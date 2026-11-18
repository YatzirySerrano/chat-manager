<script setup lang="ts" generic="T extends string">
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import { cn } from '@/lib/utils';
import type { FilterTabOption } from '@/components/filters/types';
import './filters.css';

/**
 * The views of a list as one compact row of mutually exclusive tabs
 * (Bandeja, Pendientes, Mías, Cerradas… or Todos, Privados, Grupos…). The
 * chosen tab sits on a quiet tinted thumb that slides to the new one, the
 * only motion of the header, so the eye follows what changed. Each tab may
 * carry a live counter (CountBadge, bumps when it goes up).
 *
 * The `after` slot goes on the same row, after the tabs and outside their
 * group (the quick toggles of a list), so the whole strip scrolls sideways
 * as one on narrow lists, keeping the chosen tab in view.
 *
 * Accessibility: a group of toggle buttons (aria-pressed), each one reached
 * with Tab; the arrow keys, Home and End also move between them. When no tab
 * matches the applied filters (`modelValue` null) none is pressed.
 */
const props = defineProps<{
    options: FilterTabOption<T>[];
    /** The tab that matches the applied filters, or null. */
    modelValue: T | null;
    /** Accessible name of the group. */
    label: string;
    /**
     * Wrap onto more rows instead of scrolling sideways: every tab and
     * toggle stays visible (the inbox, where «Cerradas» used to hide past
     * the right edge of a narrow list).
     */
    wrap?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();

const scroller = ref<HTMLElement | null>(null);
const track = ref<HTMLElement | null>(null);
const thumb = ref({ x: 0, y: 0, width: 0, height: 0, visible: false });
/* No glide on the first placement: the thumb appears where it belongs. */
const placed = ref(false);

function measure(): void {
    const element = track.value?.querySelector<HTMLElement>(
        '[aria-pressed="true"]',
    );

    if (!element) {
        thumb.value = { ...thumb.value, visible: false };

        return;
    }

    thumb.value = {
        x: element.offsetLeft,
        y: element.offsetTop,
        width: element.offsetWidth,
        height: element.offsetHeight,
        visible: true,
    };

    // Keep the chosen tab in view when the strip scrolls.
    const strip = scroller.value;
    const left = (track.value?.offsetLeft ?? 0) + element.offsetLeft;

    if (
        strip &&
        (left < strip.scrollLeft ||
            left + element.offsetWidth > strip.scrollLeft + strip.clientWidth)
    ) {
        strip.scrollTo({
            left: left - 8,
            behavior: placed.value ? 'smooth' : 'auto',
        });
    }

    updateFade();
}

onMounted(() => {
    measure();
    requestAnimationFrame(() => (placed.value = true));
});

watch(
    () => [props.modelValue, props.options.map((option) => option.count)],
    () => void nextTick(measure),
);

useResizeObserver(track, () => measure());

/* Which edges hide more of the strip: those fade out, so it reads as scrollable. */
const fade = ref('');

function updateFade(): void {
    const strip = scroller.value;

    if (!strip) {
        return;
    }

    const left = strip.scrollLeft > 2;
    const right = strip.scrollLeft + strip.clientWidth < strip.scrollWidth - 2;

    fade.value = [left && 'left', right && 'right'].filter(Boolean).join(' ');
}

useResizeObserver(scroller, updateFade);
onMounted(updateFade);

/*
 * A mouse wheel scrolls the strip sideways: without it, reaching the tabs
 * past the edge needed a trackpad or Shift + wheel.
 */
function onWheel(event: WheelEvent): void {
    const strip = scroller.value;

    if (
        props.wrap ||
        !strip ||
        strip.scrollWidth <= strip.clientWidth ||
        Math.abs(event.deltaX) >= Math.abs(event.deltaY)
    ) {
        return;
    }

    event.preventDefault();
    strip.scrollLeft += event.deltaY;
}

function onKeydown(event: KeyboardEvent): void {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        return;
    }

    const buttons = [
        ...(track.value?.querySelectorAll<HTMLButtonElement>(
            'button[data-filter-tab]',
        ) ?? []),
    ];
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement);

    if (index === -1) {
        return;
    }

    event.preventDefault();

    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? buttons.length - 1
              : (index +
                    (event.key === 'ArrowRight' ? 1 : -1) +
                    buttons.length) %
                buttons.length;

    buttons[next]?.focus();
}

const thumbStyle = computed(() => ({
    width: `${thumb.value.width}px`,
    height: `${thumb.value.height}px`,
    transform: `translate(${thumb.value.x}px, ${thumb.value.y}px)`,
    opacity: thumb.value.visible ? 1 : 0,
}));
</script>

<template>
    <div
        ref="scroller"
        :class="
            wrap
                ? 'flex min-w-0 flex-wrap items-center gap-1.5'
                : 'filter-tabs-scroll flex min-w-0 items-center gap-1 overflow-x-auto'
        "
        :data-fade="(!wrap && fade) || undefined"
        @scroll.passive="updateFade"
        @wheel="onWheel"
    >
        <div
            ref="track"
            class="relative flex items-center gap-px rounded-lg border border-border bg-muted p-0.5"
            :class="wrap ? 'max-w-full min-w-0 flex-wrap' : 'shrink-0'"
            role="group"
            :aria-label="label"
            @keydown="onKeydown"
        >
            <!-- The thumb: a quiet tint under the chosen tab. -->
            <span
                class="pointer-events-none absolute top-0 left-0 rounded-md border border-border bg-card shadow-(--shadow-control)"
                :class="placed && 'filter-tabs-thumb'"
                :style="thumbStyle"
                aria-hidden="true"
            />
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                v-bind="option.attrs"
                :class="
                    cn(
                        'relative z-[1] inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md px-2.5 text-[0.8125rem] whitespace-nowrap outline-none',
                        'transition-colors duration-(--duration-fast) ease-(--ease-standard) focus-visible:ring-2 focus-visible:ring-ring/50 pointer-coarse:h-9',
                        modelValue === option.value
                            ? 'font-semibold text-foreground'
                            : 'font-medium text-muted-foreground hover:text-foreground',
                    )
                "
                :aria-pressed="modelValue === option.value"
                :title="option.title"
                data-filter-tab
                @click="emit('update:modelValue', option.value)"
            >
                <component
                    :is="option.icon"
                    v-if="option.icon"
                    class="size-3.5 shrink-0"
                    :class="option.attention && 'text-warning'"
                    aria-hidden="true"
                />
                <span class="truncate">{{ option.label }}</span>
                <CountBadge
                    v-if="option.count !== undefined && option.count !== null"
                    :value="option.count"
                    :tone="option.attention ? 'warning' : 'plain'"
                />
                <span v-if="option.srLabel" class="sr-only"
                    >: {{ option.srLabel }}</span
                >
            </button>
        </div>
        <slot name="after" />
    </div>
</template>
