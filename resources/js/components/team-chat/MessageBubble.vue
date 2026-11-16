<script setup lang="ts">
import {
    Ban,
    Check,
    CheckCheck,
    Copy,
    CornerUpLeft,
    MoreHorizontal,
    Pencil,
    SmilePlus,
    Trash2,
} from '@lucide/vue';
import { computed, inject, nextTick, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    BUBBLE_HOVER,
    BUBBLE_META,
    BUBBLE_TOMBSTONE,
    QUOTE_BASE,
    REACTIONS_ROW,
    ROW_ACTIONS,
    ROW_ACTION_BUTTON,
    bubbleShape,
    bubbleTone,
    quoteClasses,
    reactionChip,
} from '@/components/chat/bubble';
import MessageAttachments from '@/components/team-chat/MessageAttachments.vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import {
    clockTime,
    isEmojiOnly,
    personTint,
    textSegments,
} from '@/components/team-chat/teamChatFormat';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Textarea } from '@/components/ui/textarea';
import {
    CUSTOM_BUBBLE_CLASSES,
    CUSTOM_CHAT_COLORS,
} from '@/lib/chatAppearance';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TeamMessage } from '@/types/team-chat';

/**
 * One message of an internal thread. Text is rendered as plain text (line
 * breaks kept, never HTML): mentions of current participants are only
 * emphasized and web addresses become links that open in a new tab. Files
 * are shown in place (MessageAttachments) and open through the authorized
 * route. Reactions show who reacted and toggle the viewer's own one.
 *
 * Consecutive messages of the same person read as one block: only the
 * first one names its author (in groups) and only the last one carries the
 * avatar and the tail corner.
 *
 * The «Reaccionar», «Responder» and actions buttons appear on hover only
 * with a mouse (fine pointer) on wide screens; they also appear when the
 * row has focus (keyboard) or their menu is open, and are always visible on
 * touch screens (phones and tablets), which have no hover.
 */
const props = defineProps<{
    message: TeamMessage;
    /** Name the author above the bubble (first message of a block, in groups). */
    showAuthor: boolean;
    /** First / last message of a block of the same person. */
    first: boolean;
    last: boolean;
    /** Group thread: other people's messages keep a column for the avatar. */
    withAvatar: boolean;
    highlighted: boolean;
    /** The other person of a private chat read up to this message (the latest own one). */
    seen: boolean;
    /** Private chat, own message: whether the other person already read it. */
    receipt: 'sent' | 'read' | null;
    canReply: boolean;
    /** Allowed reactions; empty when the viewer cannot react. */
    reactions: string[];
    saving: boolean;
    /** Why the last edit of this message was refused, if it was. */
    editError: string | null;
}>();

const emit = defineEmits<{
    react: [message: TeamMessage, emoji: string];
    reply: [message: TeamMessage];
    edit: [message: TeamMessage, body: string];
    delete: [message: TeamMessage];
    jump: [messageId: number];
    view: [attachmentId: number];
}>();

/*
 * The viewer's own chat colors (CSS variables of the chat), when set. Only
 * the chosen text color is guaranteed (≥4.5:1) on the bubble, so text
 * inside it uses that color at full strength (currentColor): theme tones,
 * opacity and translucent fills stay for the theme's own bubbles. A
 * deleted message keeps the theme's muted look.
 */
const customColors = inject(
    CUSTOM_CHAT_COLORS,
    computed(() => false),
);
const custom = computed(() => customColors.value && !props.message.deleted);

const editing = ref(false);
const reactionsOpen = ref(false);
const draft = ref('');
const editor = ref<InstanceType<typeof Textarea> | null>(null);

const time = computed(() => clockTime(props.message.created_at));
const fullDate = computed(() => formatDateTime(props.message.created_at));
const hasText = computed(
    () => !props.message.deleted && Boolean(props.message.body),
);
const hasActions = computed(
    () =>
        !props.message.deleted &&
        (props.canReply ||
            props.message.can.edit ||
            props.message.can.delete ||
            hasText.value),
);
const canReact = computed(
    () => !props.message.deleted && props.reactions.length > 0,
);
const segments = computed(() =>
    textSegments(props.message.body, props.message.mentions),
);
const bigEmoji = computed(
    () =>
        isEmojiOnly(props.message.body) &&
        props.message.attachments.length === 0 &&
        !props.message.reply_to,
);
const authorTint = computed(() =>
    custom.value
        ? ''
        : props.message.author?.deleted
          ? 'text-muted-foreground'
          : personTint(props.message.author?.name).name,
);

