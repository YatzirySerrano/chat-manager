<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import {
    FileText,
    LayoutTemplate,
    Lock,
    Paperclip,
    Send,
    StickyNote,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import EmojiPicker from '@/components/conversations/EmojiPicker.vue';
import {
    newClientReference,
    typeLabel,
} from '@/components/conversations/messageFormat';
import QuickReplyPicker from '@/components/quick-replies/QuickReplyPicker.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { usePermissions } from '@/composables/usePermissions';
import { formatDuration, formatFileSize } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { store as storeMessage } from '@/routes/conversations/messages';
import { store as storeNote } from '@/routes/conversations/notes';
import type {
    AvailableQuickReply,
    ComposerSettings,
    ConversationMessage,
    ConversationNote,
    ConversationSummary,
    CustomerWindow,
} from '@/types';

/**
 * Composer of the chat. Two clearly separated modes:
 *
 * - "Responder": a WhatsApp message to the customer (text, or one file with
 *   an optional caption). Only the responsible agent can use it while the
 *   customer service window is open; otherwise it explains why and, when
 *   the window closed, offers the approved template flow. Nothing (text,
 *   file or template) can be sent through a paused, deleted or misconfigured
 *   connection: the reason is shown instead and notes stay available.
 * - "Nota interna": a private note for the team, in another color and with
 *   an explicit label. It goes to a different endpoint and never to Meta.
 *
 * Every send attempt carries a client reference: retrying after a network
 * error reuses it, so the server stores the message once; the button is
 * disabled while a request runs.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    window: CustomerWindow;
    settings: ComposerSettings;
    replyTo: ConversationMessage | null;
}>();

const emit = defineEmits<{
    sent: [message: ConversationMessage];
    noteSaved: [note: ConversationNote];
    openTemplates: [];
    'update:replyTo': [message: ConversationMessage | null];
}>();

type Mode = 'reply' | 'note';

const page = usePage();
const { can: holdsPermission } = usePermissions();
const userId = computed(() => page.props.auth?.user?.id ?? 0);

const canReply = computed(() => props.conversation.can.reply);
const canNote = computed(() => props.conversation.can.addNote);
const mode = ref<Mode>(canReply.value || !canNote.value ? 'reply' : 'note');

/* Drafts survive switching between conversations during the session. */
const drafts = new Map<string, string>();
const draftKey = (id: number, draftMode: Mode): string => `${id}:${draftMode}`;

const text = ref('');
const attachment = ref<File | null>(null);
const attachmentPreview = ref<string | null>(null);
const attachmentError = ref<string | null>(null);
const error = ref<string | null>(null);
const sending = ref(false);
/* Declared before the immediate watcher below, which clears the file input. */
const fileInput = ref<HTMLInputElement | null>(null);
let clientReference = newClientReference();

watch(
    () => [props.conversation.id, mode.value] as const,
    ([id, currentMode], previous) => {
        if (previous) {
            drafts.set(draftKey(previous[0], previous[1]), text.value);
        }

        text.value = drafts.get(draftKey(id, currentMode)) ?? '';
        error.value = null;

        if (!previous || previous[0] !== id) {
            clearAttachment();
            clientReference = newClientReference();
        }
    },
    { immediate: true },
);

watch(
    () => props.conversation.id,
    () => {
        mode.value = canReply.value || !canNote.value ? 'reply' : 'note';
    },
);

/*
 * The viewer just became able to reply (accepted or was assigned the open
 * conversation): an untouched composer switches from "Nota interna" to
 * "Responder", so the first reply is not saved as a note by mistake.
 */
watch(canReply, (now, before) => {
    if (now && !before && mode.value === 'note' && text.value.trim() === '') {
        mode.value = 'reply';
    }
});

/* Enter to send: a per-user preference kept in this browser. */
const enterKey = computed(() => `chat-manager:composer-enter:${userId.value}`);
const enterSends = ref(true);

watch(
    enterKey,
    (key) => {
        try {
            enterSends.value = globalThis.localStorage.getItem(key) !== '0';
        } catch {
            enterSends.value = true;
        }
    },
    { immediate: true },
);

watch(enterSends, (value) => {
    try {
        globalThis.localStorage.setItem(enterKey.value, value ? '1' : '0');
    } catch {
        // Storage may be unavailable (private mode); the toggle still works.
    }
});

/** Why the customer cannot be written to right now, if they cannot. */
const replyBlock = computed<string | null>(() => {
    const conversation = props.conversation;

    if (conversation.status === 'closed') {
        return 'La conversación está cerrada. Reábrela para volver a atender al cliente.';
    }

    if (conversation.status === 'pending') {
        return conversation.can.accept
            ? 'La conversación está pendiente: acéptala para responder.'
            : 'La conversación está pendiente de que alguien la acepte.';
    }

    if (conversation.status === 'on_hold') {
        return conversation.can.resume
            ? 'La conversación está en espera: retómala para responder.'
            : 'La conversación está en espera.';
    }

    if (!canReply.value) {
        return conversation.assignee
            ? `Solo ${conversation.assignee.name}, responsable de la conversación, puede responder al cliente.`
            : 'No tienes permiso para responder a esta conversación.';
    }

    if (!conversation.connection.can_send) {
        const reason =
            conversation.connection.send_block_reason ??
            `La conexión de WhatsApp «${conversation.connection.name}» no puede enviar mensajes en este momento.`;

        return canNote.value
            ? `${reason} Puedes seguir agregando notas internas.`
            : reason;
    }

    if (!props.window.allows_free_form) {
        return (
            props.window.block_reason ??
            'La ventana de atención de 24 horas está cerrada: solo puedes enviar una plantilla aprobada.'
        );
    }

    return null;
});

const windowClosed = computed(
    () =>
        props.conversation.status === 'open' &&
        canReply.value &&
        props.conversation.connection.can_send &&
        !props.window.allows_free_form,
);

/* Sending may work, but nothing proves the credentials (e.g. restored). */
const unverifiedConnection = computed(
    () =>
        props.conversation.connection.can_send &&
        !props.conversation.connection.credentials_verified,
);
const canUseTemplates = computed(() => windowClosed.value);
const canUseQuickReplies = computed(() => holdsPermission('quick-replies.use'));

const disabledReason = computed<string | null>(() => {
    if (mode.value === 'note') {
        return canNote.value
            ? null
            : 'No tienes permiso para agregar notas internas.';
    }

    return replyBlock.value;
});

const maxLength = computed(() => {
    if (mode.value === 'note') {
        return props.settings.note_max;
    }

    return attachment.value
        ? props.settings.caption_max
        : props.settings.body_max;
});

const trimmed = computed(() => text.value.trim());
const tooLong = computed(() => text.value.length > maxLength.value);
const canSubmit = computed(
    () =>
        disabledReason.value === null &&
        !sending.value &&
        !tooLong.value &&
        attachmentError.value === null &&
        (trimmed.value !== '' ||
            (mode.value === 'reply' && attachment.value !== null)),
);

/* Attachments: validated here for a fast answer, again by the server. */
const accept = computed(() =>
    props.settings.attachments.flatMap((rule) => rule.mimes).join(','),
);

/** Names browsers give to types the server accepts under another name. */
const BROWSER_ALIASES: Record<string, string> = {
    'audio/x-m4a': 'audio/mp4',
    'audio/m4a': 'audio/mp4',
    'audio/mp3': 'audio/mpeg',
    'audio/x-aac': 'audio/aac',
    'video/3gp': 'video/3gpp',
    'image/jpg': 'image/jpeg',
};

function ruleFor(file: File) {
    const type = BROWSER_ALIASES[file.type] ?? file.type;

    return props.settings.attachments.find((candidate) =>
        candidate.mimes.includes(type),
    );
}

const largestLimit = computed(() =>
    Math.max(0, ...props.settings.attachments.map((rule) => rule.max_bytes)),
);

function clearAttachment(): void {
    if (attachmentPreview.value) {
        URL.revokeObjectURL(attachmentPreview.value);
    }

    attachment.value = null;
    attachmentPreview.value = null;
    attachmentError.value = null;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function chooseFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    clearAttachment();

    if (!file) {
        return;
    }

    const rule = ruleFor(file);

    attachment.value = file;

    if (!rule && file.type !== '') {
        attachmentError.value =
            'Este tipo de archivo no se puede enviar por WhatsApp.';
    } else if (!rule) {
        // The browser could not tell the type: the server reads the bytes.
        if (file.size > largestLimit.value) {
            attachmentError.value = `El archivo pesa ${formatFileSize(file.size)}; el máximo permitido es ${formatFileSize(largestLimit.value)}.`;
        }
    } else if (file.size > rule.max_bytes) {
        attachmentError.value = `El archivo pesa ${formatFileSize(file.size)}; el máximo para ${rule.label} es ${formatFileSize(rule.max_bytes)}.`;
    } else if (rule.type === 'image') {
        attachmentPreview.value = URL.createObjectURL(file);
    }

    clientReference = newClientReference();
}

const attachmentKind = computed(() =>
    attachment.value
        ? (ruleFor(attachment.value)?.type ?? 'document')
        : 'document',
);

/* The customer service window, always in sight while replying. */
const windowBadge = computed(() => {
    const current = props.window;

    if (props.conversation.status === 'closed' || current.status === 'none') {
        return null;
    }

    const tone = {
        open: 'border-success/30 bg-success/10 text-success',
        expiring: 'border-warning/40 bg-warning/10 text-warning',
        expired: 'border-destructive/30 bg-destructive/10 text-destructive',
        none: '',
    }[current.status];

    return {
        tone,
        text: current.allows_free_form
            ? `Ventana abierta · quedan ${formatDuration(current.remaining_seconds)}`
            : 'Ventana de 24 h cerrada',
        title: current.label,
    };
});

/* Quick replies through "/". */
const textarea = ref<HTMLTextAreaElement | null>(null);
const picker = ref<InstanceType<typeof QuickReplyPicker> | null>(null);
const trigger = ref<{ start: number; query: string } | null>(null);
const dismissedAt = ref<number | null>(null);

function updateTrigger(): void {
    const element = textarea.value;

    if (
        !canUseQuickReplies.value ||
        mode.value !== 'reply' ||
        !element ||
        element.selectionStart !== element.selectionEnd
    ) {
        trigger.value = null;

        return;
    }

    const caret = element.selectionStart;
    const match = /(^|\s)\/([a-z0-9_-]*)$/i.exec(text.value.slice(0, caret));

    if (!match) {
        trigger.value = null;
        dismissedAt.value = null;

        return;
    }

    const start = caret - match[2].length - 1;

    trigger.value =
        dismissedAt.value === start
            ? null
            : { start, query: match[2].toLowerCase() };
}

function closePicker(): void {
    dismissedAt.value = trigger.value?.start ?? null;
    trigger.value = null;
}

async function insertAtCaret(value: string, from?: number): Promise<void> {
    const element = textarea.value;
    const caret = element?.selectionStart ?? text.value.length;
    const start = from ?? caret;

    text.value = text.value.slice(0, start) + value + text.value.slice(caret);

    await nextTick();

    const position = start + value.length;
    element?.focus();
    element?.setSelectionRange(position, position);
}

async function insertQuickReply(reply: AvailableQuickReply): Promise<void> {
    const current = trigger.value;

    trigger.value = null;
    await insertAtCaret(reply.text, current?.start);
}

function onKeydown(event: KeyboardEvent): void {
    if (trigger.value && picker.value?.handleKeydown(event)) {
        event.preventDefault();

        return;
    }

    if (event.key !== 'Enter' || event.isComposing) {
        return;
    }

    const shortcut = event.ctrlKey || event.metaKey;

    if ((enterSends.value && !event.shiftKey && !event.altKey) || shortcut) {
        event.preventDefault();
        submit();
    }
}

const sendHttp = useHttp<
    {
        body: string;
        client_reference: string;
        attachment: File | null;
        reply_to_message_id: number | null;
    },
    { data: ConversationMessage }
>({
    body: '',
    client_reference: '',
    attachment: null,
    reply_to_message_id: null,
});

const noteHttp = useHttp<{ body: string }, { data: ConversationNote }>({
    body: '',
});

const uploadProgress = computed(() => {
    const percentage = sendHttp.progress?.percentage;

    return sending.value && attachment.value && percentage !== undefined
        ? Math.round(percentage)
        : null;
});

function responseMessage(failure: unknown): string {
    if (
        typeof failure === 'object' &&
        failure !== null &&
        'response' in failure
    ) {
        const response = (
            failure as { response: { status: number; data: string } }
        ).response;

        try {
            const parsed = JSON.parse(response.data) as { message?: string };

            if (response.status === 409 && parsed.message) {
                return parsed.message;
            }
        } catch {
            // Not JSON: fall through to the generic explanation.
        }

        if (response.status === 404) {
            return 'La conversación ya no está a tu alcance.';
        }

        if (response.status === 429) {
            return 'Estás enviando demasiado rápido. Espera unos segundos e inténtalo de nuevo.';
        }

        if (response.status === 413) {
            return 'El archivo es demasiado grande para subirlo.';
        }

        return 'El servidor no pudo procesar el envío. Inténtalo de nuevo.';
    }

    return 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo: el mensaje no se duplicará.';
}

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }

    sending.value = true;
    error.value = null;

    try {
        if (mode.value === 'note') {
            noteHttp.body = text.value;
            const response = await noteHttp.post(
                storeNote.url(props.conversation.id),
                {
                    onError: (errors) => {
                        error.value = Object.values(errors)[0] ?? null;
                    },
                },
            );

            if (response) {
                emit('noteSaved', response.data);
                text.value = '';
                drafts.delete(draftKey(props.conversation.id, 'note'));
            }

            return;
        }

        sendHttp.body = text.value;
        sendHttp.client_reference = clientReference;
        sendHttp.attachment = attachment.value;
        sendHttp.reply_to_message_id = props.replyTo?.id ?? null;

        let rejected = false;
        const response = await sendHttp.post(
            storeMessage.url(props.conversation.id),
            {
                onError: (errors) => {
                    rejected = true;
                    error.value = Object.values(errors)[0] ?? null;
                },
            },
        );

        if (rejected) {
            // Refused by validation: nothing was stored, a new attempt is new.
            clientReference = newClientReference();

            return;
        }

        if (response) {
            emit('sent', response.data);
            text.value = '';
            drafts.delete(draftKey(props.conversation.id, 'reply'));
            clearAttachment();
            emit('update:replyTo', null);
            clientReference = newClientReference();
        }
    } catch (failure) {
        error.value = responseMessage(failure);

        const status =
            typeof failure === 'object' &&
            failure !== null &&
            'response' in failure
                ? (failure as { response: { status: number } }).response.status
                : null;

        // A conflict or refusal stored nothing; a network error might have.
        if (status !== null && status >= 400 && status < 500) {
            clientReference = newClientReference();
        }

        // The conversation or its connection changed meanwhile: show the
        // current state (the draft stays in the composer).
        if (status === 409) {
            router.reload({ only: ['conversation', 'window'] });
        }
    } finally {
        sending.value = false;
        await nextTick();
        textarea.value?.focus();
    }
}

