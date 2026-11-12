<script setup lang="ts">
import { usePreferredReducedMotion } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { StorageState } from '@/types/storage';

/**
 * Bedside-monitor trace of the storage: a sweep that redraws the line from
 * left to right, like an electrocardiograph.
 *
 * It only beats while the page keeps receiving real answers:
 *  - `beat` increments each time a live check comes back; that draws a
 *    tall, confirmed beat and leaves a mark under the trace;
 *  - while the last answer is recent (`live`) the rhythm continues between
 *    checks with smaller beats: steady when the state is "ok", irregular
 *    (uneven intervals, weak and skipped beats) when it is "warning";
 *  - "down", or no answer for too long, is a flat line.
 *
 * With reduced motion nothing moves: a still trace of the same rhythm is
 * drawn and the text next to the monitor carries the state. The sweep
 * also stops while the tab is hidden.
 */
const props = defineProps<{
    state: StorageState;
    /** Still receiving answers from the server. */
    live: boolean;
    /** Increments on every confirmed check. */
    beat: number;
}>();

const HEIGHT = 136;
const BASELINE = 82;
const AMPLITUDE = 58;
const STEP = 3;
const SPEED = 132;
const GAP = 14;
const BEAT_SECONDS = 0.62;

const root = ref<HTMLElement | null>(null);
const width = ref(960);
const reducedMotion = usePreferredReducedMotion();
const still = computed(() => reducedMotion.value === 'reduce');

const flat = computed(() => props.state === 'down' || !props.live);

let samples: number[] = [];
const head = ref(0);
const pathBefore = ref('');
const pathAfter = ref('');
const dot = ref({ x: 0, y: BASELINE });
/** Marks of confirmed checks: the sweep erases them on its next lap. */
const marks = ref<{ x: number; lap: number }[]>([]);

type Beat = { start: number; strength: number };

let beats: Beat[] = [];
let nextBeatAt = 0;
let clock = 0;
let lap = 0;
let carry = 0;
let frame = 0;
let last = 0;
let observer: ResizeObserver | null = null;

/** One heartbeat (P wave, QRS complex, T wave) for a phase from 0 to 1. */
function complex(phase: number): number {
    const bump = (center: number, spread: number, height: number): number =>
        height * Math.exp(-((phase - center) ** 2) / (2 * spread ** 2));

    return (
        bump(0.16, 0.035, 0.11) -
        bump(0.3, 0.012, 0.16) +
        bump(0.335, 0.014, 1) -
        bump(0.375, 0.014, 0.28) +
        bump(0.62, 0.06, 0.22)
    );
}

function valueAt(time: number): number {
    let value = 0.012 * Math.sin(time * 1.7);

    for (const beat of beats) {
        const phase = (time - beat.start) / BEAT_SECONDS;

        if (phase >= 0 && phase <= 1) {
            value += complex(phase) * beat.strength;
        }
    }

    return value;
}

/** Interval and strength of the next resting beat for the current state. */
function restingBeat(): { after: number; strength: number } {
    if (props.state === 'warning') {
        const skipped = Math.random() < 0.18;

        return {
            after: 0.62 + Math.random() * 1.25 + (skipped ? 1.1 : 0),
            strength: 0.34 + Math.random() * 0.36,
        };
    }

    return { after: 1.12, strength: 0.62 };
}

function schedule(): void {
    if (flat.value) {
        return;
    }

    if (clock >= nextBeatAt) {
        const next = restingBeat();

        beats.push({ start: clock, strength: next.strength });
        nextBeatAt = clock + next.after;
    }

    beats = beats.filter((beat) => clock - beat.start <= BEAT_SECONDS + 0.1);
}

function y(value: number): number {
    return BASELINE - value * AMPLITUDE;
}

function line(from: number, to: number): string {
    let path = '';

    for (let index = from; index < to; index++) {
        path += `${index === from ? 'M' : 'L'}${index * STEP} ${y(samples[index] ?? 0).toFixed(1)}`;
    }

    return path;
}

function draw(): void {
    const total = samples.length;
    const at = Math.min(head.value, total);

    pathBefore.value = at > 1 ? line(0, at) : '';
    pathAfter.value = at + GAP < total - 1 ? line(at + GAP, total) : '';
    dot.value = {
        x: Math.max(0, at - 1) * STEP,
        y: y(samples[Math.max(0, at - 1)] ?? 0),
    };
}

/** A still trace of the rhythm: the first paint and reduced motion. */
function fillStill(): void {
    const total = Math.max(8, Math.ceil(width.value / STEP) + 1);
    const interval = props.state === 'warning' ? [0.8, 1.7, 1.05, 2.1] : [1.12];
    const strengths =
        props.state === 'warning' ? [0.6, 0.36, 0.52, 0.4] : [0.62];
    const starts: Beat[] = [];

    if (!flat.value) {
        let time = 0.35;
        let index = 0;

        while (time < (total * STEP) / SPEED) {
            starts.push({
                start: time,
                strength: strengths[index % strengths.length],
            });
            time += interval[index % interval.length];
            index++;
        }
    }

    const previous = beats;

    beats = starts;
    samples = Array.from({ length: total }, (_, index) =>
        valueAt((index * STEP) / SPEED),
    );
    beats = previous;
}

