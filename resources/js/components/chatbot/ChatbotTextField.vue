<script setup lang="ts">
import { Braces } from '@lucide/vue';
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
        <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-1">
            <Label
                :for="id"
                :class="compact && 'text-xs font-medium text-muted-foreground'"
                >{{ label }}</Label
            >
            <div
                v-if="!disabled"
                class="flex flex-wrap items-center gap-1"
                role="group"
                :aria-label="`Insertar variable en ${label.toLowerCase()}`"
            >
                <Braces
                    class="size-3.5 text-muted-foreground"
                    aria-hidden="true"
                />
                <button
                    v-for="variable in variables"
                    :key="variable.token"
                    type="button"
                    class="rounded-sm border bg-card px-1.5 py-0.5 font-mono text-[0.6875rem] text-muted-foreground transition-colors duration-(--duration-fast) hover:border-primary/40 hover:bg-primary-soft hover:text-primary-soft-foreground focus-visible:ring-2 focus-visible:ring-primary/30 focus-visible:outline-none"
                    :title="`${variable.label} (ej. ${variable.example})`"
                    @click="insert(variable.token)"
                >
                    {{ variable.token }}
                </button>
            </div>
        </div>
        <Textarea
            :id="id"
            v-model="model"
            :rows="rows"
            :maxlength="maxlength"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-invalid="error ? true : undefined"
            :aria-describedby="error ? `${id}-error` : undefined"
        />
        <div class="flex items-start justify-between gap-3">
            <InputError :id="`${id}-error`" :message="error" />
            <span
                class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                :class="
                    model.length > maxlength * 0.9 &&
                    'text-warning-soft-foreground'
                "
                >{{ model.length }}/{{ maxlength }}</span
            >
        </div>
    </div>
</template>
