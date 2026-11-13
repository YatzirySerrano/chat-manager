<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { CornerUpLeft, Paperclip, SendHorizontal, Smile, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    useId,
} from 'vue';
import InputError from '@/components/InputError.vue';
import DraftAttachment from '@/components/team-chat/DraftAttachment.vue';
import {
    attachmentSummary,
    draftKind,
    drawableImage,
    namedCapture,
    newClientReference,
    screenFiles,
} from '@/components/team-chat/teamChatFormat';
import TeamEmojiPicker from '@/components/team-chat/TeamEmojiPicker.vue';
import TeamMediaViewer from '@/components/team-chat/TeamMediaViewer.vue';
import type { ViewerItem } from '@/components/team-chat/TeamMediaViewer.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useUploadProgress } from '@/composables/useUploadProgress';
import { formatFileSize } from '@/lib/format';
import { UPLOAD_CODE_FIELD } from '@/lib/uploads';
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
 * line), files of the allowed types and emoji. Internal messages are never
 * sent to WhatsApp.
 *
 * Files arrive from the picker, from the clipboard (Ctrl+V of a screenshot
 * or of copied files) or dropped on the chat (the window calls addFiles).
 * Every file is checked here before anything is uploaded and shown with a
 * preview drawn from the file itself; nothing leaves the browser until the
 * message is sent.
 *
 * In groups, typing "@" suggests the current participants; the chosen ids
 * travel with the message (names are not unique) and the server keeps only
 * current participants. While writing, a "typing" signal is sent at most
 * every few seconds; it is never stored.
 *
 * Each message carries a `client_reference` (idempotency key). After a
 * failure whose outcome is unknown (no response, server error) the same key
 * is reused when the same message is sent again, so a message the server
 * did store is answered back instead of being stored and notified twice.
 *
 * The text being written is remembered per chat for this browser tab
 * (sessionStorage), so changing chats does not lose it. Files are not.
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
        client_reference: string;
    },
    { data: TeamMessage; replayed?: boolean }
>({
    body: '',
    reply_to_message_id: null,
    attachments: [],
    mentions: [],
    client_reference: '',
});
const typingHttp = useHttp<Record<string, never>>({});

const chosenMentions = ref<TeamMention[]>([]);
const mentionQuery = ref<string | null>(null);
const mentionIndex = ref(0);
let lastTypingAt = 0;

/* ------------------------------------------------------- remembered text */

const draftKey = computed(() => `team-chat.draft.${props.threadId}`);

function rememberDraft(): void {
    try {
        if (http.body.trim() === '') {
            window.sessionStorage.removeItem(draftKey.value);
        } else {
            window.sessionStorage.setItem(draftKey.value, http.body);
        }
    } catch {
        // Storage may be unavailable (private mode): the text is not remembered.
    }
}

const rememberDraftSoon = useDebounceFn(rememberDraft, 400);

/*
 * The idempotency key of the next send. It is kept only while the same
 * message (text, files and reply) is retried after an uncertain failure;
 * any change to the message makes it a new one with a new key.
 */
let clientReference = newClientReference();
let referenceMessage: string | null = null;

function messageFingerprint(): string {
    return JSON.stringify([
        http.body.trim(),
        http.reply_to_message_id,
        http.attachments.map((file) => [
            file.name,
            file.size,
            file.lastModified,
        ]),
    ]);
}

/* The last attempt certainly stored nothing, or succeeded: the next is new. */
function renewReference(): void {
    clientReference = newClientReference();
    referenceMessage = null;
}

/* The server's own explanation of a refusal, when it sent one. */
function serverMessage(data: string): string | null {
    try {
        const parsed: unknown = JSON.parse(data);

        if (
            parsed !== null &&
            typeof parsed === 'object' &&
            'message' in parsed &&
            typeof parsed.message === 'string' &&
            parsed.message !== ''
        ) {
            return parsed.message;
        }
    } catch {
        // Not JSON: the generic explanation is used.
    }

    return null;
}