function react(emoji: string): void {
    reactionsOpen.value = false;
    emit('react', props.message, emoji);
}

async function copyText(): Promise<void> {
    const text = props.message.body ?? '';

    try {
        await navigator.clipboard.writeText(text);
        toast.success('Texto copiado');
    } catch {
        // Without clipboard permission (or over plain HTTP): the old way.
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        const copied = document.execCommand('copy');
        area.remove();

        if (copied) {
            toast.success('Texto copiado');
        } else {
            toast.error('No se pudo copiar el texto.');
        }
    }
}

async function startEditing(): Promise<void> {
    draft.value = props.message.body ?? '';
    editing.value = true;
    await nextTick();

    const element = editor.value?.$el as HTMLTextAreaElement | undefined;
    element?.focus();
    element?.setSelectionRange(draft.value.length, draft.value.length);
}

function saveEdit(): void {
    if (props.saving) {
        return;
    }

    // Nothing changed: there is nothing to save or to mark as edited.
    if (draft.value.trim() === (props.message.body ?? '').trim()) {
        editing.value = false;

        return;
    }

    emit('edit', props.message, draft.value);
}

/* The editor stays open (with the text) until the server accepts the edit. */
watch(
    () => props.saving,
    (saving, wasSaving) => {
        if (wasSaving && !saving && !props.editError) {
            editing.value = false;
        }
    },
);

function onEditKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        editing.value = false;
    } else if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        saveEdit();
    }
}
</script>

