<script setup lang="ts">
import { onClickOutside, useElementSize } from '@vueuse/core';
import { computed, onMounted, ref, useTemplateRef } from 'vue';
import {
    chartTokens,
    formatCompact,
    formatDuration,
    formatDurationTick,
    formatNumber,
    useChartTheme,
} from '@/composables/useChartTheme';
import type { BucketLabel } from '@/composables/useChartTheme';
import ChartTooltip from './ChartTooltip.vue';
import type { ChartTooltipRow, LineSeries } from './types';

/**
 * Line chart drawn in SVG (no chart library): up to three series over one
 * shared axis. A null value is a gap: the line is cut there and never drawn
 * as 0; a real 0 sits on the baseline. The first series with `area` gets a
 * light wash. Pointer and keyboard (←/→, Inicio/Fin) move a crosshair with
 * a readout of every series; the keyboard readout is also announced.
 */
const props = withDefaults(
    defineProps<{
        labels: BucketLabel[];
        series: LineSeries[];
        yFormat?: 'number' | 'duration';
        /** Accessible summary of the chart (role="img"). */
        description: string;
        /** Text of a missing value in the readout. */
        nullLabel?: string;
    }>(),
    { yFormat: 'number', nullLabel: 'Sin datos' },
);

const { duration, reducedMotion } = useChartTheme();

const root = useTemplateRef<HTMLElement>('root');
const { width: measuredWidth } = useElementSize(root);
const width = computed(() =>
    Math.max(240, Math.round(measuredWidth.value || 640)),
);
const height = computed(() => (width.value < 560 ? 180 : 220));

const seriesColor = (series: LineSeries, index: number): string =>
    series.color ??
    (index === 0
        ? chartTokens.primary
        : index === 1
          ? chartTokens.secondary
          : chartTokens.comparison);

const durationSteps = [
    1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600,
    43200, 86400, 172800, 432000, 864000,
];

/** Rounded step of the axis (1/2/5 × 10ⁿ, or whole time units) so the gridlines fall on readable values. */
const step = computed(() => {
    const top = Math.max(
        0,
        ...props.series.flatMap((series) =>
            series.values.filter((value): value is number => value !== null),
        ),
    );
    const raw = top / 4;

    if (props.yFormat === 'duration') {
        return (
            durationSteps.find((candidate) => candidate >= raw) ??
            Math.ceil(raw / 86400) * 86400
        );
    }

    const safe = Math.max(1, raw);
    const magnitude = 10 ** Math.floor(Math.log10(safe));
    const nice =
        [1, 2, 5, 10].find((factor) => factor * magnitude >= safe) ?? 10;

    return nice * magnitude;
});

const ticks = computed(() =>
    [0, 1, 2, 3, 4].map((index) => index * step.value),
);
const max = computed(() => step.value * 4);

function tickLabel(value: number): string {
    return props.yFormat === 'duration'
        ? formatDurationTick(value)
        : formatCompact(value);
}

function valueLabel(value: number | null): string {
    if (value === null) {
        return props.nullLabel;
    }

    return props.yFormat === 'duration'
        ? formatDuration(value)
        : formatNumber(value);
}

const padding = computed(() => {
    const longest = Math.max(
        ...ticks.value.map((tick) => tickLabel(tick).length),
    );

    return {
        top: 12,
        right: 20,
        bottom: 28,
        left: Math.ceil(longest * 7.4) + 12,
    };
});

const plotWidth = computed(
    () => width.value - padding.value.left - padding.value.right,
);
const plotHeight = computed(
    () => height.value - padding.value.top - padding.value.bottom,
);
const count = computed(() => props.labels.length);

function x(index: number): number {
    return (
        padding.value.left +
        (count.value <= 1
            ? plotWidth.value / 2
            : (index / (count.value - 1)) * plotWidth.value)
    );
}

function y(value: number): number {
    return (
        padding.value.top +
        plotHeight.value -
        (value / max.value) * plotHeight.value
    );
}

const baseline = computed(() => y(0));