const UNCERTAIN_SEND =
    'No se pudo confirmar si tu mensaje se envió. Vuelve a enviarlo: si ya había llegado, no se duplicará.';

function normalized(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
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
 * ("attachments.N") also mark their card (see failedFiles).
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

/* Card position → id of the error that explains why it failed. */
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
    void rememberDraftSoon();
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

/* ------------------------------------------------------------------ files */

/*
 * Object URLs of the attached images and videos (their previews). They are
 * made when a file is attached and released when it leaves the message.
 */
const previewUrls = shallowRef(new Map<File, string>());

function syncPreviews(): void {
    const next = new Map<File, string>();

    for (const file of http.attachments) {
        const kind = draftKind(file);

        if (kind !== 'video' && !drawableImage(file)) {
            continue;
        }

        next.set(
            file,
            previewUrls.value.get(file) ?? URL.createObjectURL(file),
        );
    }

    for (const [file, url] of previewUrls.value) {
        if (!next.has(file)) {
            URL.revokeObjectURL(url);
        }
    }

    previewUrls.value = next;
}

/**
 * Attach files (picker, clipboard or dropped on the chat). Whatever cannot
 * be sent is left out, each with its reason, before anything is uploaded.
 */
function addFiles(incoming: File[], extraProblems: string[] = []): void {
    if (http.processing) {
        localErrors.value = [
            'Espera a que termine el envío en curso para adjuntar más archivos.',
        ];

        return;
    }

    http.clearErrors();

    const screened = screenFiles(http.attachments, incoming, props.limits);

    localErrors.value = [...extraProblems, ...screened.problems];
    http.attachments = screened.files;
    syncPreviews();
    focus();
}

function pickFiles(event: Event): void {
    const input = event.target as HTMLInputElement;
    const chosen = Array.from(input.files ?? []);
    input.value = '';
    addFiles(chosen);
}

/*
 * Ctrl+V: a screenshot (Windows Snipping Tool, Print Screen) or files copied
 * in the file explorer become attachments. Text is pasted as always; when
 * the clipboard carries both text and a picture of it (a spreadsheet's
 * cells, a rich text selection) the text wins.
 */
function onPaste(event: ClipboardEvent): void {
    const data = event.clipboardData;

    if (!data) {
        return;
    }

    const files = Array.from(data.files ?? []);

    if (files.length === 0) {
        for (const item of Array.from(data.items ?? [])) {
            const file = item.kind === 'file' ? item.getAsFile() : null;

            if (file) {
                files.push(file);
            }
        }
    }

    if (files.length === 0 || data.getData('text/plain').trim() !== '') {
        return;
    }

    event.preventDefault();
    addFiles(files.map((file) => namedCapture(file)));
}

/* The errors referred to positions that no longer exist: they go too. */
function removeFile(index: number): void {
    http.attachments = http.attachments.filter(
        (_, position) => position !== index,
    );
    localErrors.value = [];
    http.clearErrors();
    syncPreviews();
}

/* The attached images and videos, to look at them before sending. */
const viewerOpen = ref(false);
const viewerIndex = ref(0);
const viewerItems = computed<ViewerItem[]>(() =>
    http.attachments.flatMap((file, position) => {
        const url = previewUrls.value.get(file);

        return url
            ? [
                  {
                      id: position,
                      kind: draftKind(file) === 'video' ? 'video' : 'image',
                      src: url,
                      name: file.name,
                      downloadUrl: null,
                      caption: `Sin enviar · ${formatFileSize(file.size)}`,
                  } satisfies ViewerItem,
              ]
            : [];
    }),
);

function previewFile(file: File): void {
    const url = previewUrls.value.get(file);
    const index = viewerItems.value.findIndex((item) => item.src === url);

    if (index >= 0) {
        viewerIndex.value = index;
        viewerOpen.value = true;
    }
}

async function insertEmoji(emoji: string): Promise<void> {
    const element = textarea.value;
    const start = element?.selectionStart ?? http.body.length;
    const end = element?.selectionEnd ?? http.body.length;

    http.body = `${http.body.slice(0, start)}${emoji}${http.body.slice(end)}`;
    emojiOpen.value = false;
    rememberDraft();
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

    const fingerprint = messageFingerprint();

    if (referenceMessage !== null && referenceMessage !== fingerprint) {
        renewReference();
    }

    referenceMessage = fingerprint;
    http.client_reference = clientReference;

    try {
        const response = await http.post(storeMessage.url(props.threadId), {
            onHttpException: (response) => {
                // 4xx: refused, nothing was stored. 5xx: it may have been.
                if (response.status < 500) {
                    renewReference();
                }

                const message =
                    response.status === 403
                        ? 'Ya no puedes enviar mensajes en este chat.'
                        : response.status === 404
                          ? 'Ya no participas en este chat. Tu mensaje no se envió.'
                          : response.status === 413
                            ? (props.limits.server?.message ??
                              'El servidor de Chat Manager no pudo recibir los archivos: su límite de subida es menor que su tamaño. Pide a un administrador que lo amplíe.')
                            : response.status === 429
                              ? 'Estás enviando mensajes muy rápido. Espera unos segundos y vuelve a intentarlo.'
                              : response.status === 503
                                ? (serverMessage(response.data) ??
                                  'El almacenamiento de archivos no está disponible. Tu mensaje no se envió; inténtalo de nuevo en unos minutos.')
                                : response.status >= 500
                                  ? UNCERTAIN_SEND
                                  : 'No se pudo enviar el mensaje. Inténtalo de nuevo.';
                localErrors.value = [message];

                return false;
            },
            onNetworkError: () => {
                // The request may have reached the server: the key is kept.
                localErrors.value = [
                    `Se perdió la conexión con la plataforma. ${UNCERTAIN_SEND}`,
                ];

                return false;
            },
        });

        // Refused by validation: the draft and its files stay, with the
        // errors shown (http.errors); nothing was stored.
        if (!response) {
            renewReference();

            return;
        }

        renewReference();

        // A successful request makes the sent values the form defaults, so
        // reset() would bring the sent text back: empty the composer instead.
        http.defaults({
            body: '',
            reply_to_message_id: null,
            attachments: [],
            mentions: [],
            client_reference: '',
        });
        http.reset();
        syncPreviews();
        rememberDraft();
        chosenMentions.value = [];
        lastTypingAt = 0;
        emit('sent', response.data);
        await nextTick();
        resize();
        focus();
    } catch {
        if (uploadWasCancelled()) {
            // The bytes never arrived completely: a new attempt is new.
            renewReference();
            localErrors.value = [
                'Se canceló el envío. Los archivos siguen adjuntos: puedes volver a enviarlos o quitarlos.',
            ];
        }

        // Other errors are shown from http.errors / localErrors.
    }
}

onMounted(async () => {
    try {
        const remembered = window.sessionStorage.getItem(draftKey.value);

        if (remembered && http.body === '') {
            http.body = remembered.slice(0, props.limits.messageMaxLength);
            await nextTick();
            resize();
        }
    } catch {
        // Storage may be unavailable: start empty.
    }
});

onBeforeUnmount(() => {
    rememberDraft();

    for (const url of previewUrls.value.values()) {
        URL.revokeObjectURL(url);
    }
});

defineExpose({ focus, addFiles });
</script>

<template>
    <div
        class="shrink-0 border-t bg-background px-2 pt-2 pb-2 sm:px-5 sm:pt-2.5 sm:pb-3"
        data-tour="team-chat.composer"
        data-safe-bottom
    >
        <div
            class="rounded-2xl border bg-card transition-[border-color,box-shadow] duration-(--duration-fast) ease-(--ease-standard) focus-within:border-primary focus-within:ring-[3px] focus-within:ring-primary/20 hover:border-border-strong focus-within:hover:border-primary"
            data-composer-surface
        >
            <div
                v-if="replyTo"
                class="flex items-center gap-2 border-b px-3 py-2 text-xs"
            >
                <CornerUpLeft
                    class="size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 border-l-2 border-primary pl-2">
                    <p class="truncate font-semibold">
                        Respondiendo a
                        {{
                            replyTo.is_mine
                                ? 'tu mensaje'
                                : replyTo.author?.name
                        }}
                    </p>
                    <p class="truncate text-muted-foreground">
                        {{
                            replyTo.body ??
                            (attachmentSummary(replyTo.attachments) ||
                                'Archivo adjunto')
                        }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-7 shrink-0 rounded-full pointer-coarse:size-9"
                    aria-label="Cancelar respuesta"
                    title="Cancelar respuesta (Esc)"
                    @click="emit('cancelReply')"
                >
                    <X class="size-3.5" />
                </Button>
            </div>

            <ul
                v-if="http.attachments.length > 0"
                class="flex gap-1.5 overflow-x-auto p-2 pb-1"
                aria-label="Archivos adjuntos sin enviar"
            >
                <DraftAttachment
                    v-for="(file, index) in http.attachments"
                    :key="`${file.name}-${file.size}-${file.lastModified}`"
                    :file="file"
                    :url="previewUrls.get(file) ?? null"
                    :error-id="failedFiles.get(index)"
                    :disabled="http.processing"
                    @remove="removeFile(index)"
                    @preview="previewFile(file)"
                />
            </ul>

            <div
                v-if="uploadProgress !== null"
                class="flex items-center gap-2 px-3 pt-1.5 text-xs text-muted-foreground"
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

            <div class="flex items-end gap-0.5 p-1.5">
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
                    class="shrink-0 rounded-full text-muted-foreground"
                    aria-label="Adjuntar archivos"
                    title="Adjuntar archivos (imágenes, PDF, documentos de Office, texto, audio o video). También puedes pegarlos o arrastrarlos al chat."
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
                            class="hidden shrink-0 rounded-full text-muted-foreground sm:inline-flex"
                            aria-label="Insertar emoji"
                            title="Insertar emoji"
                        >
                            <Smile />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="start" side="top">
                        <TeamEmojiPicker @pick="insertEmoji" />
                    </PopoverContent>
                </Popover>
                <label :for="textareaId" class="sr-only">Mensaje</label>
                <div class="relative flex min-w-0 flex-1">
                    <ul
                        v-if="suggestions.length > 0"
                        :id="`${textareaId}-mentions`"
                        role="listbox"
                        aria-label="Mencionar a"
                        class="absolute bottom-full left-0 z-10 mb-2 grid w-64 max-w-full gap-0.5 rounded-lg border bg-popover p-1 text-popover-foreground shadow-popover"
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
                        class="max-h-[200px] min-h-9 flex-1 resize-none rounded-lg border-0 bg-transparent px-2 py-2 text-base leading-snug outline-none placeholder:text-muted-foreground md:text-sm md:leading-5"
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
                        @paste="onPaste"
                        @click="detectMention"
                        @blur="mentionQuery = null"
                    />
                </div>
                <Button
                    size="icon"
                    class="shrink-0 rounded-full"
                    :disabled="!canSend"
                    aria-label="Enviar mensaje"
                    title="Enviar (Enter). Nueva línea: Shift + Enter"
                    :loading="http.processing"
                    @click="send"
                >
                    <SendHorizontal />
                </Button>
            </div>
        </div>
        <div class="mt-1 flex min-h-4 items-start justify-between gap-3 px-2">
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
                class="shrink-0 text-xs tabular-nums"
                :class="
                    remaining < 0 ? 'text-destructive' : 'text-muted-foreground'
                "
                :aria-label="
                    remaining < 0
                        ? `Sobran ${-remaining} caracteres`
                        : `Quedan ${remaining} caracteres`
                "
            >
                {{ remaining }}
            </span>
            <span
                v-else-if="errors.length === 0"
                class="hidden shrink-0 text-[0.7rem] text-muted-foreground/80 pointer-fine:lg:block"
                aria-hidden="true"
            >
                Enter para enviar, Shift + Enter para otra línea
            </span>
        </div>

        <TeamMediaViewer
            v-model:open="viewerOpen"
            v-model:index="viewerIndex"
            :items="viewerItems"
        />
    </div>
</template>
