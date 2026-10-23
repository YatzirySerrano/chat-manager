<script setup lang="ts">
import {
    Bot,
    CircleAlert,
    CircleQuestionMark,
    FlaskConical,
    LayoutTemplate,
    MapPin,
    MousePointerClick,
    Reply,
    RotateCw,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
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
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import type { ConversationMessage } from '@/types';

/**
 * One customer message or reply. Inbound messages sit on the left and
 * replies on the right; structured content (location, shared contacts,
 * interactive answers, reactions, templates) has its own renderer, and a
 * failed reply shows why and, when allowed, a retry button.
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
    }>(),
    { canRetry: false, retrying: false, canQuote: false },
);

const emit = defineEmits<{
    retry: [message: ConversationMessage];
    quote: [message: ConversationMessage];
    jump: [messageId: number];
}>();

const outbound = computed(() => props.message.direction === 'outbound');
const failed = computed(() => props.message.status === 'failed');
/* Meta may have received it: a warning, not a definitive failure. */
const unknownDelivery = computed(
    () => failed.value && props.message.error_code === DELIVERY_UNKNOWN,
);

const content = computed(
    () => props.message.content as Record<string, unknown> | null,
);

const location = computed(() => {
    if (props.message.type !== 'location' || !content.value) {
        return null;
    }

    const latitude = content.value.latitude as number | null;
    const longitude = content.value.longitude as number | null;

    return {
        name: (content.value.name as string | null) ?? null,
        address: (content.value.address as string | null) ?? null,
        url:
            latitude !== null && longitude !== null
                ? `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=17/${latitude}/${longitude}`
                : null,
        coordinates:
            latitude !== null && longitude !== null
                ? `${latitude.toFixed(5)}, ${longitude.toFixed(5)}`
                : null,
    };
});

const sharedContacts = computed(() =>
    props.message.type === 'contacts' && content.value
        ? ((content.value.contacts as {
              name: string | null;
              phones: string[];
          }[]) ?? [])
        : [],
);

const interactive = computed(() =>
    props.message.type === 'interactive' && content.value
        ? {
              title: (content.value.title as string | null) ?? null,
              description: (content.value.description as string | null) ?? null,
          }
        : null,
);

const reaction = computed(() =>
    props.message.type === 'reaction'
        ? {
              emoji:
                  (content.value?.emoji as string | null | undefined) ??
                  props.message.body,
              target:
                  (content.value?.reacted_message_id as number | null) ?? null,
          }
        : null,
);

const isMedia = computed(() =>
    ['image', 'audio', 'video', 'document', 'sticker'].includes(
        props.message.type,
    ),
);

/** Body shown as text (captions for media, the text itself otherwise). */
const text = computed(() => {
    if (reaction.value || location.value || interactive.value) {
        return null;
    }

    return props.message.body;
});

const author = computed(() => {
    if (!outbound.value) {
        return props.contactName;
    }

    if (props.message.automatic) {
        return 'Mensaje automático';
    }

    return props.message.sender?.name ?? 'Equipo';
});

const time = computed(() => props.message.sent_at ?? props.message.created_at);
</script>

<template>
    <div
        class="group flex w-full"
        :class="outbound ? 'justify-end' : 'justify-start'"
    >
        <div
            class="relative max-w-[85%] min-w-0 rounded-2xl px-3 py-2 text-[0.9375rem]/snug shadow-xs sm:max-w-[70%] md:text-sm"
            :class="[
                outbound
                    ? 'rounded-br-md bg-primary-soft text-primary-soft-foreground'
                    : 'rounded-bl-md border bg-card text-card-foreground',
                failed &&
                    (unknownDelivery
                        ? 'ring-1 ring-warning/60'
                        : 'ring-1 ring-destructive/60'),
            ]"
            :data-message-id="message.id"
        >
            <p
                class="mb-0.5 flex flex-wrap items-center gap-1.5 text-xs font-medium text-muted-foreground"
            >
                <Bot
                    v-if="message.automatic"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span class="min-w-0 truncate" :title="author">{{
                    author
                }}</span>
                <span
                    v-if="message.simulated"
                    class="inline-flex items-center gap-1 rounded bg-warning/15 px-1.5 py-px text-xs font-semibold text-warning"
                    title="Enviado por el transporte simulado de desarrollo: no llegó a WhatsApp."
                >
                    <FlaskConical class="size-3.5" aria-hidden="true" />
                    Simulación
                </span>
            </p>

            <button
                v-if="message.reply_to"
                type="button"
                class="mb-1.5 block w-full rounded-md border-l-2 border-primary/60 bg-background/50 px-2 py-1 text-left text-sm text-muted-foreground hover:bg-background/80 md:text-xs"
                :aria-label="`Ir al mensaje citado`"
                @click="emit('jump', message.reply_to.id)"
            >
                <span class="block font-medium text-foreground/80">
                    {{
                        message.reply_to.direction === 'inbound'
                            ? contactName
                            : 'Equipo'
                    }}
                </span>
                <span class="line-clamp-2">{{
                    message.reply_to.body ?? typeLabel(message.reply_to.type)
                }}</span>
            </button>

            <p
                v-if="message.template"
                class="mb-1 flex w-fit max-w-full items-start gap-1 rounded bg-background/60 px-1.5 py-0.5 text-xs text-muted-foreground"
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
                v-if="message.attachments.length > 0"
                class="mb-1 grid gap-1.5"
            >
                <MessageAttachmentPreview
                    v-for="attachment in message.attachments"
                    :key="attachment.id"
                    :attachment="attachment"
                />
            </div>
            <p
                v-else-if="isMedia"
                class="mb-1 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
            >
                {{ typeLabel(message.type) }}:
                {{
                    message.direction === 'inbound'
                        ? 'el archivo todavía no se ha descargado de WhatsApp.'
                        : 'archivo no disponible.'
                }}
            </p>

            <div v-if="location" class="grid gap-0.5">
                <p class="flex items-center gap-1.5 font-medium">
                    <MapPin class="size-4 shrink-0" aria-hidden="true" />
                    {{ location.name ?? 'Ubicación compartida' }}
                </p>
                <p v-if="location.address" class="text-xs">
                    {{ location.address }}
                </p>
                <a
                    v-if="location.url"
                    :href="location.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-xs text-primary underline underline-offset-2"
                    >Ver en el mapa ({{ location.coordinates }})</a
                >
            </div>

            <ul v-if="sharedContacts.length > 0" class="grid gap-1.5">
                <li
                    v-for="(shared, index) in sharedContacts"
                    :key="index"
                    class="flex items-start gap-2 rounded-lg bg-background/60 px-2 py-1.5"
                >
                    <UserRound
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span class="min-w-0">
                        <span class="block font-medium">{{
                            shared.name ?? 'Contacto sin nombre'
                        }}</span>
                        <span
                            v-for="phone in shared.phones"
                            :key="phone"
                            class="block text-xs text-muted-foreground"
                            >{{
                                phone.startsWith('+')
                                    ? formatPhone(phone)
                                    : phone
                            }}</span
                        >
                    </span>
                </li>
            </ul>

            <p v-if="interactive" class="flex items-start gap-1.5">
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
                        class="block text-xs text-muted-foreground"
                        >{{ interactive.description }}</span
                    >
                </span>
            </p>

            <p v-if="reaction" class="flex items-center gap-2">
                <span class="text-2xl leading-none" aria-hidden="true">{{
                    reaction.emoji ?? '—'
                }}</span>
                <span class="text-xs text-muted-foreground">
                    {{ reaction.emoji ? 'Reaccionó' : 'Quitó su reacción' }}
                    <button
                        v-if="reaction.target"
                        type="button"
                        class="text-primary underline underline-offset-2"
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
                class="wrap-anywhere whitespace-pre-line"
            />
            <p
                v-else-if="
                    !isMedia &&
                    !location &&
                    !interactive &&
                    !reaction &&
                    sharedContacts.length === 0
                "
                class="text-muted-foreground italic"
            >
                {{
                    message.type === 'unsupported'
                        ? 'WhatsApp no permite mostrar este tipo de mensaje aquí.'
                        : `${typeLabel(message.type)} sin texto`
                }}
            </p>

            <div
                class="mt-1 flex items-center justify-end gap-1.5 text-xs text-muted-foreground tabular-nums"
            >
                <time
                    :datetime="time ?? undefined"
                    :title="formatDateTime(time)"
                >
                    {{ formatTime(time) }}
                </time>
                <MessageStatusIndicator
                    v-if="outbound"
                    :status="message.status"
                    :error-code="message.error_code"
                    show-progress-label
                />
            </div>

            <div
                v-if="failed"
                class="mt-1.5 flex flex-wrap items-center gap-2 border-t pt-1.5 text-xs"
                :class="
                    unknownDelivery
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
                            ? 'WhatsApp no confirmó la entrega; el cliente pudo haberlo recibido.'
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

            <button
                v-if="canQuote"
                type="button"
                class="absolute top-0 flex size-8 items-center justify-center rounded-full opacity-0 transition-opacity outline-none group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10 pointer-coarse:opacity-100"
                :class="
                    outbound
                        ? '-left-9 pointer-coarse:-left-11'
                        : '-right-9 pointer-coarse:-right-11'
                "
                title="Responder citando este mensaje"
                @click="emit('quote', message)"
            >
                <span
                    class="flex size-7 items-center justify-center rounded-full border bg-background text-muted-foreground hover:text-foreground pointer-coarse:size-8"
                >
                    <Reply class="size-3.5" aria-hidden="true" />
                </span>
                <span class="sr-only">Responder citando este mensaje</span>
            </button>
        </div>
    </div>
</template>