function tick(now: number): void {
    frame = requestAnimationFrame(tick);

    if (last === 0) {
        last = now;

        return;
    }

    // A tab that was throttled never jumps the sweep forward.
    const delta = Math.min(0.05, (now - last) / 1000);

    last = now;
    carry += delta * SPEED;

    const total = samples.length;
    let advanced = false;

    while (carry >= STEP) {
        carry -= STEP;
        clock += STEP / SPEED;
        schedule();

        if (head.value >= total) {
            head.value = 0;
            lap++;
        }

        samples[head.value] = valueAt(clock);
        head.value++;
        advanced = true;
    }

    if (advanced) {
        const x = head.value * STEP;

        // The sweep erases, on a later lap, the marks it passes over.
        const erased = (mark: { x: number; lap: number }): boolean =>
            mark.lap < lap && mark.x >= x && mark.x < x + GAP * STEP;

        if (marks.value.some(erased)) {
            marks.value = marks.value.filter((mark) => !erased(mark));
        }

        draw();
    }
}

function start(): void {
    cancelAnimationFrame(frame);
    last = 0;

    if (still.value || document.hidden) {
        return;
    }

    frame = requestAnimationFrame(tick);
}

function reset(): void {
    fillStill();
    head.value = still.value ? samples.length : 0;
    marks.value = [];
    draw();
}

function onVisibility(): void {
    start();
}

onMounted(() => {
    if (root.value) {
        width.value = Math.max(240, root.value.clientWidth);
        observer = new ResizeObserver(([entry]) => {
            const next = Math.max(240, Math.round(entry.contentRect.width));

            if (Math.abs(next - width.value) > 2) {
                width.value = next;
                reset();
            }
        });
        observer.observe(root.value);
    }

    reset();
    start();
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    cancelAnimationFrame(frame);
    observer?.disconnect();
    document.removeEventListener('visibilitychange', onVisibility);
});

// A confirmed check: a full beat right at the head, and its mark.
watch(
    () => props.beat,
    () => {
        if (flat.value || still.value) {
            return;
        }

        beats.push({ start: clock, strength: 1 });
        nextBeatAt = clock + restingBeat().after;
        // Under the peak of that beat; past the right edge it lands on the next lap.
        const span = samples.length * STEP;
        const x = head.value * STEP + 0.335 * BEAT_SECONDS * SPEED;

        marks.value = [
            ...marks.value,
            x < span ? { x, lap } : { x: x - span, lap: lap + 1 },
        ];
    },
);

watch([() => props.state, flat, still], () => {
    beats = [];
    nextBeatAt = clock + 0.2;

    if (still.value) {
        reset();
    }

    start();
});

const viewBox = computed(() => `0 0 ${width.value} ${HEIGHT}`);
const tone = computed(() => (flat.value ? 'down' : props.state));
</script>

<template>
    <div
        ref="root"
        class="heartbeat relative w-full overflow-hidden"
        :data-tone="tone"
        role="img"
        :aria-label="
            flat
                ? 'Monitor del almacenamiento: sin respuesta'
                : state === 'warning'
                  ? 'Monitor del almacenamiento: responde con avisos'
                  : 'Monitor del almacenamiento: responde con normalidad'
        "
    >
        <svg
            :viewBox="viewBox"
            :height="HEIGHT"
            width="100%"
            preserveAspectRatio="none"
            class="block"
            aria-hidden="true"
        >
            <line
                x1="0"
                :x2="width"
                :y1="BASELINE"
                :y2="BASELINE"
                class="heartbeat-baseline"
            />
            <path :d="pathAfter" class="heartbeat-trace heartbeat-trace-old" />
            <path :d="pathBefore" class="heartbeat-trace" />
            <line
                v-for="mark in marks"
                :key="`${mark.lap}-${mark.x}`"
                :x1="mark.x"
                :x2="mark.x"
                :y1="HEIGHT - 12"
                :y2="HEIGHT - 4"
                class="heartbeat-mark"
            />
            <g v-if="!still">
                <circle :cx="dot.x" :cy="dot.y" r="9" class="heartbeat-halo" />
                <circle :cx="dot.x" :cy="dot.y" r="3" class="heartbeat-dot" />
            </g>
        </svg>
    </div>
</template>

<style scoped>
/*
  Fixed monitor colours (not the palette): green, amber and red must mean
  the same on every palette, and they sit on the ink surface in both themes.
*/
.heartbeat {
    --trace: oklch(0.82 0.17 155);
    color: var(--trace);
    background-image:
        linear-gradient(
            to right,
            color-mix(in oklch, var(--trace) 9%, transparent) 1px,
            transparent 1px
        ),
        linear-gradient(
            to bottom,
            color-mix(in oklch, var(--trace) 9%, transparent) 1px,
            transparent 1px
        );
    background-size: 24px 24px;
    background-position: -1px -1px;
    transition: color var(--duration-panel) var(--ease-standard);
}

.heartbeat[data-tone='warning'] {
    --trace: oklch(0.84 0.15 82);
}

.heartbeat[data-tone='down'] {
    --trace: oklch(0.72 0.19 25);
}

.heartbeat-baseline {
    stroke: currentColor;
    stroke-opacity: 0.16;
    stroke-width: 1;
    stroke-dasharray: 2 6;
    vector-effect: non-scaling-stroke;
}

.heartbeat-trace {
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linejoin: round;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
    filter: drop-shadow(
        0 0 5px color-mix(in oklch, var(--trace) 55%, transparent)
    );
}

.heartbeat-trace-old {
    stroke-opacity: 0.38;
    filter: none;
}

.heartbeat-mark {
    stroke: currentColor;
    stroke-opacity: 0.7;
    stroke-width: 2;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
}

.heartbeat-dot {
    fill: currentColor;
}

.heartbeat-halo {
    fill: currentColor;
    fill-opacity: 0.22;
}
</style>
