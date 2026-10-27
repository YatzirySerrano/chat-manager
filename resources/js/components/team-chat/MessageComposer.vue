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
import { useUploadProgress } from '@/composables/useUploadProgress';
import { formatFileSize } from '@/lib/format';
import { UPLOAD_CODE_FIELD, serverLimitNotice } from '@/lib/uploads';
import { cn } from '@/lib/utils';
import { typing as announceTyping } from '@/routes/team-chat/threads';
import { store as storeMessage } from '@/routes/team-chat/threads/messages';
import type {
    TeamChatLimits,
    TeamMention,
    TeamMessage,
} from '@/types/team-chat';

/**
 * Writes internal messages: multiline text (Enter sends, Shift+Enter adds a
 * line), files of the allowed types and quick emoji. Internal messages are
 * never sent to WhatsApp.
 *
 * In groups, typing "@" suggests the current participants; the chosen ids
 * travel with the message (names are not unique) and the server keeps only
 * current participants. While writing, a "typing" signal is sent at most
 * every few seconds; it is never stored.
 */
const props = defineProps<{
    threadId: number;
    limits: TeamChatLimits;
    replyTo: TeamMessage | null;
    /** People that may be mentioned (other current participants of a group). */
    mentionable: TeamMention[];
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
/* Problems found here (files refused before uploading, network, cancellation). */
const localErrors = ref<string[]>([]);
const errorIdPrefix = useId();

const TYPING_INTERVAL_MS = 3000;
const MENTION_QUERY = /(?:^|\s)@([^\s@]{0,30})$/u;

const http = useHttp<
    {
        body: string;
        reply_to_message_id: number | null;
        attachments: File[];
        mentions: number[];
    },
    { data: TeamMessage }
>({
    body: '',
    reply_to_message_id: null,
    attachments: [],
    mentions: [],
});
const typingHttp = useHttp<Record<string, never>>({});

const chosenMentions = ref<TeamMention[]>([]);
const mentionQuery = ref<string | null>(null);
const mentionIndex = ref(0);
let lastTypingAt = 0;

function normalized(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLocaleLowerCase('es');
}

const suggestions = computed(() => {
    if (mentionQuery.value === null) {
        return [];
    }

    const query = normalized(mentionQuery.value);

    return props.mentionable
        .filter((person) =>
            normalized(person.name)
                .split(/\s+/)
                .some((word) => word.startsWith(query)),
        )
        .slice(0, 8);
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
/*
 * Every problem, each with an id: the server's per file errors
 * ("attachments.N") also mark their chip (see failedFiles).
 */
const errors = computed(() => {
    const server = Object.entries(
        http.errors as Record<string, string | undefined>,
    )
        .filter(
            (entry): entry is [string, string] =>
                entry[0] !== UPLOAD_CODE_FIELD && Boolean(entry[1]),
        )
        .map(([field, message]) => ({
            id: `${errorIdPrefix}-${field.replace(/\W/g, '-')}`,
            message,
            file: /^attachments\.(\d+)$/.exec(field)?.[1] ?? null,
        }));

    return [
        ...localErrors.value.map((message, index) => ({
            id: `${errorIdPrefix}-local-${index}`,
            message,
            file: null,
        })),
        ...server,
    ];
});

/* Chip position → id of the error that explains why it failed. */
const failedFiles = computed(
    () =>
        new Map(
            errors.value
                .filter((entry) => entry.file !== null)
                .map((entry) => [Number(entry.file), entry.id]),
        ),
);

/* Upload progress and «Cancelar envío» (shared with the customer reply composer). */
const {
    progress: uploadProgress,
    canCancel: canCancelUpload,
    cancel: cancelUpload,
    wasCancelled: uploadWasCancelled,
} = useUploadProgress(
    http,
    () => http.processing && http.attachments.length > 0,
);

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

function detectMention(): void {
    const element = textarea.value;

    if (!element || props.mentionable.length === 0) {
        mentionQuery.value = null;

        return;
    }

    const match = http.body
        .slice(0, element.selectionStart ?? http.body.length)
        .match(MENTION_QUERY);

    mentionQuery.value = match ? match[1] : null;
    mentionIndex.value = 0;
}

async function chooseMention(person: TeamMention): Promise<void> {
    const element = textarea.value;
    const caret = element?.selectionStart ?? http.body.length;
    const before = http.body
        .slice(0, caret)
        .replace(/@[^\s@]{0,30}$/u, `@${person.name} `);

    http.body = `${before}${http.body.slice(caret)}`;

    if (!chosenMentions.value.some((chosen) => chosen.id === person.id)) {
        chosenMentions.value = [...chosenMentions.value, person];
    }

    mentionQuery.value = null;
    await nextTick();
    element?.focus();
    element?.setSelectionRange(before.length, before.length);
    resize();
}

/** Mentions whose "@Name" is still in the text. */
function mentionsInText(): number[] {
    return chosenMentions.value
        .filter((person) => http.body.includes(`@${person.name}`))
        .map((person) => person.id)
        .slice(0, props.limits.maxMentions);
}

function signalTyping(): void {
    const now = Date.now();

    if (http.body.trim() === '' || now - lastTypingAt < TYPING_INTERVAL_MS) {
        return;
    }

    lastTypingAt = now;
    typingHttp
        .post(announceTyping.url(props.threadId), {
            onHttpException: () => false,
            onNetworkError: () => false,
        })
        .catch(() => {
            // The indicator is best effort: a lost signal only hides it.
        });
}

function onInput(): void {
    resize();
    detectMention();
    signalTyping();
}

function onKeydown(event: KeyboardEvent): void {
    if (suggestions.value.length > 0 && !event.isComposing) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            mentionIndex.value =
                (mentionIndex.value + step + suggestions.value.length) %
                suggestions.value.length;

            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            void chooseMention(suggestions.value[mentionIndex.value]);

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            mentionQuery.value = null;

            return;
        }
    }

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
    http.clearErrors();
    const problems: string[] = [];

    const tooBig = chosen.filter(
        (file) => file.size > props.limits.maxKilobytes * 1024,
    );

    for (const file of tooBig) {
        problems.push(
            `«${file.name}» supera el máximo de ${formatFileSize(props.limits.maxKilobytes * 1024)} por archivo.`,
        );
    }

    /* Within the chat's limit but above this server's PHP upload limit. */
    const serverMax = props.limits.server?.max_file_bytes ?? null;
    const overServer = chosen.filter(
        (file) =>
            !tooBig.includes(file) &&
            serverMax !== null &&
            file.size > serverMax,
    );

    for (const file of overServer) {
        problems.push(
            serverLimitNotice(file.size, serverMax ?? 0, `«${file.name}»`),
        );
    }

    const accepted = chosen.filter(
        (file) => !tooBig.includes(file) && !overServer.includes(file),
    );
    let merged = [...http.attachments, ...accepted];

    if (merged.length > props.limits.maxFiles) {
        problems.push(
            `Puedes adjuntar como máximo ${props.limits.maxFiles} archivos por mensaje.`,
        );
        merged = merged.slice(0, props.limits.maxFiles);
    }

    /* Every file travels in one request, capped by post_max_size. */
    const requestMax = props.limits.server?.max_request_bytes ?? null;

    let trimmed = false;

    while (
        requestMax !== null &&
        merged.length > http.attachments.length &&
        merged.reduce((total, file) => total + file.size, 0) > requestMax
    ) {
        merged = merged.slice(0, -1);
        trimmed = true;
    }

    if (trimmed && requestMax !== null) {
        problems.push(
            `Los archivos juntos pesan más de lo que el servidor de Chat Manager acepta en un mensaje (${formatFileSize(requestMax)}): envíalos en varios mensajes; un administrador puede ampliar el límite.`,
        );
    }

    localErrors.value = problems;
    http.attachments = merged;
}

/* The errors referred to positions that no longer exist: they go too. */
function removeFile(index: number): void {
    http.attachments = http.attachments.filter(
        (_, position) => position !== index,
    );
    localErrors.value = [];
    http.clearErrors();
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

    localErrors.value = [];
    uploadWasCancelled();
    http.reply_to_message_id = props.replyTo?.id ?? null;
    http.mentions = mentionsInText();
    mentionQuery.value = null;
    http.clearErrors();

    try {
        const response = await http.post(storeMessage.url(props.threadId), {
            onHttpException: (response) => {
                const message =
                    response.status === 403
                        ? 'Ya no puedes enviar mensajes en este chat.'
                        : response.status === 413
                          ? (props.limits.server?.message ??
                            'El servidor de Chat Manager no pudo recibir los archivos: su límite de subida es menor que su tamaño. Pide a un administrador que lo amplíe.')
                          : 'No se pudo enviar el mensaje. Inténtalo de nuevo.';
                localErrors.value = [message];

                return false;
            },
            onNetworkError: () => {
                localErrors.value = [
                    'No hay conexión con la plataforma. Tu mensaje no se envió; revisa tu internet e inténtalo de nuevo.',
                ];

                return false;
            },
        });

        // Refused by validation: the draft and its files stay, with the
        // errors shown (http.errors).
        if (!response) {
            return;
        }

        // A successful request makes the sent values the form defaults, so
        // reset() would bring the sent text back: empty the composer instead.
        http.defaults({
            body: '',
            reply_to_message_id: null,
            attachments: [],
            mentions: [],
        });
        http.reset();
        chosenMentions.value = [];
        lastTypingAt = 0;
        emit('sent', response.data);
        await nextTick();
        resize();
        focus();
    } catch {
        if (uploadWasCancelled()) {
            localErrors.value = [
                'Se canceló el envío. Los archivos siguen adjuntos: puedes volver a enviarlos o quitarlos.',
            ];
        }

        // Other errors are shown from http.errors / localErrors.
    }
}
</script>

<template>
    <div
        class="border-t bg-background p-2 sm:p-3"
        data-tour="team-chat.composer"
    >
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
                :class="
                    failedFiles.has(index) &&
                    'border-destructive/60 text-destructive'
                "
                :data-attachment-failed="failedFiles.has(index) || undefined"
                :aria-describedby="failedFiles.get(index)"
            >
                <Paperclip class="size-3.5 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ file.name }}</span>
                <span v-if="failedFiles.has(index)" class="sr-only">
                    (no se pudo enviar)</span
                >
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

        <div
            v-if="uploadProgress !== null"
            class="mb-2 flex items-center gap-2 text-xs text-muted-foreground"
        >
            <div
                class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-label="Progreso de la subida de los archivos"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="uploadProgress"
            >
                <div
                    class="h-full rounded-full bg-primary transition-[width] motion-reduce:transition-none"
                    :style="{ width: `${uploadProgress}%` }"
                />
            </div>
            <span class="shrink-0 tabular-nums">{{
                uploadProgress < 100 ? `${uploadProgress} %` : 'procesando…'
            }}</span>
            <Button
                v-if="canCancelUpload"
                type="button"
                variant="outline"
                size="sm"
                class="h-7 shrink-0 pointer-coarse:h-10"
                @click="cancelUpload"
            >
                Cancelar envío
            </Button>
        </div>

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
            <div class="relative flex min-w-0 flex-1">
                <ul
                    v-if="suggestions.length > 0"
                    :id="`${textareaId}-mentions`"
                    role="listbox"
                    aria-label="Mencionar a"
                    class="absolute bottom-full left-0 z-10 mb-1 grid w-64 max-w-full gap-0.5 rounded-lg border bg-popover p-1 text-popover-foreground shadow-md"
                >
                    <li
                        v-for="(person, index) in suggestions"
                        :id="`${textareaId}-mention-${person.id}`"
                        :key="person.id"
                        role="option"
                        :aria-selected="index === mentionIndex"
                        :class="
                            cn(
                                'cursor-pointer truncate rounded-md px-2 py-1.5 text-sm',
                                index === mentionIndex
                                    ? 'bg-accent text-accent-foreground'
                                    : 'hover:bg-accent',
                            )
                        "
                        @mousedown.prevent="chooseMention(person)"
                    >
                        {{ person.name }}
                    </li>
                </ul>
                <textarea
                    :id="textareaId"
                    ref="textarea"
                    v-model="http.body"
                    rows="1"
                    class="max-h-[200px] min-h-10 flex-1 resize-none rounded-xl border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    placeholder="Escribe un mensaje interno…"
                    :aria-invalid="errors.length > 0"
                    :aria-expanded="suggestions.length > 0"
                    :aria-controls="
                        suggestions.length > 0
                            ? `${textareaId}-mentions`
                            : undefined
                    "
                    :aria-activedescendant="
                        suggestions.length > 0
                            ? `${textareaId}-mention-${suggestions[mentionIndex]?.id}`
                            : undefined
                    "
                    @keydown="onKeydown"
                    @input="onInput"
                    @click="detectMention"
                    @blur="mentionQuery = null"
                />
            </div>
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
                    v-for="entry in errors"
                    :id="entry.id"
                    :key="entry.id"
                    :message="entry.message"
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
