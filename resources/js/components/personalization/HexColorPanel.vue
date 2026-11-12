<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Input } from '@/components/ui/input';

/**
 * Color picker of the platform (replaces the browser's own color dialog):
 * a saturation / brightness area, a hue track and the "#rrggbb" value, all
 * usable with the pointer and the keyboard.
 *
 * The model is always a lowercase "#rrggbb" string. `swatches` adds quick
 * choices under the value.
 */
const props = withDefaults(
    defineProps<{
        /** Accessible name of the value field ("Color de acento"). */
        label?: string;
        swatches?: string[];
    }>(),
    {
        label: 'Color',
        swatches: () => [],
    },
);

const model = defineModel<string>({ required: true });

type Hsv = { h: number; s: number; v: number };

const HEX = /^#[0-9a-f]{6}$/i;

function hexToHsv(hex: string): Hsv {
    const r = parseInt(hex.slice(1, 3), 16) / 255;
    const g = parseInt(hex.slice(3, 5), 16) / 255;
    const b = parseInt(hex.slice(5, 7), 16) / 255;
    const max = Math.max(r, g, b);
    const delta = max - Math.min(r, g, b);
    let h = 0;

    if (delta !== 0) {
        if (max === r) {
            h = ((g - b) / delta) % 6;
        } else if (max === g) {
            h = (b - r) / delta + 2;
        } else {
            h = (r - g) / delta + 4;
        }
    }

    return {
        h: (h * 60 + 360) % 360,
        s: max === 0 ? 0 : delta / max,
        v: max,
    };
}

function hsvToHex({ h, s, v }: Hsv): string {
    const channel = (n: number): string => {
        const k = (n + h / 60) % 6;
        const value = v - v * s * Math.max(0, Math.min(k, 4 - k, 1));

        return Math.round(value * 255)
            .toString(16)
            .padStart(2, '0');
    };

    return `#${channel(5)}${channel(3)}${channel(1)}`;
}

/**
 * The hue and saturation are kept apart from the model: black, white and
 * the greys have no hue of their own, and the handles must not jump while
 * the color passes through them.
 */
const hsv = ref<Hsv>(
    HEX.test(model.value) ? hexToHsv(model.value) : { h: 230, s: 0.6, v: 0.9 },
);
const text = ref(HEX.test(model.value) ? model.value.toLowerCase() : '');

watch(model, (value) => {
    if (!HEX.test(value)) {
        return;
    }

    if (value.toLowerCase() !== hsvToHex(hsv.value)) {
        hsv.value = hexToHsv(value);
    }

    text.value = value.toLowerCase();
});

function commit(next: Hsv): void {
    hsv.value = next;
    const hex = hsvToHex(next);
    text.value = hex;
    model.value = hex;
}

const clamp = (value: number): number => Math.min(1, Math.max(0, value));

function track(
    event: PointerEvent,
    apply: (x: number, y: number) => void,
): void {
    const element = event.currentTarget as HTMLElement;
    const box = element.getBoundingClientRect();
    const move = (pointer: PointerEvent): void =>
        apply(
            clamp((pointer.clientX - box.left) / box.width),
            clamp((pointer.clientY - box.top) / box.height),
        );
    const stop = (): void => {
        element.removeEventListener('pointermove', move);
        element.removeEventListener('pointerup', stop);
        element.removeEventListener('pointercancel', stop);
    };

    element.setPointerCapture(event.pointerId);
    element.addEventListener('pointermove', move);
    element.addEventListener('pointerup', stop);
    element.addEventListener('pointercancel', stop);
    element.focus();
    move(event);
}

function onAreaPointer(event: PointerEvent): void {
    track(event, (x, y) => commit({ ...hsv.value, s: x, v: 1 - y }));
}

function onHuePointer(event: PointerEvent): void {
    track(event, (x) => commit({ ...hsv.value, h: Math.round(x * 359) }));
}

function onAreaKey(event: KeyboardEvent): void {
    const step = event.shiftKey ? 0.1 : 0.02;
    const moves: Record<string, Partial<Hsv>> = {
        ArrowLeft: { s: clamp(hsv.value.s - step) },
        ArrowRight: { s: clamp(hsv.value.s + step) },
        ArrowUp: { v: clamp(hsv.value.v + step) },
        ArrowDown: { v: clamp(hsv.value.v - step) },
    };
    const change = moves[event.key];

    if (change) {
        event.preventDefault();
        commit({ ...hsv.value, ...change });
    }
}