const recipient = computed(() => {
    const contact = props.conversation.contact;
    const phone = contact.phone_e164 ? formatPhone(contact.phone_e164) : null;

    return phone ? `${contact.name} (${phone})` : contact.name;
});

const placeholder = computed(() => {
    if (disabledReason.value) {
        return '';
    }

    if (mode.value === 'note') {
        return 'Escribe una nota para el equipo. El cliente no la verá.';
    }

    return attachment.value
        ? 'Agrega un texto para el archivo (opcional)'
        : canUseQuickReplies.value
          ? 'Escribe un mensaje. «/» abre las respuestas rápidas.'
          : 'Escribe un mensaje';
});

const hint = computed(() =>
    enterSends.value
        ? 'Enter envía · Shift+Enter agrega un salto de línea'
        : 'Ctrl+Enter (⌘+Enter) envía · Enter agrega un salto de línea',
);

function focus(): void {
    textarea.value?.focus();
}

defineExpose({ focus });
</script>

<template>
    <div
        class="border-t bg-background px-3 pt-2 pb-3 sm:px-4"
        :class="mode === 'note' && 'bg-warning/5'"
    >
        <div
            class="mb-2 flex flex-wrap items-center justify-between gap-2"
            role="tablist"
            aria-label="Tipo de mensaje"
        >
            <div
                class="inline-flex rounded-lg border bg-muted/40 p-0.5 text-sm"
            >
                <button
                    type="button"
                    role="tab"
                    :aria-selected="mode === 'reply'"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 font-medium transition-colors"
                    :class="
                        mode === 'reply'
                            ? 'bg-background text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="mode = 'reply'"
                >
                    <Send class="size-3.5" aria-hidden="true" />
                    Responder al cliente
                </button>
                <button
                    v-if="canNote"
                    type="button"
                    role="tab"
                    :aria-selected="mode === 'note'"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1 font-medium transition-colors"
                    :class="
                        mode === 'note'
                            ? 'bg-warning/20 text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="mode = 'note'"
                >
                    <StickyNote class="size-3.5" aria-hidden="true" />
                    Nota interna
                </button>
            </div>
            <p
                class="min-w-0 truncate text-xs text-muted-foreground"
                :title="mode === 'reply' ? `Para: ${recipient}` : undefined"
            >
                <template v-if="mode === 'reply'">
                    <span
                        v-if="windowBadge"
                        class="mr-1.5 inline-flex items-center rounded-full border px-1.5 py-px text-[11px] font-medium"
                        :class="windowBadge.tone"
                        :title="windowBadge.title"
                        >{{ windowBadge.text }}</span
                    >
                    Para:
                    <span class="font-medium text-foreground">{{
                        recipient
                    }}</span>
                    por WhatsApp · {{ conversation.connection.name }}
                </template>
                <span
                    v-else
                    class="inline-flex items-center gap-1 font-semibold text-warning"
                >
                    <Lock class="size-3" aria-hidden="true" />
                    Nota interna — no se envía al cliente
                </span>
            </p>
        </div>

        <div
            v-if="disabledReason"
            class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed px-3 py-2.5 text-sm text-muted-foreground"
            role="status"
        >
            <Lock class="size-4 shrink-0" aria-hidden="true" />
            <p class="min-w-0 flex-1">{{ disabledReason }}</p>
            <Button
                v-if="mode === 'reply' && canUseTemplates"
                size="sm"
                @click="emit('openTemplates')"
            >
                <LayoutTemplate aria-hidden="true" />
                Enviar plantilla
            </Button>
        </div>

        <form v-else novalidate class="grid gap-2" @submit.prevent="submit">
            <div
                v-if="replyTo && mode === 'reply'"
                class="flex items-start gap-2 rounded-lg border-l-2 border-primary bg-muted/50 px-3 py-1.5 text-xs"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-medium">
                        Respondiendo a
                        {{
                            replyTo.direction === 'inbound'
                                ? conversation.contact.name
                                : 'un mensaje del equipo'
                        }}
                    </p>
                    <p class="line-clamp-2 text-muted-foreground">
                        {{ replyTo.body ?? typeLabel(replyTo.type) }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded p-0.5 text-muted-foreground hover:text-foreground"
                    aria-label="Quitar la cita"
                    @click="emit('update:replyTo', null)"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </div>

            <div
                v-if="attachment && mode === 'reply'"
                class="flex items-center gap-3 rounded-lg border px-3 py-2"
                :class="attachmentError && 'border-destructive/60'"
            >
                <img
                    v-if="attachmentPreview"
                    :src="attachmentPreview"
                    alt="Vista previa del archivo adjunto"
                    class="size-12 rounded object-cover"
                />
                <FileText
                    v-else
                    class="size-8 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 text-xs">
                    <p class="truncate font-medium" :title="attachment.name">
                        {{ attachment.name }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ typeLabel(attachmentKind) }} ·
                        {{ formatFileSize(attachment.size) }}
                        <template v-if="uploadProgress !== null">
                            · subiendo {{ uploadProgress }} %</template
                        >
                    </p>
                    <p v-if="attachmentError" class="text-destructive">
                        {{ attachmentError }}
                    </p>
                </div>
                <button
                    type="button"
                    class="rounded p-1 text-muted-foreground hover:text-foreground"
                    aria-label="Quitar el archivo adjunto"
                    :disabled="sending"
                    @click="clearAttachment"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>

            <div class="relative">
                <label for="chat-composer" class="sr-only">{{
                    mode === 'reply'
                        ? `Mensaje para ${conversation.contact.name}`
                        : 'Nota interna para el equipo'
                }}</label>
                <textarea
                    id="chat-composer"
                    ref="textarea"
                    v-model="text"
                    rows="2"
                    role="combobox"
                    aria-autocomplete="list"
                    :aria-expanded="trigger !== null"
                    :aria-controls="
                        trigger !== null ? picker?.listId : undefined
                    "
                    :aria-activedescendant="
                        trigger !== null ? picker?.activeOptionId : undefined
                    "
                    :aria-describedby="
                        error ? 'chat-composer-error' : 'chat-composer-hint'
                    "
                    :aria-invalid="tooLong || error !== null"
                    :placeholder="placeholder"
                    class="flex field-sizing-content max-h-48 min-h-11 w-full resize-y rounded-lg border bg-background px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    :class="
                        mode === 'note' &&
                        'border-warning/60 bg-warning/5 focus-visible:border-warning focus-visible:ring-warning/30'
                    "
                    @input="updateTrigger"
                    @click="updateTrigger"
                    @keyup="
                        (event) =>
                            ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(
                                event.key,
                            ) && updateTrigger()
                    "
                    @keydown="onKeydown"
                    @blur="trigger = null"
                />
                <div
                    v-if="trigger"
                    class="absolute inset-x-0 bottom-full z-20 mb-1"
                >
                    <QuickReplyPicker
                        ref="picker"
                        :query="trigger.query"
                        :contact-id="conversation.contact.id"
                        :department-id="conversation.department?.id ?? null"
                        @select="insertQuickReply"
                        @close="closePicker"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1">
                <template v-if="mode === 'reply'">
                    <input
                        ref="fileInput"
                        type="file"
                        class="sr-only"
                        :accept="accept"
                        tabindex="-1"
                        aria-hidden="true"
                        @change="chooseFile"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-9 text-muted-foreground"
                        :disabled="sending"
                        title="Adjuntar archivo (imagen, documento, audio o video)"
                        aria-label="Adjuntar archivo"
                        @click="fileInput?.click()"
                    >
                        <Paperclip aria-hidden="true" />
                    </Button>
                </template>
                <EmojiPicker
                    :disabled="sending"
                    @select="(emoji) => insertAtCaret(emoji)"
                />
                <label
                    class="ml-1 hidden cursor-pointer items-center gap-1.5 text-xs text-muted-foreground sm:inline-flex"
                >
                    <Switch
                        v-model="enterSends"
                        aria-label="Enviar con Enter"
                    />
                    Enter para enviar
                </label>
                <span
                    class="ml-auto text-[11px] tabular-nums"
                    :class="
                        tooLong ? 'text-destructive' : 'text-muted-foreground'
                    "
                    :aria-hidden="!tooLong"
                    :role="tooLong ? 'alert' : undefined"
                >
                    {{ text.length }}/{{ maxLength }}
                </span>
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    :variant="mode === 'note' ? 'outline' : 'default'"
                    :class="mode === 'note' && 'border-warning/60'"
                >
                    <Spinner v-if="sending" />
                    <StickyNote
                        v-else-if="mode === 'note'"
                        aria-hidden="true"
                    />
                    <Send v-else aria-hidden="true" />
                    {{
                        sending
                            ? mode === 'note'
                                ? 'Guardando…'
                                : 'Enviando…'
                            : mode === 'note'
                              ? 'Guardar nota'
                              : 'Enviar'
                    }}
                </Button>
            </div>
            <p
                v-if="error"
                id="chat-composer-error"
                class="text-xs text-destructive"
                role="alert"
            >
                {{ error }}
            </p>
            <p
                v-else-if="mode === 'reply' && unverifiedConnection"
                id="chat-composer-hint"
                class="flex items-center gap-1 text-[11px] text-warning"
                role="status"
            >
                <TriangleAlert class="size-3 shrink-0" aria-hidden="true" />
                Las credenciales de la conexión «{{
                    conversation.connection.name
                }}» aún no se han verificado con Meta: el envío podría fallar.
            </p>
            <p
                v-else
                id="chat-composer-hint"
                class="text-[11px] text-muted-foreground"
            >
                {{ hint }}
            </p>
        </form>
    </div>
</template>
