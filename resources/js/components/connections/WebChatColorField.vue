<script setup lang="ts">
import { RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import HexColorField from '@/components/personalization/HexColorField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * An optional color of the web chat widget: swatch, hex input and a reset
 * to the widget's own color. Empty ('') means "predeterminado"; the swatch
 * then shows the color the widget uses instead (`fallback`).
 *
 *   <WebChatColorField id="widget-bg" v-model="form.background_color" label="Fondo" fallback="#ffffff" />
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        fallback: string;
        error?: string;
        swatches?: string[];
        disabled?: boolean;
    }>(),
    { error: undefined, swatches: () => [], disabled: false },
);

const model = defineModel<string>({ required: true });

const isDefault = computed(() => model.value.trim() === '');
</script>

<template>
    <div class="grid min-w-0 gap-1.5">
        <Label :for="id" class="text-xs">{{ label }}</Label>
        <div class="flex min-w-0 items-center gap-1.5">
            <HexColorField
                v-model="model"
                :aria-label="`Elegir ${label.toLowerCase()}`"
                :fallback="props.fallback"
                :swatches="swatches"
                :disabled="disabled"
            />
            <Input
                :id="id"
                v-model="model"
                maxlength="7"
                class="h-9 min-w-0 flex-1 font-mono text-xs"
                placeholder="Auto"
                :disabled="disabled"
                :aria-invalid="error ? true : undefined"
                :aria-describedby="error ? `${id}-error` : undefined"
            />
            <button
                v-if="!isDefault && !disabled"
                type="button"
                class="grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-label="`Usar el color predeterminado en ${label.toLowerCase()}`"
                title="Predeterminado"
                @click="model = ''"
            >
                <RotateCcw class="size-3.5" aria-hidden="true" />
            </button>
        </div>
        <InputError :id="`${id}-error`" :message="error" />
    </div>
</template>
