<script setup lang="ts">
import { KeyRound, LockKeyhole, RefreshCw } from '@lucide/vue';
import { nextTick, ref, useTemplateRef, watch } from 'vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import type { FieldBindings } from '@/composables/useFormValidation';

/**
 * Write-only credential field. A saved secret is never sent to the browser:
 * the field only knows that a value exists and offers to replace it. Leaving
 * it blank keeps the stored value.
 */
const props = defineProps<{
    id: string;
    saved: boolean;
    disabled?: boolean;
    invalid?: boolean;
    /** ARIA attributes and blur handler from useFormValidation. */
    fieldAttrs?: FieldBindings;
    placeholder?: string;
    maxlength?: number;
    /** Offer to generate a random value (for tokens chosen by the company). */
    generatable?: boolean;
}>();

const model = defineModel<string>({ required: true });

const replacing = ref(!props.saved);
const input = useTemplateRef<InstanceType<typeof PasswordInput>>('input');

watch(
    () => props.saved,
    (saved) => {
        replacing.value = !saved;
    },
);

async function startReplacing(): Promise<void> {
    replacing.value = true;
    await nextTick();
    input.value?.focus();
}

function cancelReplacing(): void {
    model.value = '';
    replacing.value = false;
}

function generate(): void {
    const bytes = new Uint8Array(24);
    crypto.getRandomValues(bytes);
    model.value = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');
}
</script>

<template>
    <div
        v-if="saved && !replacing"
        class="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-muted/40 px-3 py-1.5"
    >
        <span class="flex items-center gap-2 text-sm">
            <LockKeyhole class="size-4 text-success" aria-hidden="true" />
            <span class="font-medium">Guardado</span>
            <span class="text-xs text-muted-foreground"
                >· cifrado, no se muestra</span
            >
        </span>
        <Button
            type="button"
            variant="ghost"
            size="sm"
            :disabled="disabled"
            :aria-controls="id"
            @click="startReplacing"
        >
            <KeyRound />
            Reemplazar
        </Button>
    </div>
    <div v-else class="grid gap-1.5">
        <div class="flex gap-2">
            <PasswordInput
                :id="id"
                ref="input"
                v-model="model"
                class="flex-1 font-mono"
                autocomplete="new-password"
                spellcheck="false"
                :maxlength="maxlength"
                :disabled="disabled"
                :placeholder="placeholder"
                v-bind="fieldAttrs"
                :aria-invalid="invalid || fieldAttrs?.['aria-invalid']"
            />
            <Button
                v-if="generatable"
                type="button"
                variant="outline"
                :disabled="disabled"
                @click="generate"
            >
                <RefreshCw />
                Generar
            </Button>
        </div>
        <button
            v-if="saved"
            type="button"
            class="justify-self-start text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
            @click="cancelReplacing"
        >
            Cancelar y conservar el valor guardado
        </button>
    </div>
</template>
