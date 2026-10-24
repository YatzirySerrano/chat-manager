<script setup lang="ts">
import { SendHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Message box of the visitor: Enter sends, Shift+Enter adds a line, with a
 * character counter and the server's limit. Text is 16 px so iOS does not
 * zoom in when it gets focus.
 */
const props = defineProps<{
    maxLength: number;
    disabled: boolean;
}>();

const emit = defineEmits<{ send: [body: string] }>();

const draft = defineModel<string>({ required: true });
const field = ref<HTMLTextAreaElement | null>(null);

const length = computed(() => draft.value.length);
const canSend = computed(
    () =>
        !props.disabled &&
        draft.value.trim() !== '' &&
        length.value <= props.maxLength,
);

function submit(): void {
    if (!canSend.value) {
        return;
    }

    emit('send', draft.value.trim());
    draft.value = '';
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}

defineExpose({
    focus: () => field.value?.focus({ preventScroll: true }),
});
</script>

<template>
    <form
        class="grid shrink-0 grid-cols-1 gap-1 border-t border-gray-200 bg-white px-3 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] dark:border-gray-800 dark:bg-gray-950"
        @submit.prevent="submit"
    >
        <div class="flex items-end gap-2">
            <label for="cm-message" class="sr-only">Escribe tu mensaje</label>
            <textarea
                id="cm-message"
                ref="field"
                v-model="draft"
                rows="1"
                :maxlength="maxLength"
                :disabled="disabled"
                placeholder="Escribe tu mensaje…"
                aria-describedby="cm-counter"
                class="[field-sizing:content] max-h-32 min-h-11 min-w-0 flex-1 resize-none rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-base leading-snug text-gray-900 placeholder:text-gray-500 focus:border-(--cm-ring) focus:outline-2 focus:outline-offset-0 focus:outline-(--cm-ring) disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-400"
                @keydown="onKeydown"
            />
            <button
                type="submit"
                class="grid size-11 shrink-0 place-items-center rounded-full bg-(--cm-accent) text-(--cm-accent-fg) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--cm-ring) disabled:opacity-50"
                :disabled="!canSend"
                aria-label="Enviar mensaje"
            >
                <SendHorizontal class="size-5" aria-hidden="true" />
            </button>
        </div>
        <p
            id="cm-counter"
            class="px-1 text-right text-xs text-gray-500 dark:text-gray-400"
            :class="{ 'text-red-600 dark:text-red-400': length >= maxLength }"
        >
            <span class="sr-only">Caracteres:</span>
            {{ length }}/{{ maxLength }}
        </p>
    </form>
</template>
