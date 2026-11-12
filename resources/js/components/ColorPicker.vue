<script setup lang="ts">
import { Check, Pipette } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import HexColorPanel from '@/components/personalization/HexColorPanel.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    colorPresentations,
    isHexColor,
    normalizeHex,
    solidForeground,
} from '@/lib/colors';
import type { AppColor, ColorSwatch, HexColor } from '@/types';

/**
 * Color of a department or tag: the platform swatches (which already
 * guarantee readable contrast in light and dark themes) plus a last
 * "Color personalizado" circle that opens the platform's own picker
 * (HexColorPanel, in a popover) and stores a "#rrggbb" value.
 *
 * It follows the radio group keyboard pattern: one Tab stop on the checked
 * circle, arrows move and select. Enter/Space or a click on the custom circle
 * opens the picker.
 */
const props = withDefaults(
    defineProps<{
        colors: ColorSwatch[];
        legend?: string;
        groupLabel: string;
        error?: string;
    }>(),
    {
        legend: 'Color',
        error: undefined,
    },
);

const model = defineModel<AppColor>({ required: true });

const DEFAULT_CUSTOM: HexColor = '#6366f1';

const pickerOpen = ref(false);

/** Last custom color, kept while a palette swatch is selected. */
const customColor = ref<HexColor>(
    isHexColor(model.value) ? normalizeHex(model.value) : DEFAULT_CUSTOM,
);

watch(model, (value) => {
    if (isHexColor(value)) {
        customColor.value = normalizeHex(value);
    }
});

const isCustomSelected = computed(() => isHexColor(model.value));

const isPaletteSelected = computed(() =>
    props.colors.includes(model.value as ColorSwatch),
);

/** Number of radios: every swatch plus the custom circle. */
const radioCount = computed(() => props.colors.length + 1);

function isChecked(index: number): boolean {
    return index === props.colors.length
        ? isCustomSelected.value
        : model.value === props.colors[index];
}

function tabIndexFor(index: number): 0 | -1 {
    if (isChecked(index)) {
        return 0;
    }

    return !isPaletteSelected.value && !isCustomSelected.value && index === 0
        ? 0
        : -1;
}

function select(index: number): void {
    model.value =
        index === props.colors.length ? customColor.value : props.colors[index];
}

/** Choosing the custom circle selects the last custom color. */
function selectCustom(): void {
    model.value = customColor.value;
}

const pickerColor = computed({
    get: () => customColor.value as string,
    set: (value: string) => {
        if (isHexColor(value)) {
            model.value = normalizeHex(value);
        }
    },
});

const CUSTOM_SWATCHES = [
    '#ef4444',
    '#f97316',
    '#eab308',
    '#22c55e',
    '#14b8a6',
    '#0ea5e9',
    '#6366f1',
    '#a855f7',
    '#ec4899',
    '#64748b',
];

function moveSelection(event: KeyboardEvent, index: number): void {
    const last = radioCount.value - 1;
    const target =
        {
            ArrowRight: index === last ? 0 : index + 1,
            ArrowDown: index === last ? 0 : index + 1,
            ArrowLeft: index === 0 ? last : index - 1,
            ArrowUp: index === 0 ? last : index - 1,
            Home: 0,
            End: last,
        }[event.key] ?? null;

    if (target === null) {
        return;
    }

    event.preventDefault();
    select(target);
    (event.currentTarget as HTMLElement)
        .closest('[role="radiogroup"]')
        ?.querySelectorAll<HTMLElement>('[role="radio"]')
        [target]?.focus();
}
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm font-medium">{{ legend }}</legend>
        <div
            class="flex flex-wrap items-center gap-2"
            role="radiogroup"
            :aria-label="groupLabel"
        >
            <button
                v-for="(color, index) in colors"
                :key="color"
                type="button"
                role="radio"
                :aria-checked="isChecked(index)"
                :tabindex="tabIndexFor(index)"
                :aria-label="colorPresentations(color).label"
                :title="colorPresentations(color).label"
                class="flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="[
                    colorPresentations(color).dot.class,
                    isChecked(index) && 'ring-2 ring-foreground/60',
                ]"
                @click="select(index)"
                @keydown="moveSelection($event, index)"
            >
                <Check
                    v-if="isChecked(index)"
                    class="size-4 text-white"
                    aria-hidden="true"
                />
            </button>

            <Popover v-model:open="pickerOpen">
                <PopoverTrigger as-child>
                    <button
                        type="button"
                        role="radio"
                        :aria-checked="isCustomSelected"
                        :tabindex="tabIndexFor(colors.length)"
                        :aria-label="
                            isCustomSelected
                                ? `Color personalizado ${customColor}`
                                : 'Color personalizado'
                        "
                        :title="
                            isCustomSelected
                                ? `Color personalizado ${customColor}`
                                : 'Color personalizado'
                        "
                        data-test="custom-color"
                        class="flex size-8 items-center justify-center rounded-full border ring-offset-2 ring-offset-background transition outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="[
                            isCustomSelected
                                ? 'ring-2 ring-foreground/60'
                                : 'bg-[conic-gradient(from_90deg,#ef4444,#f59e0b,#22c55e,#06b6d4,#6366f1,#d946ef,#ef4444)]',
                        ]"
                        :style="
                            isCustomSelected
                                ? { backgroundColor: customColor }
                                : undefined
                        "
                        @click="selectCustom"
                        @keydown="moveSelection($event, colors.length)"
                    >
                        <Check
                            v-if="isCustomSelected"
                            class="size-4"
                            :class="
                                solidForeground(customColor) === 'white'
                                    ? 'text-white'
                                    : 'text-black'
                            "
                            aria-hidden="true"
                        />
                        <Pipette
                            v-else
                            class="size-3.5 text-white drop-shadow"
                            aria-hidden="true"
                        />
                    </button>
                </PopoverTrigger>
                <PopoverContent align="start" class="w-64 p-3">
                    <HexColorPanel
                        v-model="pickerColor"
                        label="Color personalizado"
                        :swatches="CUSTOM_SWATCHES"
                    />
                </PopoverContent>
            </Popover>

            <span
                v-if="isCustomSelected"
                class="font-mono text-xs text-muted-foreground"
                aria-hidden="true"
                >{{ customColor }}</span
            >
        </div>
        <InputError :message="error" />
    </fieldset>
</template>
