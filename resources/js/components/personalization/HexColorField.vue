<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import HexColorPanel from '@/components/personalization/HexColorPanel.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Swatch button that opens the platform's color picker in a popover.
 * Replaces <input type="color">: the model is the same "#rrggbb" string.
 *
 *   <HexColorField v-model="form.accent_color" aria-label="Color de acento" />
 */
const props = withDefaults(
    defineProps<{
        ariaLabel?: string;
        /** Color shown while the model is not a valid "#rrggbb" value. */
        fallback?: string;
        swatches?: string[];
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        ariaLabel: 'Elegir color',
        fallback: '#000000',
        swatches: () => [],
        disabled: false,
        class: undefined,
    },
);

const model = defineModel<string>({ required: true });

const shown = computed(() =>
    /^#[0-9a-f]{6}$/i.test(model.value)
        ? model.value.toLowerCase()
        : props.fallback,
);

const panelModel = computed({
    get: () => shown.value,
    set: (value: string) => {
        model.value = value;
    },
});
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                data-slot="hex-color-field"
                :aria-label="`${ariaLabel}: ${shown}`"
                :disabled="disabled"
                :class="
                    cn(
                        'group/color relative size-9 shrink-0 rounded-md border border-input p-1 shadow-(--shadow-control) outline-none hover:border-border-strong focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                        props.class,
                    )
                "
            >
                <span
                    aria-hidden="true"
                    class="block size-full rounded-[5px] transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/color:scale-90 group-data-[state=open]/color:scale-90"
                    :style="{ backgroundColor: shown }"
                />
            </button>
        </PopoverTrigger>
        <PopoverContent align="start" class="w-64 p-3">
            <HexColorPanel
                v-model="panelModel"
                :label="ariaLabel"
                :swatches="swatches"
            />
        </PopoverContent>
    </Popover>
</template>
