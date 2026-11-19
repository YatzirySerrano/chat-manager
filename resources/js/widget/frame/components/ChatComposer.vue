<script setup lang="ts">
import { Paperclip, SendHorizontal } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { VisitorFilesConfig } from '../../types';

/**
 * Message box of the visitor: Enter sends, Shift+Enter adds a line, with a
 * character counter and the server's limit. Text is 16 px so iOS does not
 * zoom in when it gets focus. When the widget accepts files, the paperclip
 * picks one, checked here by type and size (the server checks its real
 * content again) before it is handed to the thread to upload.
 */
const props = defineProps<{
    maxLength: number;
    disabled: boolean;
    placeholder?: string | null;
    files?: VisitorFilesConfig | null;
}>();

const emit = defineEmits<{ send: [body: string]; attach: [file: File] }>();

const draft = defineModel<string>({ required: true });
const field = ref<HTMLTextAreaElement | null>(null);
const picker = ref<HTMLInputElement | null>(null);
const fileError = ref<string | null>(null);

const length = computed(() => draft.value.length);
const canSend = computed(
    () =>
        !props.disabled &&
        draft.value.trim() !== '' &&
        length.value <= props.maxLength,
);
const filesEnabled = computed(() => props.files?.enabled === true);
const placeholderText = computed(
    () => props.placeholder || 'Escribe tu mensaje…',
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

/** Why the file cannot be sent, or null (mirrors VisitorFiles). */
function problemOf(file: File): string | null {
    const config = props.files;

    if (!config || !config.enabled) {
        return 'Este chat no admite archivos.';
    }

    const accepted = config.accept.toLowerCase().split(',');
    const type = file.type.toLowerCase();
    const extension = file.name.includes('.')
        ? '.' + file.name.split('.').pop()!.toLowerCase()
        : '';

    if (!accepted.includes(type) && !accepted.includes(extension)) {
        return 'Este tipo de archivo no se puede enviar. Envía una imagen, un video, un audio, un PDF o un documento de Office.';
    }

    if (file.size === 0) {
        return 'El archivo está vacío.';
    }

    const video = type.startsWith('video/');
    const max = video ? config.video_max_bytes : config.max_bytes;

    if (file.size > max) {
        return `${video ? 'El video' : 'El archivo'} supera el tamaño permitido (${video ? config.video_max_mb : config.max_mb} MB).`;
    }

    return null;
}

function choose(): void {
    fileError.value = null;
    picker.value?.click();
}

function onPicked(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = '';

    if (!file) {
        return;
    }

    fileError.value = problemOf(file);

    if (fileError.value === null) {
        emit('attach', file);
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
            <template v-if="filesEnabled && files?.enabled">
                <input
                    ref="picker"
                    type="file"
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    :accept="files.accept"
                    @change="onPicked"
                />
                <button
                    type="button"
                    class="grid size-11 shrink-0 place-items-center rounded-full text-gray-600 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--cm-ring) disabled:opacity-50 dark:text-gray-300 dark:hover:bg-gray-800"
                    :disabled="disabled"
                    aria-label="Adjuntar archivo"
                    title="Adjuntar archivo"
                    @click="choose"
                >
                    <Paperclip class="size-5" aria-hidden="true" />
                </button>
            </template>
            <label for="cm-message" class="sr-only">Escribe tu mensaje</label>
            <textarea
                id="cm-message"
                ref="field"
                v-model="draft"
                rows="1"
                :maxlength="maxLength"
                :disabled="disabled"
                :placeholder="placeholderText"
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
            v-if="fileError"
            class="px-1 text-xs text-red-600 dark:text-red-400"
            role="alert"
        >
            {{ fileError }}
        </p>
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
