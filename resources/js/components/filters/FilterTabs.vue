<script setup lang="ts" generic="T extends string">
import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import { cn } from '@/lib/utils';
import type { FilterTabOption } from '@/components/filters/types';
import './filters.css';

/**
 * The views of a list as one track of mutually exclusive tabs (Bandeja,
 * Pendientes, Mías, Cerradas… or Todos, Privados, Grupos…). The chosen tab
 * sits on a raised thumb that glides to the new one, the only motion of the
 * header, so the eye follows what changed. Each tab may carry a live counter
 * (CountBadge, bumps when it goes up).
 *
 * Accessibility: a group of toggle buttons (aria-pressed), each one reached
 * with Tab; the arrow keys, Home and End also move between them. When no tab
 * matches the applied filters (`modelValue` null) none is pressed. On narrow
 * lists the track scrolls sideways and keeps the chosen tab in view.
 */
const props = defineProps<{
    options: FilterTabOption<T>[];
    /** The tab that matches the applied filters, or null. */
    modelValue: T | null;
    /** Accessible name of the group. */
    label: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: T] }>();

const track = ref<HTMLElement | null>(null);
const thumb = ref({ x: 0, width: 0, visible: false });
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
        width: element.offsetWidth,
        visible: true,
    };

    // Keep the chosen tab in view when the track scrolls.
    const scroller = track.value;

    if (
        scroller &&
        (element.offsetLeft < scroller.scrollLeft ||
            element.offsetLeft + element.offsetWidth >
                scroller.scrollLeft + scroller.clientWidth)
    ) {
        scroller.scrollTo({
            left: element.offsetLeft - 8,
            behavior: placed.value ? 'smooth' : 'auto',
        });
    }
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
    transform: `translateX(${thumb.value.x}px)`,
    opacity: thumb.value.visible ? 1 : 0,
}));
</script>

<template>
    <div
        class="rounded-full bg-surface-sunken p-1 shadow-[inset_0_1px_2px_color-mix(in_oklch,var(--shadow-ink,black)_8%,transparent)] ring-1 ring-border/70 ring-inset"
    >
        <div
            ref="track"
            class="filter-tabs-scroll relative flex gap-0.5 overflow-x-auto"
            role="group"
            :aria-label="label"
            @keydown="onKeydown"
        >
            <!-- The thumb: a raised pill tinted by the palette. -->
            <span
                class="pointer-events-none absolute inset-y-0 left-0 rounded-full bg-linear-to-b from-card to-[color-mix(in_oklch,var(--primary)_7%,var(--card))] shadow-(--shadow-float,var(--shadow-control)) ring-1 ring-primary/25"
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
                        'relative z-[1] inline-flex h-8 min-w-0 flex-auto shrink-0 items-center justify-center gap-1.5 rounded-full px-3 text-[0.8125rem] font-medium whitespace-nowrap outline-none',
                        'transition-[color] duration-(--duration-fast) ease-(--ease-standard) focus-visible:ring-[3px] focus-visible:ring-primary/30 active:scale-[0.95] motion-safe:transition-[color,transform] pointer-coarse:h-10',
                        modelValue === option.value
                            ? 'font-semibold text-foreground'
                            : option.attention
                              ? 'font-semibold text-foreground hover:bg-warning/10'
                              : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
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
                    class="size-3.5 shrink-0 transition-colors duration-(--duration-fast)"
                    :class="
                        option.attention
                            ? 'text-warning'
                            : modelValue === option.value && 'text-primary'
                    "
                    aria-hidden="true"
                />
                <span class="truncate">{{ option.label }}</span>
                <CountBadge
                    v-if="option.count !== undefined && option.count !== null"
                    :value="option.count"
                    :tone="
                        option.attention
                            ? 'warning'
                            : modelValue === option.value
                              ? 'primary'
                              : 'muted'
                    "
                    :halo="option.attention"
                />
                <span v-if="option.srLabel" class="sr-only"
                    >: {{ option.srLabel }}</span
                >
            </button>
        </div>
    </div>
</template>