function onHueKey(event: KeyboardEvent): void {
    const step = event.shiftKey ? 15 : 3;
    const moves: Record<string, number> = {
        ArrowLeft: hsv.value.h - step,
        ArrowDown: hsv.value.h - step,
        ArrowRight: hsv.value.h + step,
        ArrowUp: hsv.value.h + step,
        Home: 0,
        End: 359,
    };
    const next = moves[event.key];

    if (next !== undefined) {
        event.preventDefault();
        commit({ ...hsv.value, h: Math.min(359, Math.max(0, next)) });
    }
}

function onText(value: string | number): void {
    const typed = String(value).trim();
    text.value = typed;
    const hex = typed.startsWith('#') ? typed : `#${typed}`;

    if (HEX.test(hex)) {
        hsv.value = hexToHsv(hex);
        model.value = hex.toLowerCase();
    }
}

const hueColor = computed(() => `hsl(${hsv.value.h} 100% 50%)`);
const current = computed(() => hsvToHex(hsv.value));
</script>

<template>
    <div class="grid gap-3" data-slot="hex-color-panel">
        <div
            role="slider"
            tabindex="0"
            aria-label="Saturación y brillo"
            :aria-valuetext="`Saturación ${Math.round(hsv.s * 100)} %, brillo ${Math.round(hsv.v * 100)} %`"
            :aria-valuenow="Math.round(hsv.s * 100)"
            aria-valuemin="0"
            aria-valuemax="100"
            class="relative h-36 w-full cursor-crosshair touch-none rounded-lg border outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            :style="{
                backgroundColor: hueColor,
                backgroundImage:
                    'linear-gradient(to top, #000, transparent), linear-gradient(to right, #fff, transparent)',
            }"
            @pointerdown.prevent="onAreaPointer"
            @keydown="onAreaKey"
        >
            <span
                aria-hidden="true"
                class="pointer-events-none absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgb(0_0_0/0.35),0_2px_6px_rgb(0_0_0/0.3)]"
                :style="{
                    left: `${hsv.s * 100}%`,
                    top: `${(1 - hsv.v) * 100}%`,
                    backgroundColor: current,
                }"
            />
        </div>

        <div
            role="slider"
            tabindex="0"
            aria-label="Tono"
            :aria-valuenow="Math.round(hsv.h)"
            aria-valuemin="0"
            aria-valuemax="359"
            class="relative h-3 w-full cursor-pointer touch-none rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            style="
                background-image: linear-gradient(
                    to right,
                    #f00,
                    #ff0,
                    #0f0,
                    #0ff,
                    #00f,
                    #f0f,
                    #f00
                );
            "
            @pointerdown.prevent="onHuePointer"
            @keydown="onHueKey"
        >
            <span
                aria-hidden="true"
                class="pointer-events-none absolute top-1/2 size-4.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_1px_rgb(0_0_0/0.35),0_2px_6px_rgb(0_0_0/0.3)]"
                :style="{
                    left: `${(hsv.h / 359) * 100}%`,
                    backgroundColor: hueColor,
                }"
            />
        </div>

        <div class="flex items-center gap-2">
            <span
                aria-hidden="true"
                class="size-9 shrink-0 rounded-md border"
                :style="{ backgroundColor: current }"
            />
            <Input
                :model-value="text"
                :aria-label="`${props.label} (hexadecimal)`"
                class="font-mono uppercase"
                maxlength="7"
                spellcheck="false"
                autocomplete="off"
                placeholder="#2563EB"
                @update:model-value="onText"
            />
        </div>

        <div
            v-if="swatches.length > 0"
            class="flex flex-wrap gap-1.5"
            role="group"
            aria-label="Colores sugeridos"
        >
            <button
                v-for="swatch in swatches"
                :key="swatch"
                type="button"
                class="size-6 rounded-md border ring-offset-2 ring-offset-popover outline-none hover:scale-110 focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    swatch.toLowerCase() === current &&
                    'ring-2 ring-foreground/60'
                "
                :style="{ backgroundColor: swatch }"
                :aria-label="`Usar ${swatch}`"
                :title="swatch"
                @click="onText(swatch)"
            />
        </div>
    </div>
</template>
