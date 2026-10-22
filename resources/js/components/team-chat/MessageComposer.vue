<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Paperclip, SendHorizontal, Smile, X } from '@lucide/vue';
import { computed, nextTick, ref, useId } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { store as storeMessage } from '@/routes/team-chat/threads/messages';
import type { TeamChatLimits, TeamMessage } from '@/types/team-chat';

/**
 * Writes internal messages: multiline text (Enter sends, Shift+Enter adds a
 * line), files of the allowed types and quick emoji. Internal messages are
 * never sent to WhatsApp.
 */
const props = defineProps<{
    threadId: number;
    limits: TeamChatLimits;
    replyTo: TeamMessage | null;
}>();

const emit = defineEmits<{
    sent: [message: TeamMessage];
    cancelReply: [];
}>();

const QUICK_EMOJI = [
    '👍',
    '👌',
    '🙌',
    '👏',
    '🙏',
    '💪',
    '😀',
    '😂',
    '😉',
    '😊',
    '🤔',
    '😅',
    '😮',
    '😢',
    '🎉',
    '✅',
    '❌',
    '⚠️',
    '⏰',
    '📌',
    '📎',
    '🔥',
    '❤️',
    '👀',
];

const textareaId = useId();
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const emojiOpen = ref(false);
const localError = ref<string | null>(null);

const http = useHttp<
    {
        body: string;
        reply_to_message_id: number | null;
        attachments: File[];
    },
    { data: TeamMessage }
>({
    body: '',
    reply_to_message_id: null,
    attachments: [],
});

const remaining = computed(
    () => props.limits.messageMaxLength - http.body.length,
);
const canSend = computed(
    () =>
        !http.processing &&
        remaining.value >= 0 &&
        (http.body.trim() !== '' || http.attachments.length > 0),
);
const errors = computed(() => {
    const list = Object.entries(
        http.errors as Record<string, string | undefined>,
    )
        .map(([, message]) => message)
        .filter((message): message is string => Boolean(message));

    return localError.value ? [localError.value, ...list] : list;
});

function resize(): void {
    const element = textarea.value;

    if (!element) {
        return;
    }

    element.style.height = 'auto';
    element.style.height = `${Math.min(element.scrollHeight, 200)}px`;
}

function focus(): void {
    textarea.value?.focus();
}

defineExpose({ focus });

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void send();
    } else if (event.key === 'Escape' && props.replyTo) {
        emit('cancelReply');
    }
}

function pickFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    const chosen = Array.from(input.files ?? []);
    input.value = '';
    localError.value = null;

    const tooBig = chosen.filter(
        (file) => file.size > props.limits.maxKilobytes * 1024,
    );

    if (tooBig.length > 0) {
        localError.value = `«${tooBig[0].name}» supera el máximo de ${Math.round(props.limits.maxKilobytes / 1024)} MB por archivo.`;
    }

    const accepted = chosen.filter((file) => !tooBig.includes(file));
    const merged = [...http.attachments, ...accepted];

    if (merged.length > props.limits.maxFiles) {
        localError.value = `Puedes adjuntar como máximo ${props.limits.maxFiles} archivos por mensaje.`;
    }

    http.attachments = merged.slice(0, props.limits.maxFiles);
}

function removeFile(index: number): void {
    http.attachments = http.attachments.filter(
        (_, position) => position !== index,
    );
}

async function insertEmoji(emoji: string): Promise<void> {
    const element = textarea.value;
    const start = element?.selectionStart ?? http.body.length;
    const end = element?.selectionEnd ?? http.body.length;

    http.body = `${http.body.slice(0, start)}${emoji}${http.body.slice(end)}`;
    emojiOpen.value = false;
    await nextTick();
    element?.focus();
    element?.setSelectionRange(start + emoji.length, start + emoji.length);
    resize();
}

async function send(): Promise<void> {
    if (!canSend.value) {
        return;
    }

    localError.value = null;
    http.reply_to_message_id = props.replyTo?.id ?? null;
    http.clearErrors();

    try {
        const response = await http.post(storeMessage.url(props.threadId), {
            onHttpException: (response) => {
                localError.value =
                    response.status === 403
                        ? 'Ya no puedes enviar mensajes en este chat.'
                        : 'No se pudo enviar el mensaje. Inténtalo de nuevo.';

                return false;
            },
            onNetworkError: () => {
                localError.value =
                    'Sin conexión con el servidor. Tu mensaje no se envió; revisa la conexión e inténtalo de nuevo.';

                return false;
            },
        });

        // A successful request makes the sent values the form defaults, so
        // reset() would bring the sent text back: empty the composer instead.
        http.defaults({
            body: '',
            reply_to_message_id: null,
            attachments: [],
        });
        http.reset();
        emit('sent', response.data);
        await nextTick();
        resize();
        focus();
    } catch {
        // Errors are shown from http.errors / localError.
    }
}
</script>

