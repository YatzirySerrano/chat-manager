<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { insertAtCaret } from '@/lib/chatbotText';
import type { ChatbotVariable } from '@/types';

/**
 * A text of the chatbot with its variable chips: a click inserts the
 * variable where the caret is. Shows its length against the limit.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        maxlength: number;
        variables: ChatbotVariable[];
        placeholder?: string;
        rows?: number;
        error?: string;
        disabled?: boolean;
        /** Smaller label, for texts inside an option row. */
        compact?: boolean;
    }>(),
    {
        placeholder: undefined,
        rows: 3,
        error: undefined,
        disabled: false,
        compact: false,
    },
);

const model = defineModel<string>({ required: true });

const wrapper = ref<HTMLElement | null>(null);

/** Short, friendly name of a variable for its chip: «Nombre», «Conexión»… */
function chipName(token: string): string {
    const name = token.replace(/[{}\s]/g, '').toLowerCase();
    const known: Record<string, string> = {
        nombre: 'Nombre',
        departamento: 'Departamento',
        conexion: 'Conexión',
        saludo: 'Saludo',
    };

    return known[name] ?? name;
}

async function insert(token: string): Promise<void> {
    const element = wrapper.value?.querySelector('textarea') ?? null;
    const result = insertAtCaret(element, model.value, token);

    if (result.value.length > props.maxlength) {
        return;
    }

    model.value = result.value;
    await nextTick();
    element?.focus();
    element?.setSelectionRange(result.caret, result.caret);
}
</script>

<template>
    <div ref="wrapper" class="grid gap-1.5">
        <Label
            :for="id"
            :class="compact && 'text-xs font-medium text-muted-foreground'"
            >{{ label }}</Label
        >
        <Textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :maxlength="maxlength"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="
                [error ? `${id}-error` : null, !disabled ? `${id}-vars` : null]
                    .filter(Boolean)
                    .join(' ') || undefined
            "
        />
        <div
            class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1.5"
        >
            <div
                v-if="!disabled && variables.length > 0"
                class="flex flex-wrap items-center gap-1"
                role="group"
                :aria-label="`Insertar dato del cliente en ${label.toLowerCase()}`"
            >
                <span
                    :id="`${id}-vars`"
                    class="mr-0.5 text-xs text-muted-foreground"
                    >Insertar:</span
                >
                <button
                    v-for="variable in variables"
                    :key="variable.token"
                    type="button"
                    class="inline-flex items-center gap-0.5 rounded-full border bg-card px-2 py-0.5 text-xs font-medium text-foreground/80 transition-colors duration-(--duration-fast) hover:border-primary/40 hover:bg-primary-soft hover:text-primary-soft-foreground focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none"
                    :title="`${variable.label}. Se escribe ${variable.token} y se cambia por el dato real (ej. «${variable.example}»).`"
                    :aria-label="`Insertar ${variable.label} (${variable.token}), por ejemplo ${variable.example}`"
                    @click="insert(variable.token)"
                >
                    <Plus class="size-3" aria-hidden="true" />
                    {{ chipName(variable.token) }}
                </button>
            </div>
            <span
                class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                :class="
                    model.length > maxlength * 0.9 &&
                    'text-warning-soft-foreground'
                "
                >{{ model.length }}/{{ maxlength }}</span
            >
        </div>
        <InputError :id="`${id}-error`" :message="error" />
    </div>
</template>