<template>
    <div
        v-if="message.kind === 'system'"
        :id="`team-message-${message.id}`"
        class="flex justify-center px-4 py-1.5"
    >
        <p
            class="max-w-md rounded-full glass px-3 py-1 text-center text-xs text-pretty text-muted-foreground shadow-(--shadow-control) ring-1 ring-border/70"
            :title="fullDate"
        >
            {{ message.system_text }}
        </p>
    </div>
    <div
        v-else
        :id="`team-message-${message.id}`"
        :class="
            cn(
                'chat-row group flex items-end gap-2',
                message.is_mine ? 'justify-end' : 'justify-start',
                first ? 'pt-3' : 'pt-0.5',
            )
        "
        :data-mine="message.is_mine || undefined"
    >
        <span v-if="withAvatar && !message.is_mine" class="w-7 shrink-0">
            <TeamAvatar
                v-if="last"
                :name="message.author?.name ?? ''"
                :deleted="message.author?.deleted ?? false"
                size="xs"
            />
        </span>
        <div
            :class="
                cn(
                    'flex max-w-[94%] min-w-0 items-center gap-0.5 sm:max-w-[min(72%,44rem)]',
                    message.is_mine && 'flex-row-reverse',
                )
            "
        >
            <div
                :class="
                    cn(
                        'flex min-w-0 flex-col',
                        message.is_mine ? 'items-end' : 'items-start',
                    )
                "
            >
                <div
                    :class="
                        cn(
                            'max-w-full min-w-0 p-1 text-sm',
                            BUBBLE_HOVER,
                            bubbleShape({
                                side: message.is_mine ? 'out' : 'in',
                                joinedAbove: !first,
                                joinedBelow: !last,
                            }),
                            message.deleted
                                ? BUBBLE_TOMBSTONE
                                : custom
                                  ? message.is_mine
                                      ? CUSTOM_BUBBLE_CLASSES.sent
                                      : [
                                            'border border-border/80',
                                            CUSTOM_BUBBLE_CLASSES.received,
                                        ]
                                  : bubbleTone(message.is_mine ? 'out' : 'in'),
                            editing && 'w-[min(32rem,100%)]',
                            highlighted &&
                                'ring-2 ring-warning ring-offset-2 ring-offset-background',
                        )
                    "
                    data-team-bubble
                >
                    <p
                        v-if="showAuthor && !message.is_mine"
                        :class="
                            cn(
                                'truncate px-2.5 pt-1 text-xs font-semibold',
                                authorTint,
                            )
                        "
                    >
                        {{ message.author?.name }}
                    </p>

                    <button
                        v-if="message.reply_to && !message.deleted"
                        type="button"
                        :class="
                            cn(
                                QUOTE_BASE,
                                'mt-0.5',
                                quoteClasses(custom),
                                custom && 'hover:underline',
                            )
                        "
                        title="Ir al mensaje citado"
                        @click="emit('jump', message.reply_to.id)"
                    >
                        <span
                            class="block truncate font-semibold"
                            :class="!custom && 'text-primary'"
                        >
                            {{ message.reply_to.author }}
                        </span>
                        <span
                            class="line-clamp-2"
                            :class="[
                                !custom && 'opacity-85',
                                message.reply_to.deleted && 'italic',
                            ]"
                        >
                            {{ message.reply_to.excerpt }}
                        </span>
                    </button>

                    <p
                        v-if="message.deleted"
                        class="flex items-center gap-1.5 px-2.5 py-1 italic"
                    >
                        <Ban class="size-3.5 shrink-0" aria-hidden="true" />
                        Mensaje eliminado
                    </p>
                    <template v-else>
                        <div v-if="editing" class="grid gap-2 p-1.5">
                            <Textarea
                                ref="editor"
                                v-model="draft"
                                rows="3"
                                class="bg-background text-foreground"
                                aria-label="Editar mensaje"
                                :aria-invalid="Boolean(editError)"
                                :disabled="saving"
                                @keydown="onEditKeydown"
                            />
                            <p
                                v-if="editError"
                                class="rounded-md bg-background px-2 py-1 text-xs text-destructive"
                                role="alert"
                            >
                                {{ editError }}
                            </p>
                            <div class="flex items-center justify-end gap-2">
                                <span
                                    class="mr-auto hidden text-[0.7rem] sm:inline"
                                    :class="!custom && 'opacity-75'"
                                >
                                    Enter guarda · Esc cancela
                                </span>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    :disabled="saving"
                                    @click="editing = false"
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    size="sm"
                                    :loading="saving"
                                    @click="saveEdit"
                                >
                                    Guardar
                                </Button>
                            </div>
                        </div>
                        <p
                            v-else-if="message.body"
                            :class="
                                cn(
                                    'px-2.5 pt-1 pb-0.5 [overflow-wrap:anywhere] whitespace-pre-wrap',
                                    bigEmoji && 'text-4xl leading-tight',
                                )
                            "
                        >
                            <template
                                v-for="(segment, index) in segments"
                                :key="index"
                            >
                                <span
                                    v-if="segment.kind === 'mention'"
                                    :class="
                                        cn(
                                            'rounded px-0.5 font-semibold',
                                            custom
                                                ? 'underline underline-offset-2'
                                                : message.is_mine
                                                  ? 'bg-primary/15'
                                                  : 'bg-primary-soft text-primary-soft-foreground',
                                        )
                                    "
                                    >{{ segment.text }}</span
                                ><a
                                    v-else-if="segment.kind === 'link'"
                                    :href="segment.href"
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    :class="
                                        cn(
                                            'font-medium underline decoration-current/50 underline-offset-2 outline-none hover:decoration-current focus-visible:ring-2 focus-visible:ring-current',
                                            !message.is_mine &&
                                                !custom &&
                                                'text-primary',
                                        )
                                    "
                                    >{{ segment.text }}</a
                                ><template v-else>{{ segment.text }}</template>
                            </template>
                        </p>

                        <MessageAttachments
                            v-if="message.attachments.length > 0 && !editing"
                            :attachments="message.attachments"
                            :mine="message.is_mine"
                            :custom="custom"
                            :class="message.body && 'mt-1'"
                            @view="(id) => emit('view', id)"
                        />
                    </template>

                    <p
                        v-if="!editing"
                        :class="
                            cn(
                                BUBBLE_META,
                                'px-2 pb-0.5',
                                !custom && 'text-muted-foreground',
                            )
                        "
                        :title="fullDate"
                    >
                        <span v-if="message.edited && !message.deleted"
                            >Editado ·</span
                        >
                        <time :datetime="message.created_at ?? undefined">{{
                            time
                        }}</time>
                        <template v-if="receipt && !message.deleted">
                            <CheckCheck
                                v-if="receipt === 'read'"
                                class="size-3.5"
                                :class="custom ? 'stroke-3' : 'chat-tick-read'"
                                aria-hidden="true"
                            />
                            <Check v-else class="size-3.5" aria-hidden="true" />
                            <span class="sr-only">{{
                                receipt === 'read' ? 'Leído' : 'Enviado'
                            }}</span>
                        </template>
                        <span v-if="seen" class="font-medium">· Visto</span>
                    </p>
                </div>

                <ul
                    v-if="message.reactions.length > 0"
                    :class="cn(REACTIONS_ROW, message.is_mine && 'justify-end')"
                    aria-label="Reacciones"
                >
                    <li
                        v-for="reaction in message.reactions"
                        :key="reaction.emoji"
                    >
                        <button
                            type="button"
                            :class="
                                cn(
                                    reactionChip(reaction.mine),
                                    'active:not-disabled:scale-95 pointer-coarse:h-8 pointer-coarse:px-2',
                                    canReact &&
                                        reactions.includes(reaction.emoji)
                                        ? 'hover:border-primary/60 hover:bg-accent'
                                        : 'cursor-default',
                                )
                            "
                            :title="reaction.names.join(', ')"
                            :aria-label="`${reaction.emoji} ${reaction.count}: ${reaction.names.join(', ')}`"
                            :aria-pressed="reaction.mine"
                            :disabled="
                                !canReact || !reactions.includes(reaction.emoji)
                            "
                            @click="react(reaction.emoji)"
                        >
                            <span aria-hidden="true">{{ reaction.emoji }}</span>
                            <span class="font-semibold tabular-nums">{{
                                reaction.count
                            }}</span>
                        </button>
                    </li>
                </ul>
            </div>

            <!--
                The controls next to the bubble sit on the chat background:
                with personal colors (unknown contrast with theme tones) they
                get the theme's own background, so the icons keep ≥ 3:1
                (WCAG 1.4.11).
            -->
            <div
                v-if="!editing && (canReact || hasActions)"
                :class="
                    cn(
                        ROW_ACTIONS,
                        'pointer-coarse:flex-col',
                        message.is_mine
                            ? 'origin-right flex-row-reverse'
                            : 'origin-left',
                    )
                "
                data-message-actions
            >
                <Popover v-if="canReact" v-model:open="reactionsOpen">
                    <PopoverTrigger as-child>
                        <button
                            type="button"
                            :class="ROW_ACTION_BUTTON"
                            aria-label="Reaccionar"
                            title="Reaccionar"
                        >
                            <SmilePlus class="size-4" aria-hidden="true" />
                        </button>
                    </PopoverTrigger>
                    <PopoverContent
                        class="w-auto rounded-full p-1"
                        side="top"
                        :align="message.is_mine ? 'end' : 'start'"
                    >
                        <div
                            class="flex gap-0.5"
                            role="group"
                            aria-label="Reacciones"
                        >
                            <button
                                v-for="(emoji, index) in reactions"
                                :key="emoji"
                                type="button"
                                class="grid size-9 place-items-center rounded-full text-xl transition-[scale,background-color] duration-(--duration-fast) ease-(--ease-spring) outline-none hover:scale-125 hover:bg-accent focus-visible:bg-accent motion-safe:animate-in motion-safe:zoom-in-50 motion-safe:fade-in pointer-coarse:size-10"
                                :class="
                                    message.reactions.some(
                                        (reaction) =>
                                            reaction.emoji === emoji &&
                                            reaction.mine,
                                    ) && 'bg-primary-soft'
                                "
                                :style="{ animationDelay: `${index * 25}ms` }"
                                :aria-label="`Reaccionar con ${emoji}`"
                                @click="react(emoji)"
                            >
                                {{ emoji }}
                            </button>
                        </div>
                    </PopoverContent>
                </Popover>
                <button
                    v-if="canReply && !message.deleted"
                    type="button"
                    :class="[ROW_ACTION_BUTTON, 'pointer-coarse:hidden']"
                    aria-label="Responder"
                    title="Responder"
                    @click="emit('reply', message)"
                >
                    <CornerUpLeft class="size-4" aria-hidden="true" />
                </button>
                <DropdownMenu v-if="hasActions">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            :class="ROW_ACTION_BUTTON"
                            aria-label="Acciones del mensaje"
                            title="Acciones del mensaje"
                        >
                            <MoreHorizontal class="size-4" aria-hidden="true" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        :align="message.is_mine ? 'end' : 'start'"
                    >
                        <DropdownMenuItem
                            v-if="canReply"
                            @select="emit('reply', message)"
                        >
                            <CornerUpLeft /> Responder
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="hasText" @select="copyText">
                            <Copy /> Copiar texto
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="message.can.edit"
                            @select="startEditing"
                        >
                            <Pencil /> Editar
                        </DropdownMenuItem>
                        <template v-if="message.can.delete">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="text-destructive focus:text-destructive"
                                @select="emit('delete', message)"
                            >
                                <Trash2 /> Eliminar
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </div>
</template>
