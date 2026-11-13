<script setup lang="ts">
import {
    Ban,
    Bot,
    ChartNoAxesColumn,
    CircleAlert,
    CircleQuestionMark,
    Copy,
    ExternalLink,
    FlaskConical,
    LayoutTemplate,
    Link2,
    Link2Off,
    MapPin,
    MessageSquareDashed,
    MousePointerClick,
    Plug,
    Reply,
    RotateCw,
    TriangleAlert,
    UserRound,
} from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed, inject } from 'vue';
import { toast } from 'vue-sonner';
import MessageAttachmentPreview from '@/components/conversations/MessageAttachmentPreview.vue';
import MessageStatusIndicator from '@/components/conversations/MessageStatusIndicator.vue';
import {
    DELIVERY_UNKNOWN,
    deliveryUnknownLabel,
    formatTime,
    typeLabel,
} from '@/components/conversations/messageFormat';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import WhatsAppText from '@/components/WhatsAppText.vue';
import {
    CUSTOM_BUBBLE_CLASSES,
    CUSTOM_CHAT_COLORS,
} from '@/lib/chatAppearance';
import { googleMapsUrl } from '@/lib/conversations/media';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type { ConversationMessage, MessageAttachment } from '@/types';

/**
 * One customer message or reply. Inbound messages sit on the left and
 * replies on the right; consecutive messages of the same author read as one
 * group (the name shows once and only the last bubble keeps its tail).
 * Structured content (location, shared contacts, interactive answers,
 * reactions, polls, templates) has its own renderer, a deleted message
 * leaves a tombstone, a type this version does not know still reads as a
 * sentence, and a failed reply shows why and, when allowed, a retry button.
 */
const props = withDefaults(
    defineProps<{
        message: ConversationMessage;
        /** The viewer may retry a failed reply (responsible agent). */
        canRetry?: boolean;
        retrying?: boolean;
        /** The viewer may answer quoting this message. */
        canQuote?: boolean;
        contactName: string;
        /** Name of the conversation's channel (copy such as "… no confirmó la entrega"). */
        channelLabel?: string;
        /** Attachment whose download link is being revoked. */
        revokingAttachmentId?: number | null;
        /** The message before / after is of the same author, moments apart. */
        groupedWithPrevious?: boolean;
        groupedWithNext?: boolean;
    }>(),
    {
        canRetry: false,
        retrying: false,
        revokingAttachmentId: null,
        canQuote: false,
        channelLabel: 'el canal',
        groupedWithPrevious: false,
        groupedWithNext: false,
    },
);

const emit = defineEmits<{
    retry: [message: ConversationMessage];
    quote: [message: ConversationMessage];
    jump: [messageId: number];
    revokeLink: [message: ConversationMessage, attachment: MessageAttachment];
}>();

const outbound = computed(() => props.message.direction === 'outbound');
/* The viewer's own chat colors (CSS variables of the chat), when set. */
const customColors = inject(
    CUSTOM_CHAT_COLORS,
    computed(() => false),
);
/**
 * With personal colors only the chosen text color is guaranteed (≥4.5:1)
 * against the bubble, so secondary text, links, badges and boxes inside it
 * use that color at full strength (currentColor): theme tones, opacity and
 * translucent fills are left for the theme's own bubbles.
 */
function themed(themeClasses: string, customClasses = ''): string {
    return customColors.value ? customClasses : themeClasses;
}

const secondaryText = computed(() => themed('text-muted-foreground'));
const linkText = computed(() => themed('text-primary', 'font-medium'));
const softBox = computed(() =>
    themed(
        'bg-background/60 text-muted-foreground',
        'border border-current/30',
    ),
);
const quoteBox = computed(() =>
    themed(
        'border-primary/70 bg-background/55 text-muted-foreground',
        'border border-current/30 border-l-current',
    ),
);
/*
 * Brought from another system (Whaticket): it has no delivery state here,
 * so it shows neither ticks nor a retry, only where it came from.
 */
