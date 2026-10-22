<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Light SVG line chart (no chart library): one main series in the platform
 * color and an optional comparison series, muted and dashed, on the same
 * axis. Hovering or focusing the chart shows the exact values of a point.
 */
type Series = { name: string; values: number[] };

const props = defineProps<{
    labels: string[];
    series: Series;
    comparison?: Series | null;
    /** Accessible description of the chart. */
    description: string;
}>();

const width = 640;
const height = 200;
const padding = { top: 12, right: 12, bottom: 28, left: 36 };
const plotWidth = width - padding.left - padding.right;
const plotHeight = height - padding.top - padding.bottom;

const hovered = ref<number | null>(null);

/**
 * Rounded step of the vertical axis (1, 2 or 5 × 10ⁿ) so the four gridlines
 * fall on whole, readable numbers.
 */
const step = computed(() => {
    const top = Math.max(
        0,
        ...props.series.values,
        ...(props.comparison?.values ?? []),
    );
    const raw = Math.max(1, top / 4);
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const nice =
        [1, 2, 5, 10].find((factor) => factor * magnitude >= raw) ?? 10;

    return nice * magnitude;
});

const max = computed(() => step.value * 4);

const ticks = computed(() =>
    [0, 1, 2, 3, 4].map((index) => index * step.value),
);

function x(index: number): number {
    const count = Math.max(1, props.labels.length - 1);

    return (
        padding.left +
        (props.labels.length === 1
            ? plotWidth / 2
            : (index / count) * plotWidth)
    );
}

function y(value: number): number {
    return padding.top + plotHeight - (value / max.value) * plotHeight;
}

function path(values: number[]): string {
    return values
        .map(
            (value, index) =>
                `${index === 0 ? 'M' : 'L'}${x(index).toFixed(1)},${y(value).toFixed(1)}`,
        )
        .join(' ');
}

const labelEvery = computed(() =>
    Math.max(1, Math.ceil(props.labels.length / 7)),
);

const slotWidth = computed(() => plotWidth / Math.max(1, props.labels.length));

const tooltipLeft = computed(() =>
    hovered.value === null ? 0 : (x(hovered.value) / width) * 100,
);

function move(step: number): void {
    const last = props.labels.length - 1;
    hovered.value =
        hovered.value === null
            ? 0
            : Math.min(last, Math.max(0, hovered.value + step));
}
</script>

<template>
    <div class="relative">
        <svg
            :viewBox="`0 0 ${width} ${height}`"
            class="h-auto w-full overflow-visible"
            role="img"
            :aria-label="description"
            tabindex="0"
            @mouseleave="hovered = null"
            @blur="hovered = null"
            @keydown.right.prevent="move(1)"
            @keydown.left.prevent="move(-1)"
        >
            <g class="text-border">
                <line
                    v-for="tick in ticks"
                    :key="tick"
                    :x1="padding.left"
                    :x2="width - padding.right"
                    :y1="y(tick)"
                    :y2="y(tick)"
                    stroke="currentColor"
                    stroke-width="1"
                />
            </g>
            <g class="fill-muted-foreground text-[10px]">
                <text
                    v-for="tick in ticks"
                    :key="`label-${tick}`"
                    :x="padding.left - 6"
                    :y="y(tick) + 3"
                    text-anchor="end"
                >
                    {{ tick.toLocaleString('es-MX') }}
                </text>
                <template v-for="(label, index) in labels" :key="label + index">
                    <text
                        v-if="
                            index % labelEvery === 0 ||
                            index === labels.length - 1
                        "
                        :x="x(index)"
                        :y="height - 8"
                        text-anchor="middle"
                    >
                        {{ label }}
                    </text>
                </template>
            </g>
            <path
                v-if="comparison"
                :d="path(comparison.values)"
                fill="none"
                class="stroke-muted-foreground/60"
                stroke-width="2"
                stroke-dasharray="4 4"
                stroke-linejoin="round"
            />
            <path
                :d="path(series.values)"
                fill="none"
                class="stroke-primary"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
            />
            <g v-if="hovered !== null">
                <line
                    :x1="x(hovered)"
                    :x2="x(hovered)"
                    :y1="padding.top"
                    :y2="padding.top + plotHeight"
                    class="stroke-muted-foreground/40"
                    stroke-width="1"
                />
                <circle
                    :cx="x(hovered)"
                    :cy="y(series.values[hovered] ?? 0)"
                    r="4"
                    class="fill-primary stroke-card"
                    stroke-width="2"
                />
            </g>
            <rect
                v-for="(label, index) in labels"
                :key="`hit-${index}`"
                :x="x(index) - slotWidth / 2"
                :y="padding.top"
                :width="slotWidth"
                :height="plotHeight"
                fill="transparent"
                @mouseenter="hovered = index"
            />
        </svg>
        <div
            v-if="hovered !== null"
            class="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border bg-popover px-2.5 py-1.5 text-xs shadow-md"
            :style="{ left: `${tooltipLeft}%` }"
            role="status"
        >
            <p class="font-medium">{{ labels[hovered] }}</p>
            <p class="flex items-center gap-1.5 tabular-nums">
                <span
                    class="inline-block h-0.5 w-3 rounded bg-primary"
                    aria-hidden="true"
                />
                {{ series.name }}:
                {{ (series.values[hovered] ?? 0).toLocaleString('es-MX') }}
            </p>
            <p
                v-if="comparison"
                class="flex items-center gap-1.5 text-muted-foreground tabular-nums"
            >
                <span
                    class="inline-block w-3 border-t-2 border-dashed border-muted-foreground/60"
                    aria-hidden="true"
                />
                {{ comparison.name }}:
                {{ (comparison.values[hovered] ?? 0).toLocaleString('es-MX') }}
            </p>
        </div>
        <div
            v-if="comparison"
            class="mt-2 flex flex-wrap gap-4 text-xs text-muted-foreground"
        >
            <span class="flex items-center gap-1.5">
                <span
                    class="inline-block h-0.5 w-4 rounded bg-primary"
                    aria-hidden="true"
                />
                {{ series.name }}
            </span>
            <span class="flex items-center gap-1.5">
                <span
                    class="inline-block w-4 border-t-2 border-dashed border-muted-foreground/60"
                    aria-hidden="true"
                />
                {{ comparison.name }}
            </span>
        </div>
    </div>
</template>
