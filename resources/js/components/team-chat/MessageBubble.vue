<script setup lang="ts">
import {
    CornerUpLeft,
    Download,
    FileText,
    MoreHorizontal,
    Pencil,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TeamAttachment, TeamMessage } from '@/types/team-chat';

/**
 * One message of an internal thread. Text is rendered as plain text (line
 * breaks kept, never HTML). Files open through the authorized route.
 */
const props = defineProps<{
    message: TeamMessage;
    showAuthor: boolean;
    highlighted: boolean;
    seen: boolean;
    canReply: boolean;
    saving: boolean;
}>();

const emit = defineEmits<{
    reply: [message: TeamMessage];
    edit: [message: TeamMessage, body: string];
    delete: [message: TeamMessage];
    jump: [messageId: number];
}>();

const editing = ref(false);
const draft = ref('');
const editor = ref<InstanceType<typeof Textarea> | null>(null);

const time = computed(() =>
    formatDateTime(props.message.created_at, { timeStyle: 'short' }),
);
const fullDate = computed(() => formatDateTime(props.message.created_at));
const hasActions = computed(
    () =>
        !props.message.deleted &&
        (props.canReply || props.message.can.edit || props.message.can.delete),
);
const images = computed(() =>
    props.message.attachments.filter(
        (attachment) => attachment.kind === 'image' && attachment.preview_url,
    ),
);
const files = computed(() =>
    props.message.attachments.filter(
        (attachment) =>
            !(attachment.kind === 'image' && attachment.preview_url),
    ),
);

function size(attachment: TeamAttachment): string {
    const kilobytes = attachment.size_bytes / 1024;

    return kilobytes >= 1024
        ? `${(kilobytes / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(kilobytes))} KB`;
}

async function startEditing(): Promise<void> {
    draft.value = props.message.body ?? '';
    editing.value = true;
    await nextTick();
    (editor.value?.$el as HTMLTextAreaElement | undefined)?.focus();
}

function saveEdit(): void {
    emit('edit', props.message, draft.value);
    editing.value = false;
}

function onEditKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
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
        class="flex justify-center px-4 py-1"
    >
        <p
            class="max-w-md rounded-full bg-muted px-3 py-1 text-center text-xs text-muted-foreground"
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
                'group flex px-3 sm:px-4',
                message.is_mine ? 'justify-end' : 'justify-start',
                showAuthor ? 'pt-2' : 'pt-0.5',
            )
        "
    >
        <div
            :class="
                cn(
                    'flex max-w-[85%] items-start gap-1 sm:max-w-[70%]',
                    message.is_mine && 'flex-row-reverse',
                )
            "
        >
            <div
                :class="
                    cn(
                        'min-w-0 rounded-2xl px-3 py-2 text-sm shadow-xs transition-colors',
                        message.is_mine
                            ? 'rounded-br-md bg-primary text-primary-foreground'
                            : 'rounded-bl-md border bg-card text-card-foreground',
                        message.deleted &&
                            'bg-muted text-muted-foreground italic shadow-none',
                        highlighted &&
                            'ring-2 ring-warning ring-offset-2 ring-offset-background',
                    )
                "
            >
                <p
                    v-if="showAuthor && !message.is_mine"
                    :class="
                        cn(
                            'mb-0.5 text-xs font-semibold',
                            message.author?.deleted
                                ? 'text-muted-foreground'
                                : 'text-primary',
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
                            'mb-1.5 block w-full rounded-lg border-l-4 px-2 py-1 text-left text-xs',
                            message.is_mine
                                ? 'border-primary-foreground/60 bg-primary-foreground/10'
                                : 'border-primary/60 bg-muted',
                        )
                    "
                    @click="emit('jump', message.reply_to.id)"
                >
                    <span class="block font-semibold">
                        {{ message.reply_to.author }}
                    </span>
                    <span class="line-clamp-2 opacity-90">
                        {{ message.reply_to.excerpt }}
                    </span>
                </button>

                <p v-if="message.deleted">Mensaje eliminado</p>
                <template v-else>
                    <div v-if="editing" class="grid min-w-56 gap-2">
                        <Textarea
                            ref="editor"
                            v-model="draft"
                            rows="3"
                            class="bg-background text-foreground"
                            aria-label="Editar mensaje"
                            @keydown="onEditKeydown"
                        />
                        <div class="flex justify-end gap-2">
                            <Button
                                size="sm"
                                variant="secondary"
                                @click="editing = false"
                            >
                                Cancelar
                            </Button>
                            <Button
                                size="sm"
                                :disabled="saving"
                                @click="saveEdit"
                            >
                                Guardar
                            </Button>
                        </div>
                    </div>
                    <p
                        v-else-if="message.body"
                        class="break-words whitespace-pre-wrap"
                    >
                        {{ message.body }}
                    </p>

                    <div
                        v-if="images.length > 0"
                        class="mt-1.5 grid gap-1.5"
                        :class="images.length > 1 ? 'grid-cols-2' : ''"
                    >
                        <a
                            v-for="image in images"
                            :key="image.id"
                            :href="image.preview_url ?? image.download_url"
                            target="_blank"
                            rel="noopener"
                            class="block overflow-hidden rounded-lg"
                            :title="image.name"
                        >
                            <img
                                :src="image.preview_url ?? ''"
                                :alt="image.name"
                                loading="lazy"
                                class="max-h-60 w-full object-cover"
                            />
                        </a>
                    </div>
                    <ul v-if="files.length > 0" class="mt-1.5 grid gap-1.5">
                        <li v-for="file in files" :key="file.id">
                            <a
                                :href="file.preview_url ?? file.download_url"
                                :target="
                                    file.preview_url ? '_blank' : undefined
                                "
                                rel="noopener"
                                :class="
                                    cn(
                                        'flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs',
                                        message.is_mine
                                            ? 'bg-primary-foreground/15 hover:bg-primary-foreground/25'
                                            : 'bg-muted hover:bg-accent',
                                    )
                                "
                            >
                                <FileText
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    file.name
                                }}</span>
                                <span class="shrink-0 opacity-75">{{
                                    size(file)
                                }}</span>
                                <Download
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                            </a>
                        </li>
                    </ul>
                </template>

                <p
                    :class="
                        cn(
                            'mt-0.5 flex items-center justify-end gap-1 text-[11px]',
                            message.is_mine && !message.deleted
                                ? 'text-primary-foreground/75'
                                : 'text-muted-foreground',
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
                    <span v-if="seen" class="font-medium">· Visto</span>
                </p>
            </div>

            <DropdownMenu v-if="hasActions && !editing">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 shrink-0 text-muted-foreground opacity-100 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                        aria-label="Acciones del mensaje"
                        title="Acciones del mensaje"
                    >
                        <MoreHorizontal class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent :align="message.is_mine ? 'end' : 'start'">
                    <DropdownMenuItem
                        v-if="canReply"
                        @select="emit('reply', message)"
                    >
                        <CornerUpLeft /> Responder
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="message.can.edit"
                        @select="startEditing"
                    >
                        <Pencil /> Editar
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="message.can.delete"
                        class="text-destructive focus:text-destructive"
                        @select="emit('delete', message)"
                    >
                        <Trash2 /> Eliminar
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