/** Runs of consecutive measured values: each one is a piece of line. */
function runs(values: (number | null)[]): { index: number; value: number }[][] {
    const result: { index: number; value: number }[][] = [];
    let current: { index: number; value: number }[] = [];

    values.forEach((value, index) => {
        if (value === null) {
            if (current.length > 0) {
                result.push(current);
            }

            current = [];

            return;
        }

        current.push({ index, value });
    });

    if (current.length > 0) {
        result.push(current);
    }

    return result;
}

const drawnSeries = computed(() =>
    props.series.map((series, index) => {
        const pieces = runs(series.values);
        const lines = pieces.filter((piece) => piece.length > 1);

        return {
            ...series,
            color: seriesColor(series, index),
            line: lines
                .map((piece) =>
                    piece
                        .map(
                            (point, position) =>
                                `${position === 0 ? 'M' : 'L'}${x(point.index).toFixed(1)},${y(point.value).toFixed(1)}`,
                        )
                        .join(' '),
                )
                .join(' '),
            area: series.area
                ? lines
                      .map(
                          (piece) =>
                              `M${x(piece[0]!.index).toFixed(1)},${baseline.value.toFixed(1)} ` +
                              piece
                                  .map(
                                      (point) =>
                                          `L${x(point.index).toFixed(1)},${y(point.value).toFixed(1)}`,
                                  )
                                  .join(' ') +
                              ` L${x(piece[piece.length - 1]!.index).toFixed(1)},${baseline.value.toFixed(1)} Z`,
                      )
                      .join(' ')
                : '',
            /** Isolated values (both neighbours missing) are drawn as a dot. */
            dots: pieces
                .filter((piece) => piece.length === 1)
                .map((piece) => piece[0]!),
        };
    }),
);

const labelEvery = computed(() => {
    const fits = Math.max(2, Math.floor(plotWidth.value / 64));

    return Math.max(1, Math.ceil(count.value / fits));
});

/* Draw-in animation on mount only, skipped with reduced motion. */
const drawn = ref(false);

onMounted(() => {
    if (reducedMotion.value) {
        drawn.value = true;

        return;
    }

    requestAnimationFrame(() =>
        requestAnimationFrame(() => {
            drawn.value = true;
        }),
    );
});

const active = ref<number | null>(null);
const fromKeyboard = ref(false);

function pointAt(event: PointerEvent): void {
    const svg = event.currentTarget as SVGElement;
    const offset = event.clientX - svg.getBoundingClientRect().left;
    const relative =
        (offset - padding.value.left) / Math.max(1, plotWidth.value);
    const index =
        count.value <= 1 ? 0 : Math.round(relative * (count.value - 1));

    fromKeyboard.value = false;
    active.value = Math.min(count.value - 1, Math.max(0, index));
}

function onKeydown(event: KeyboardEvent): void {
    const last = count.value - 1;
    const current = active.value ?? -1;
    const next = {
        ArrowRight: Math.min(last, current + 1),
        ArrowLeft: Math.max(0, current < 0 ? 0 : current - 1),
        Home: 0,
        End: last,
    }[event.key];

    if (next === undefined || last < 0) {
        return;
    }

    event.preventDefault();
    fromKeyboard.value = true;
    active.value = next;
}

function onFocus(): void {
    if (active.value === null && count.value > 0) {
        fromKeyboard.value = true;
        active.value = 0;
    }
}

function clear(): void {
    active.value = null;
}

/**
 * Only a mouse leaving the chart hides the readout: on touch, pointerleave
 * fires right after the finger lifts, so the readout stays until the next
 * tap elsewhere (or on another point).
 */
function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        clear();
    }
}

onClickOutside(root, clear);

const tooltipRows = computed<ChartTooltipRow[]>(() => {
    if (active.value === null) {
        return [];
    }

    const index = active.value;

    return drawnSeries.value.map((series) => ({
        key: series.key,
        name: series.name,
        value: valueLabel(series.values[index] ?? null),
        color: series.color,
        shape: series.dashed ? 'dashed' : 'line',
    }));
});

const announcement = computed(() => {
    if (active.value === null || !fromKeyboard.value) {
        return '';
    }

    const label = props.labels[active.value]?.long ?? '';

    return `${label}: ${tooltipRows.value.map((row) => `${row.name} ${row.value}`).join(', ')}`;
});
</script>

