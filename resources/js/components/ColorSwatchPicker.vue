<script setup lang="ts">
import { Check } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { swatchClasses } from '@/lib/swatches';
import type { ColorSwatch } from '@/types';

/**
 * Radio group of the platform color swatches (which already guarantee
 * readable contrast in light and dark themes). It follows the radio group
 * keyboard pattern: one Tab stop on the checked swatch, arrows move and
 * select.
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

const model = defineModel<ColorSwatch>({ required: true });

function moveSelection(event: KeyboardEvent, index: number): void {
    const last = props.colors.length - 1;
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
    model.value = props.colors[target];
    (event.currentTarget as HTMLElement).parentElement
        ?.querySelectorAll<HTMLElement>('[role="radio"]')
        [target]?.focus();
}
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm font-medium">{{ legend }}</legend>
        <div
            class="flex flex-wrap gap-2"
            role="radiogroup"
            :aria-label="groupLabel"
        >
            <button
                v-for="(color, index) in colors"
                :key="color"
                type="button"
                role="radio"
                :aria-checked="model === color"
                :tabindex="
                    model === color || (!colors.includes(model) && index === 0)
                        ? 0
                        : -1
                "
                :aria-label="swatchClasses[color].label"
                :title="swatchClasses[color].label"
                class="flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="[
                    swatchClasses[color].dot,
                    model === color && 'ring-2 ring-foreground/60',
                ]"
                @click="model = color"
                @keydown="moveSelection($event, index)"
            >
                <Check
                    v-if="model === color"
                    class="size-4 text-white"
                    aria-hidden="true"
                />
            </button>
        </div>
        <InputError :message="error" />
    </fieldset>
</template>