<template>
    <div class="border-t bg-background p-2 sm:p-3">
        <div
            v-if="replyTo"
            class="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-primary bg-muted px-3 py-2 text-xs"
        >
            <div class="min-w-0 flex-1">
                <p class="font-semibold">
                    Respondiendo a
                    {{ replyTo.is_mine ? 'tu mensaje' : replyTo.author?.name }}
                </p>
                <p class="line-clamp-2 text-muted-foreground">
                    {{ replyTo.body ?? 'Archivo adjunto' }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                class="size-6"
                aria-label="Cancelar respuesta"
                title="Cancelar respuesta"
                @click="emit('cancelReply')"
            >
                <X class="size-3.5" />
            </Button>
        </div>

        <ul
            v-if="http.attachments.length > 0"
            class="mb-2 flex flex-wrap gap-1.5"
        >
            <li
                v-for="(file, index) in http.attachments"
                :key="`${file.name}-${index}`"
                class="flex max-w-56 items-center gap-1.5 rounded-md border bg-muted px-2 py-1 text-xs"
            >
                <Paperclip class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ file.name }}</span>
                <button
                    type="button"
                    class="rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                    :aria-label="`Quitar ${file.name}`"
                    @click="removeFile(index)"
                >
                    <X class="size-3" />
                </button>
            </li>
        </ul>

        <div class="flex items-end gap-1.5">
            <input
                ref="fileInput"
                type="file"
                class="hidden"
                multiple
                :accept="limits.accept"
                @change="pickFiles"
            />
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 text-muted-foreground"
                aria-label="Adjuntar archivos"
                title="Adjuntar archivos (imágenes, PDF, documentos de Office, texto, audio o video)"
                :disabled="
                    http.processing ||
                    http.attachments.length >= limits.maxFiles
                "
                @click="fileInput?.click()"
            >
                <Paperclip />
            </Button>
            <Popover v-model:open="emojiOpen">
                <PopoverTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="hidden shrink-0 text-muted-foreground sm:inline-flex"
                        aria-label="Insertar emoji"
                        title="Insertar emoji"
                    >
                        <Smile />
                    </Button>
                </PopoverTrigger>
                <PopoverContent class="w-64 p-2" align="start">
                    <div
                        class="grid grid-cols-8 gap-0.5"
                        role="listbox"
                        aria-label="Emojis"
                    >
                        <button
                            v-for="emoji in QUICK_EMOJI"
                            :key="emoji"
                            type="button"
                            role="option"
                            class="rounded-md p-1 text-lg hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            :aria-label="`Insertar ${emoji}`"
                            @click="insertEmoji(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </PopoverContent>
            </Popover>
            <label :for="textareaId" class="sr-only">Mensaje</label>
            <textarea
                :id="textareaId"
                ref="textarea"
                v-model="http.body"
                rows="1"
                class="max-h-[200px] min-h-10 flex-1 resize-none rounded-xl border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                placeholder="Escribe un mensaje interno…"
                :aria-invalid="errors.length > 0"
                @keydown="onKeydown"
                @input="resize"
            />
            <Button
                size="icon"
                class="shrink-0 rounded-xl"
                :disabled="!canSend"
                aria-label="Enviar mensaje"
                title="Enviar (Enter). Nueva línea: Shift + Enter"
                @click="send"
            >
                <Spinner v-if="http.processing" />
                <SendHorizontal v-else />
            </Button>
        </div>
        <div class="mt-1 flex items-start justify-between gap-2 px-1">
            <div class="min-w-0 space-y-0.5">
                <InputError
                    v-for="message in errors"
                    :key="message"
                    :message="message"
                />
            </div>
            <span
                v-if="remaining < 200"
                class="shrink-0 text-xs"
                :class="
                    remaining < 0 ? 'text-destructive' : 'text-muted-foreground'
                "
            >
                {{ remaining }}
            </span>
        </div>
    </div>
</template>