<template>
    <div ref="root" class="relative w-full" data-line-chart>
        <svg
            :width="width"
            :height="height"
            :viewBox="`0 0 ${width} ${height}`"
            class="block max-w-full touch-pan-y overflow-visible focus-visible:outline-offset-4"
            role="img"
            :aria-label="description"
            tabindex="0"
            @pointermove="pointAt"
            @pointerdown="pointAt"
            @pointerleave="onPointerLeave"
            @focus="onFocus"
            @blur="clear"
            @keydown="onKeydown"
        >
            <g aria-hidden="true">
                <line
                    v-for="tick in ticks"
                    :key="`grid-${tick}`"
                    :x1="padding.left"
                    :x2="width - padding.right"
                    :y1="y(tick)"
                    :y2="y(tick)"
                    :style="{ stroke: chartTokens.grid }"
                    stroke-width="1"
                    shape-rendering="crispEdges"
                />
                <text
                    v-for="tick in ticks"
                    :key="`tick-${tick}`"
                    :x="padding.left - 8"
                    :y="y(tick) + 4"
                    text-anchor="end"
                    class="text-xs tabular-nums"
                    :style="{ fill: chartTokens.axis }"
                >
                    {{ tickLabel(tick) }}
                </text>
                <template v-for="(label, index) in labels" :key="`x-${index}`">
                    <text
                        v-if="index % labelEvery === 0"
                        :x="x(index)"
                        :y="height - 8"
                        text-anchor="middle"
                        class="text-xs"
                        :style="{ fill: chartTokens.axis }"
                    >
                        {{ label.short }}
                    </text>
                </template>
            </g>

            <g aria-hidden="true">
                <template v-for="series in drawnSeries" :key="series.key">
                    <path
                        v-if="series.area"
                        :d="series.area"
                        :style="{
                            fill: series.color,
                            opacity: drawn ? 0.12 : 0,
                            transition: `opacity ${duration}ms ease-out`,
                        }"
                    />
                </template>
                <template
                    v-for="series in drawnSeries"
                    :key="`line-${series.key}`"
                >
                    <path
                        v-if="series.line"
                        :d="series.line"
                        fill="none"
                        stroke-width="2"
                        stroke-linejoin="round"
                        stroke-linecap="round"
                        :pathLength="series.dashed ? undefined : 1"
                        :data-series="series.key"
                        :style="
                            series.dashed
                                ? {
                                      stroke: series.color,
                                      strokeDasharray: '5 4',
                                      opacity: drawn ? 1 : 0,
                                      transition: `opacity ${duration}ms ease-out`,
                                  }
                                : {
                                      stroke: series.color,
                                      strokeDasharray: 1,
                                      strokeDashoffset: drawn ? 0 : 1,
                                      transition: `stroke-dashoffset ${duration}ms ease-out`,
                                  }
                        "
                    />
                    <circle
                        v-for="dot in series.dots"
                        :key="`dot-${series.key}-${dot.index}`"
                        :cx="x(dot.index)"
                        :cy="y(dot.value)"
                        r="3"
                        :style="{ fill: series.color }"
                    />
                </template>
            </g>

            <g v-if="active !== null" aria-hidden="true">
                <line
                    :x1="x(active)"
                    :x2="x(active)"
                    :y1="padding.top"
                    :y2="baseline"
                    :style="{ stroke: chartTokens.axis }"
                    stroke-opacity="0.45"
                    stroke-width="1"
                />
                <template
                    v-for="series in drawnSeries"
                    :key="`marker-${series.key}`"
                >
                    <circle
                        v-if="
                            series.values[active] !== null &&
                            series.values[active] !== undefined
                        "
                        :cx="x(active)"
                        :cy="y(series.values[active] as number)"
                        r="4"
                        stroke-width="2"
                        :style="{
                            fill: series.color,
                            stroke: chartTokens.surface,
                        }"
                    />
                </template>
            </g>
        </svg>

        <ChartTooltip
            v-if="active !== null"
            :title="labels[active]?.long ?? ''"
            :rows="tooltipRows"
            :x="x(active)"
            :y="padding.top"
            :container-width="width"
            :side="x(active) > width / 2 ? 'left' : 'right'"
        />
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    </div>
</template>