const imported = computed(() => props.message.imported ?? null);
const importedLabel = computed(() => {
    const origin = imported.value;

    if (!origin) {
        return null;
    }

    const source = origin.source === 'whaticket' ? 'Whaticket' : origin.source;

    return origin.sender_name
        ? `Importado de ${source} · ${origin.sender_name}`
        : `Importado de ${source}`;
});
/* Deleted for everyone by its sender: only a tombstone is left. */
const revoked = computed(() => Boolean(props.message.revoked_at));
const edited = computed(
    () => !revoked.value && Boolean(props.message.edited_at),
);
const failed = computed(
    () =>
        !imported.value && !revoked.value && props.message.status === 'failed',
);
/* Meta may have received it: a warning, not a definitive failure. */
const unknownDelivery = computed(
    () => failed.value && props.message.error_code === DELIVERY_UNKNOWN,
);

const content = computed(
    () => (props.message.content ?? null) as Record<string, unknown> | null,
);
const type = computed<string>(() => props.message.type);

function numberOrNull(value: unknown): number | null {
    const parsed = typeof value === 'string' ? Number(value) : value;

    return typeof parsed === 'number' && Number.isFinite(parsed)
        ? parsed
        : null;
}

function textOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== ''
        ? value.trim()
        : null;
}

const location = computed(() => {
    if (type.value !== 'location' || revoked.value) {
        return null;
    }

    const latitude = numberOrNull(content.value?.latitude);
    const longitude = numberOrNull(content.value?.longitude);
    const located = latitude !== null && longitude !== null;

    return {
        name: textOrNull(content.value?.name),
        address: textOrNull(content.value?.address),
        url: located ? googleMapsUrl(latitude, longitude) : null,
        coordinates: located
            ? `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`
            : null,
    };
});

const sharedContacts = computed(() =>
    type.value === 'contacts' && !revoked.value && content.value
        ? ((content.value.contacts as
              | { name: string | null; phones?: string[] | null }[]
              | undefined) ?? [])
        : [],
);

const interactive = computed(() =>
    type.value === 'interactive' && !revoked.value && content.value
        ? {
              title: textOrNull(content.value.title),
              description: textOrNull(content.value.description),
          }
        : null,
);

const reaction = computed(() =>
    type.value === 'reaction' && !revoked.value
        ? {
              emoji: textOrNull(content.value?.emoji) ?? props.message.body,
              target: numberOrNull(content.value?.reacted_message_id),
          }
        : null,
);

/** A poll: its question and options, with votes when the channel reports them. */
const poll = computed(() => {
    if (type.value !== 'poll' || revoked.value) {
        return null;
    }

    const options = Array.isArray(content.value?.options)
        ? (content.value.options as unknown[])
              .map((option) =>
                  typeof option === 'string'
                      ? { name: option, votes: null }
                      : {
                            name:
                                textOrNull(
                                    (option as { name?: unknown } | null)?.name,
                                ) ?? '',
                            votes: numberOrNull(
                                (option as { votes?: unknown } | null)?.votes,
                            ),
                        },
              )
              .filter((option) => option.name !== '')
        : [];

    return {
        question:
            textOrNull(content.value?.question) ??
            textOrNull(content.value?.name) ??
            props.message.body ??
            'Encuesta',
        options,
    };
});

/** Meta's flag for an animated sticker (the file keeps its frames either way). */
const stickerAnimated = computed(
    () => type.value === 'sticker' && content.value?.animated === true,
);

const MEDIA_TYPES = ['image', 'audio', 'video', 'document', 'sticker'];
const isMedia = computed(() => MEDIA_TYPES.includes(type.value));
/* Types with a renderer of their own, or that are plain text. */
const KNOWN_TYPES = [
    ...MEDIA_TYPES,
    'text',
    'button',
    'template',
    'location',
    'contacts',
    'interactive',
    'reaction',
    'poll',
];

const attachments = computed(() =>
    revoked.value ? [] : (props.message.attachments ?? []),
);

/* A sticker alone is drawn without the bubble around it. */
const bare = computed(
    () =>
        type.value === 'sticker' &&
        attachments.value.length > 0 &&
        attachments.value.every((attachment) => attachment.available) &&
        !props.message.body &&
        !props.message.reply_to &&
        !failed.value,
);

const voice = computed(
    () => type.value === 'audio' && content.value?.voice === true,
);
const mediaSeconds = computed(() => numberOrNull(content.value?.seconds));
const gif = computed(
    () =>
        type.value === 'video' &&
        (content.value?.gif === true || content.value?.gif_playback === true),
);

/** Body shown as text (captions for media, the text itself otherwise). */
const text = computed(() => {
    if (
        revoked.value ||
        reaction.value ||
        location.value ||
        interactive.value ||
        poll.value
    ) {
        return null;
    }

    return props.message.body;
});

/**
 * What to say when there is nothing to draw: a type this version does not
 * know, or one the channel does not let an application read. The hint the
 * server found in the content is used when there is one.
 */
const fallback = computed<string | null>(() => {
    if (
        revoked.value ||
        text.value ||
        isMedia.value ||
        location.value ||
        interactive.value ||
        reaction.value ||
        poll.value ||
        sharedContacts.value.length > 0
    ) {
        return null;
    }

    const hint =
        textOrNull(content.value?.hint) ??
        textOrNull(content.value?.description) ??
        textOrNull(content.value?.title);

    if (hint) {
        return hint;
    }

    if (type.value === 'unsupported') {
        return `Este mensaje tiene un formato que ${props.channelLabel} no permite mostrar aquí. Pídele al cliente que lo reenvíe como texto, foto o documento si necesitas verlo.`;
    }

    if (!KNOWN_TYPES.includes(type.value)) {
        const original = textOrNull(content.value?.original_type) ?? type.value;

        return `Mensaje de tipo «${original}» que todavía no se puede mostrar aquí.`;
    }

    return `${typeLabel(type.value)} sin texto`;
});

/**
 * The quoted message, whichever shape the server used: the stored quote of
 * this or an earlier conversation, or the text preview of the WhatsApp
 * history (which may not be stored here at all).
 */
const quote = computed(() => {
    const quoted = props.message.reply_to;

    if (!quoted || revoked.value) {
        return null;
    }

    if ('origin' in quoted) {
        if (quoted.origin === 'restricted') {
            return { mode: 'restricted' as const };
        }

        const previous = quoted.origin === 'previous_conversation';

        if (previous && !quoted.url) {
            return { mode: 'restricted' as const };
        }

        return {
            mode: previous ? ('link' as const) : ('jump' as const),
            id: quoted.id,
            url: quoted.url,
            author:
                (quoted.direction === 'inbound'
                    ? props.contactName
                    : 'Equipo') + (previous ? ' · conversación anterior' : ''),
            text: quoted.body ?? typeLabel(quoted.type),
        };
    }

    return {
        mode: quoted.id ? ('jump' as const) : ('static' as const),
        id: quoted.id,
        url: null,
        author: quoted.from_me ? 'Equipo' : props.contactName,
        text: textOrNull(quoted.preview) ?? 'Mensaje',
    };
});

/* Reactions under the bubble, one chip per emoji. */
const reactionChips = computed(() => {
    const chips = new Map<
        string,
        { emoji: string; count: number; mine: boolean }
    >();

    for (const item of props.message.reactions ?? []) {
        const emoji = textOrNull(item?.emoji);

        if (!emoji) {
            continue;
        }

        const chip = chips.get(emoji) ?? { emoji, count: 0, mine: false };

        chip.count++;
        chip.mine = chip.mine || item.from_me === true;
        chips.set(emoji, chip);
    }

    return [...chips.values()];
});

const author = computed(() => {
    if (!outbound.value) {
        return props.contactName;
    }

    if (props.message.automatic) {
        return 'Mensaje automático';
    }

    if (props.message.integration) {
        return `Integración: ${props.message.integration.name}`;
    }

    return props.message.sender?.name ?? 'Equipo';
});

/** The customer's download link can still be revoked (outbound link deliveries). */
function canRevokeLink(attachment: MessageAttachment): boolean {
    return Boolean(attachment.link_revoke_url) && !attachment.link_revoked_at;
}

const time = computed(() => props.message.sent_at ?? props.message.created_at);

/** A file the channel cannot carry (ZIP on WhatsApp) went as a secure download link. */
function linkLabel(expiresAt: string | null | undefined): string {
    if (!expiresAt) {
        return 'Enviado como enlace de descarga seguro';
    }

    return new Date(expiresAt).getTime() < Date.now()
        ? `Enlace de descarga · venció el ${formatDate(expiresAt)}`
        : `Enlace de descarga · vence el ${formatDate(expiresAt)}`;
}

/* Text worth copying: the body, or what a location or a contact says. */
const copyable = computed<string | null>(() => {
    if (revoked.value) {
        return null;
    }

    if (location.value) {
        return (
            [location.value.name, location.value.address, location.value.url]
                .filter(Boolean)
                .join('\n') || null
        );
    }

    return textOrNull(props.message.body);
});

async function copyText(): Promise<void> {
    if (!copyable.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(copyable.value);
        toast.success('Mensaje copiado');
    } catch {
        toast.error(
            'No se pudo copiar. Selecciona el texto y cópialo con Ctrl+C.',
        );
    }
}

const toolButton =
    'grid size-7 place-items-center rounded-full border bg-background text-muted-foreground shadow-(--shadow-control) outline-none transition-[color,scale] duration-(--duration-fast) hover:scale-110 hover:text-primary focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-9';
</script>

<template>
    <div
        class="group flex w-full"
        :class="[
            outbound ? 'justify-end' : 'justify-start',
            groupedWithPrevious ? 'mt-0.5' : 'mt-2.5',
        ]"
        :data-grouped="groupedWithPrevious ? 'true' : undefined"
    >
        <div
            class="relative flex max-w-[85%] min-w-0 flex-col sm:max-w-[min(72%,44rem)]"
            :class="outbound ? 'items-end' : 'items-start'"
        >
            <div
                class="relative max-w-full min-w-0 text-sm/snug wrap-anywhere"
                :class="[
                    bare
                        ? 'px-0 py-0'
                        : [
                              'rounded-bubble px-2 pt-1.5 pb-1 transition-shadow duration-(--duration-base) ease-(--ease-out-quint) group-hover:shadow-hover',
                              outbound
                                  ? [
                                        groupedWithPrevious && 'rounded-tr-md',
                                        groupedWithNext
                                            ? 'rounded-br-md'
                                            : 'rounded-br-sm',
                                        customColors
                                            ? CUSTOM_BUBBLE_CLASSES.sent
                                            : 'bg-bubble-out text-bubble-out-foreground',
                                    ]
                                  : [
                                        'border border-border-strong/60',
                                        groupedWithPrevious && 'rounded-tl-md',
                                        groupedWithNext
                                            ? 'rounded-bl-md'
                                            : 'rounded-bl-sm',
                                        customColors
                                            ? CUSTOM_BUBBLE_CLASSES.received
                                            : 'bg-bubble-in text-bubble-in-foreground',
                                    ],
                          ],
                    failed &&
                        (unknownDelivery
                            ? 'ring-1 ring-warning/60'
                            : 'ring-1 ring-destructive/60'),
                ]"
                :data-message-id="message.id"
                :data-message-type="message.type"
                :data-message-revoked="revoked ? 'true' : undefined"
                data-layout-box="bubble"
            >
                <!--
                    The author shows once per group. A customer's name is
                    already in the header of the chat: it stays for screen
                    readers only.
                -->
                <p
                    v-if="!bare"
                    class="flex flex-wrap items-center gap-1.5 px-1 text-xs font-semibold"
                    :class="[
                        secondaryText,
                        !message.simulated &&
                            (groupedWithPrevious || !outbound) &&
                            'sr-only',
                        'mb-0.5',
                    ]"
                >
                    <Bot
                        v-if="message.automatic"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <Plug
                        v-else-if="message.integration"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span
                        class="min-w-0 truncate"
                        :class="
                            message.simulated &&
                            (groupedWithPrevious || !outbound) &&
                            'sr-only'
                        "
                        :title="author"
                        >{{ author }}</span
                    >
                    <span
                        v-if="message.simulated"
                        class="inline-flex items-center gap-1 rounded px-1.5 py-px text-xs font-semibold"
                        :class="
                            themed(
                                'bg-warning/15 text-warning',
                                'border border-current/40',
                            )
                        "
                        :title="`Este mensaje se registró en modo de prueba y no llegó al cliente por ${channelLabel}. Para enviar de verdad, un administrador debe activar el envío real.`"
                    >
                        <FlaskConical class="size-3.5" aria-hidden="true" />
                        No enviado (modo de prueba)
                    </span>
                </p>

                <!-- Deleted for everyone: nothing of it is shown again. -->
                <p
                    v-if="revoked"
                    class="flex items-center gap-1.5 px-1 py-0.5 italic"
                    :class="secondaryText"
                    data-message-tombstone
                >
                    <Ban class="size-3.5 shrink-0" aria-hidden="true" />
                    {{
                        outbound
                            ? 'Se eliminó este mensaje'
                            : 'Este mensaje fue eliminado'
                    }}
                </p>

                <template v-else>
                    <button
                        v-if="quote?.mode === 'jump'"
                        type="button"
                        data-layout-box="quote"
                        class="mb-1 block w-full rounded-lg border-l-[3px] px-2 py-1 text-left text-sm transition-colors duration-(--duration-fast) md:text-xs"
                        :class="[
                            quoteBox,
                            themed('hover:bg-background/80', 'hover:underline'),
                        ]"
                        aria-label="Ir al mensaje citado"
                        @click="quote.id && emit('jump', quote.id)"
                    >
                        <span
                            class="block font-semibold"
                            :class="themed('text-primary')"
                            >{{ quote.author }}</span
                        >
                        <span class="line-clamp-2 wrap-anywhere">{{
                            quote.text
                        }}</span>
                    </button>
                    <!-- A quote of an earlier conversation the viewer may open there. -->
                    <Link
                        v-else-if="quote?.mode === 'link' && quote.url"
                        :href="quote.url"
                        data-layout-box="quote"
                        data-quote-origin="previous_conversation"
                        class="mb-1 block w-full rounded-lg border-l-[3px] px-2 py-1 text-left text-sm transition-colors duration-(--duration-fast) md:text-xs"
                        :class="[
                            quoteBox,
                            themed('hover:bg-background/80', 'hover:underline'),
                        ]"
                        aria-label="Abrir el mensaje citado en su conversación anterior"
                    >
                        <span
                            class="block font-semibold"
                            :class="themed('text-primary')"
                            >{{ quote.author }}</span
                        >
                        <span class="line-clamp-2 wrap-anywhere">{{
                            quote.text
                        }}</span>
                    </Link>
                    <!-- A quote whose original is not stored here: its text only. -->
                    <p
                        v-else-if="quote?.mode === 'static'"
                        data-layout-box="quote"
                        data-quote-origin="preview"
                        class="mb-1 block w-full rounded-lg border-l-[3px] px-2 py-1 text-left text-sm md:text-xs"
                        :class="quoteBox"
                    >
                        <span
                            class="block font-semibold"
                            :class="themed('text-primary')"
                            >{{ quote.author }}</span
                        >
                        <span class="line-clamp-2 wrap-anywhere">{{
                            quote.text
                        }}</span>
                    </p>
                    <!-- An earlier conversation the viewer may not see: nothing of it. -->
                    <p
                        v-else-if="quote"
                        data-layout-box="quote"
                        data-quote-origin="restricted"
                        class="mb-1 block w-full rounded-lg border-l-[3px] px-2 py-1 text-left text-sm italic md:text-xs"
                        :class="quoteBox"
                    >
                        Responde a un mensaje de una conversación anterior
                    </p>

                    <p
                        v-if="message.template"
                        class="mx-1 mb-1 flex w-fit max-w-full items-start gap-1 rounded px-1.5 py-0.5 text-xs"
                        :class="softBox"
                    >
                        <LayoutTemplate
                            class="mt-px size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 wrap-anywhere"
                            >Plantilla «{{ message.template.name }}»<template
                                v-if="message.template.language"
                            >
                                · {{ message.template.language }}</template
                            ></span
                        >
                    </p>

                    <div
                        v-if="attachments.length > 0"
                        class="grid grid-cols-1 gap-1.5"
                        :class="!bare && 'mb-1'"
                    >
                        <template
                            v-for="attachment in attachments"
                            :key="attachment.id"
                        >
                            <MessageAttachmentPreview
                                :attachment="attachment"
                                :channel-label="channelLabel"
                                :sticker="
                                    message.type === 'sticker'
                                        ? { animated: stickerAnimated }
                                        : null
                                "
                                :voice="voice"
                                :seconds="mediaSeconds"
                                :gif="gif"
                                :caption="message.body"
                                :author="author"
                                :at="time"
                            />
                            <div
                                v-if="attachment.delivery_method === 'link'"
                                class="flex flex-wrap items-center gap-1.5"
                            >
                                <p
                                    v-if="attachment.link_revoked_at"
                                    class="flex w-fit max-w-full items-start gap-1 rounded px-1.5 py-0.5 text-xs"
                                    :class="softBox"
                                    title="El cliente ya no puede descargar el archivo con este enlace."
                                >
                                    <Link2Off
                                        class="mt-px size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 wrap-anywhere"
                                        >Enlace revocado el
                                        {{
                                            formatDateTime(
                                                attachment.link_revoked_at,
                                            )
                                        }}</span
                                    >
                                </p>
                                <p
                                    v-else
                                    class="flex w-fit max-w-full items-start gap-1 rounded px-1.5 py-0.5 text-xs"
                                    :class="softBox"
                                    title="El cliente recibió un enlace seguro para descargar el archivo, porque el canal no admite este tipo de archivo."
                                >
                                    <Link2
                                        class="mt-px size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0 wrap-anywhere">{{
                                        linkLabel(attachment.link_expires_at)
                                    }}</span>
                                </p>
                                <button
                                    v-if="canRevokeLink(attachment)"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs underline-offset-2 hover:underline disabled:cursor-not-allowed disabled:opacity-60 pointer-coarse:min-h-10"
                                    :class="linkText"
                                    :disabled="revokingAttachmentId !== null"
                                    :aria-busy="
                                        revokingAttachmentId === attachment.id
                                    "
                                    @click="
                                        emit('revokeLink', message, attachment)
                                    "
                                >
                                    <Spinner
                                        v-if="
                                            revokingAttachmentId ===
                                            attachment.id
                                        "
                                        class="size-3.5"
                                    />
                                    <Link2Off
                                        v-else
                                        class="size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Revocar enlace
                                </button>
                            </div>
                        </template>
                    </div>
                    <p
                        v-else-if="isMedia"
                        class="mb-1 rounded-xl border border-dashed px-3 py-2 text-xs"
                        :class="[
                            secondaryText,
                            customColors && 'border-current/40',
                        ]"
                    >
                        {{ typeLabel(message.type) }}:
                        {{
                            message.direction === 'inbound'
                                ? `el archivo todavía no se ha descargado de ${channelLabel}.`
                                : 'archivo no disponible.'
                        }}
                    </p>

                    <!-- A place: what it is called, where it is and the way there. -->
                    <div
                        v-if="location"
                        class="mb-1 w-72 max-w-full overflow-hidden rounded-xl border"
                        :class="themed('bg-background/60', 'border-current/30')"
                        data-location-card
                    >
                        <div class="flex items-start gap-2.5 p-2.5">
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-full"
                                :class="
                                    themed(
                                        'bg-destructive-soft text-destructive-soft-foreground',
                                        'border border-current/40',
                                    )
                                "
                                aria-hidden="true"
                            >
                                <MapPin class="size-4.5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">
                                    {{
                                        location.name ?? 'Ubicación compartida'
                                    }}
                                </p>
                                <p
                                    v-if="location.address"
                                    class="text-xs"
                                    :class="secondaryText"
                                >
                                    {{ location.address }}
                                </p>
                                <p
                                    v-if="location.coordinates"
                                    class="text-xs tabular-nums"
                                    :class="secondaryText"
                                >
                                    {{ location.coordinates }}
                                </p>
                                <p
                                    v-else
                                    class="text-xs"
                                    :class="secondaryText"
                                >
                                    El mensaje llegó sin coordenadas.
                                </p>
                            </div>
                        </div>
                        <a
                            v-if="location.url"
                            :href="location.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex items-center justify-center gap-1.5 border-t px-3 py-2 text-xs font-semibold transition-colors duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset pointer-coarse:py-3"
                            :class="
                                themed(
                                    'text-primary hover:bg-accent/60',
                                    'border-current/30 hover:underline',
                                )
                            "
                            data-location-link
                        >
                            <ExternalLink class="size-3.5" aria-hidden="true" />
                            Abrir en Google Maps
                        </a>
                    </div>

                    <ul
                        v-if="sharedContacts.length > 0"
                        class="mb-1 grid gap-1.5"
                    >
                        <li
                            v-for="(shared, index) in sharedContacts"
                            :key="index"
                            class="flex items-start gap-2.5 rounded-xl border p-2.5"
                            :class="
                                themed('bg-background/60', 'border-current/30')
                            "
                        >
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-full"
                                :class="
                                    themed(
                                        'bg-muted text-muted-foreground',
                                        'border border-current/40',
                                    )
                                "
                                aria-hidden="true"
                            >
                                <UserRound class="size-4.5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block font-medium">{{
                                    shared.name ?? 'Contacto sin nombre'
                                }}</span>
                                <span
                                    v-for="phone in shared.phones ?? []"
                                    :key="phone"
                                    class="block text-xs tabular-nums"
                                    :class="secondaryText"
                                    >{{
                                        phone.startsWith('+')
                                            ? formatPhone(phone)
                                            : phone
                                    }}</span
                                >
                            </span>
                        </li>
                    </ul>

                    <p v-if="interactive" class="flex items-start gap-1.5 px-1">
                        <MousePointerClick
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            <span class="font-medium">{{
                                interactive.title ?? 'Respuesta interactiva'
                            }}</span>
                            <span
                                v-if="interactive.description"
                                class="block text-xs"
                                :class="secondaryText"
                                >{{ interactive.description }}</span
                            >
                        </span>
                    </p>

                    <div
                        v-if="poll"
                        class="mb-1 w-72 max-w-full px-1"
                        data-poll
                    >
                        <p class="flex items-start gap-1.5 font-medium">
                            <ChartNoAxesColumn
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            {{ poll.question }}
                        </p>
                        <ul
                            v-if="poll.options.length > 0"
                            class="mt-1.5 grid gap-1"
                        >
                            <li
                                v-for="(option, index) in poll.options"
                                :key="index"
                                class="flex items-center justify-between gap-3 rounded-lg border px-2.5 py-1.5 text-xs"
                                :class="
                                    themed(
                                        'bg-background/60',
                                        'border-current/30',
                                    )
                                "
                            >
                                <span class="min-w-0 wrap-anywhere">{{
                                    option.name
                                }}</span>
                                <span
                                    v-if="option.votes !== null"
                                    class="shrink-0 font-semibold tabular-nums"
                                    >{{ option.votes }}</span
                                >
                            </li>
                        </ul>
                        <p class="mt-1 text-xs" :class="secondaryText">
                            Encuesta · se responde desde el teléfono
                        </p>
                    </div>

                    <p v-if="reaction" class="flex items-center gap-2 px-1">
                        <span
                            class="text-2xl leading-none"
                            aria-hidden="true"
                            >{{ reaction.emoji ?? '—' }}</span
                        >
                        <span class="text-xs" :class="secondaryText">
                            {{
                                reaction.emoji
                                    ? 'Reaccionó'
                                    : 'Quitó su reacción'
                            }}
                            <button
                                v-if="reaction.target"
                                type="button"
                                class="underline underline-offset-2"
                                :class="linkText"
                                @click="emit('jump', reaction.target)"
                            >
                                a un mensaje
                            </button>
                            <template v-else>a un mensaje</template>
                        </span>
                    </p>

                    <WhatsAppText
                        v-if="text"
                        :text="text"
                        links
                        :inherit-color="customColors"
                        class="block px-1 wrap-anywhere whitespace-pre-line"
                    />
                    <p
                        v-else-if="fallback"
                        class="flex items-start gap-1.5 px-1 italic"
                        :class="secondaryText"
                        data-message-fallback
                    >
                        <MessageSquareDashed
                            class="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="min-w-0">{{ fallback }}</span>
                    </p>

                    <p
                        v-if="outbound && message.delivery_warning"
                        class="mt-1 flex items-start gap-1 px-1 text-xs"
                        :class="themed('text-warning')"
                        role="note"
                    >
                        <TriangleAlert
                            class="mt-px size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 wrap-anywhere">{{
                            message.delivery_warning
                        }}</span>
                    </p>
                </template>

                <div
                    class="flex items-center justify-end gap-1 text-[0.6875rem] leading-4 tabular-nums"
                    :class="[
                        secondaryText,
                        bare
                            ? 'mt-1 w-fit rounded-full border bg-background/85 px-2 py-0.5'
                            : 'mt-0.5 px-1',
                        bare && (outbound ? 'ml-auto' : ''),
                    ]"
                >
                    <span
                        v-if="edited"
                        data-message-edited
                        :title="`Editado el ${formatDateTime(message.edited_at)}`"
                        >Editado</span
                    >
                    <span v-if="edited" aria-hidden="true">·</span>
                    <time
                        :datetime="time ?? undefined"
                        :title="formatDateTime(time)"
                    >
                        {{ formatTime(time) }}
                    </time>
                    <span
                        v-if="importedLabel"
                        class="min-w-0 truncate italic"
                        :title="importedLabel"
                        data-imported
                        >{{ importedLabel }}</span
                    >
                    <MessageStatusIndicator
                        v-else-if="outbound && !revoked"
                        :status="message.status"
                        :error-code="message.error_code"
                        show-progress-label
                        :inherit-color="customColors"
                    />
                </div>

                <div
                    v-if="failed"
                    class="mx-1 mt-1.5 mb-1 flex flex-wrap items-center gap-2 border-t pt-1.5 text-xs"
                    :class="
                        customColors
                            ? 'border-current/30'
                            : unknownDelivery
                              ? 'border-warning/30 text-warning'
                              : 'border-destructive/20 text-destructive'
                    "
                    role="status"
                >
                    <CircleQuestionMark
                        v-if="unknownDelivery"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <CircleAlert
                        v-else
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 flex-1">
                        <strong v-if="unknownDelivery" class="font-semibold"
                            >{{ deliveryUnknownLabel }}:
                        </strong>
                        {{
                            message.error_message ??
                            (unknownDelivery
                                ? `${channelLabel} no confirmó la entrega; el cliente pudo haberlo recibido.`
                                : 'El mensaje no se pudo enviar.')
                        }}
                    </span>
                    <Button
                        v-if="canRetry"
                        size="sm"
                        variant="outline"
                        class="pointer-coarse:h-10"
                        :disabled="retrying"
                        @click="emit('retry', message)"
                    >
                        <Spinner v-if="retrying" />
                        <RotateCw v-else aria-hidden="true" />
                        Reintentar
                    </Button>
                </div>
            </div>

            <!-- Reactions left on the message. -->
            <ul
                v-if="reactionChips.length > 0 && !revoked"
                class="relative z-[1] -mt-1.5 flex flex-wrap gap-1 px-2"
                :class="outbound ? 'justify-end' : 'justify-start'"
                aria-label="Reacciones"
                data-message-reactions
            >
                <li
                    v-for="chip in reactionChips"
                    :key="chip.emoji"
                    class="inline-flex items-center gap-1 rounded-full border bg-background px-1.5 py-0.5 text-xs leading-none shadow-(--shadow-control)"
                    :class="chip.mine && 'border-primary/50 bg-primary-soft'"
                    :title="
                        chip.mine
                            ? 'Reacción enviada desde este número'
                            : `Reacción de ${contactName}`
                    "
                >
                    <span class="text-sm leading-none">{{ chip.emoji }}</span>
                    <span
                        v-if="chip.count > 1"
                        class="font-medium text-muted-foreground tabular-nums"
                        >{{ chip.count }}</span
                    >
                </li>
            </ul>

            <!-- Quick actions, beside the bubble. -->
            <div
                v-if="!revoked && (canQuote || copyable)"
                class="absolute top-0 flex gap-1 opacity-0 transition-opacity duration-(--duration-fast) group-hover:opacity-100 focus-within:opacity-100 pointer-coarse:opacity-100"
                :class="
                    outbound
                        ? 'right-full mr-1.5 flex-row-reverse'
                        : 'left-full ml-1.5'
                "
                data-message-actions
            >
                <button
                    v-if="canQuote"
                    type="button"
                    :class="toolButton"
                    title="Responder citando este mensaje"
                    @click="emit('quote', message)"
                >
                    <Reply class="size-3.5" aria-hidden="true" />
                    <span class="sr-only">Responder citando este mensaje</span>
                </button>
                <button
                    v-if="copyable"
                    type="button"
                    :class="toolButton"
                    class="pointer-coarse:hidden"
                    title="Copiar el texto"
                    @click="copyText"
                >
                    <Copy class="size-3.5" aria-hidden="true" />
                    <span class="sr-only">Copiar el texto del mensaje</span>
                </button>
            </div>
        </div>
    </div>
</template>
