<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    CircleCheckBig,
    Clock,
    Hourglass,
    MessagesSquare,
    Paperclip,
    Sparkles,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import CategoryBadge from '@/components/categories/CategoryBadge.vue';
import ChatPreviewDialog from '@/components/chat-preview/ChatPreviewDialog.vue';
import type {
    ChatPreviewAppearance,
    ChatPreviewAttachment,
    ChatPreviewItem,
} from '@/components/chat-preview/types';
import {
    previewText,
    typeLabel,
} from '@/components/conversations/messageFormat';
import { useAcceptance } from '@/components/conversations/queueRules';
import ReadinessBadge from '@/components/conversations/ReadinessBadge.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import { useChannel } from '@/composables/useChannel';
import { formatDateTime } from '@/lib/format';
import { preview as previewRoute } from '@/routes/conversations';
import type {
    AppColor,
    ConversationAcceptance,
    ConversationCategoryRef,
    ConversationMessage,
    ConversationReadinessValue,
    ConversationSummary,
} from '@/types';

/**
 * The inbox preview of one conversation (long press, right click or
 * «Vista previa» on its row). It only reads (GET conversations.preview):
 * the chat is not opened, so the read marker, the unread counter, the
 * notifications and the conversation itself stay as they were. While it is
 * open it follows the conversation's channel and reads the latest messages
 * again when a new one arrives.
 *
 * Besides the latest messages it tells what the request is: the message it
 * started with (set apart when it is not among the latest ones), when it
 * was received, its department and category, where it stands («Nueva»,
 * «Pendiente», «En proceso», «Finalizada», with icon and text), its files
 * by type and whether it is available for attention (the `acceptance`
 * contract of the list, read fresh by the same read-only request).
 */
const props = defineProps<{
    conversation: ConversationSummary | null;
    /** The row's unread counter, shown untouched. */
    unread: number;
}>();

const emit = defineEmits<{
    close: [];
    open: [id: number];
}>();

type PreviewStage = 'new' | 'pending' | 'in_progress' | 'finished';

type PreviewFacts = {
    id: number;
    status: string;
    status_label: string;
    stage: { value: PreviewStage; label: string };
    received_at: string | null;
    department: {
        id: number;
        name: string;
        color: AppColor;
        is_active: boolean;
    } | null;
    category: ConversationCategoryRef | null;
    assignee: string | null;
    readiness: {
        value: ConversationReadinessValue;
        label: string;
    };
    attachments: { total: number; by_type: Record<string, number> };
    acceptance: ConversationAcceptance | null;
};

type PreviewPage = {
    messages: ConversationMessage[];
    has_more: boolean;
    /** The message the conversation started with, when not among `messages`. */
    first_message: ConversationMessage | null;
    conversation: PreviewFacts;
    /** The viewer's own effective chat colors, as in the chat. */
    appearance: ChatPreviewAppearance;
};

const http = useHttp<Record<string, never>, PreviewPage>({});
const messages = ref<ConversationMessage[]>([]);
const hasMore = ref(false);
const firstMessage = ref<ConversationMessage | null>(null);
const facts = ref<PreviewFacts | null>(null);
const appearance = ref<ChatPreviewAppearance | null>(null);
const error = ref<string | null>(null);
let generation = 0;

async function load(): Promise<void> {
    const id = props.conversation?.id;

    if (!id) {
        return;
    }

    const current = ++generation;
    error.value = null;

    try {
        const page = await http.get(previewRoute.url(id));

        if (current === generation) {
            messages.value = page.messages;
            hasMore.value = page.has_more;
            firstMessage.value = page.first_message ?? null;
            facts.value = page.conversation ?? null;
            appearance.value = page.appearance;
        }
    } catch {
        if (current === generation) {
            error.value =
                'No se pudo cargar la vista previa. Es posible que ya no tengas acceso a esta conversación.';
        }
    }
}

watch(
    () => props.conversation?.id ?? null,
    (id) => {
        messages.value = [];
        hasMore.value = false;
        firstMessage.value = null;
        facts.value = null;
        appearance.value = null;

        if (id !== null) {
            void load();
        }
    },
    { immediate: true },
);

/* A message arriving while it is open shows up here too. */
const reload = useDebounceFn(() => void load(), 400);

useChannel(
    () =>
        props.conversation ? `conversations.${props.conversation.id}` : null,
    {
        '.message.recorded': () => void reload(),
        '.message.status': () => void reload(),
    },
);

const contactName = computed(
    () =>
        props.conversation?.contact.display_name ||
        props.conversation?.contact.name ||
        'Cliente',
);

function attachmentKind(type: string): ChatPreviewAttachment['kind'] {
    return (
        ['image', 'sticker', 'audio', 'video', 'document'] as const
    ).includes(type as never)
        ? (type as ChatPreviewAttachment['kind'])
        : 'other';
}

function author(message: ConversationMessage): string {
    if (message.direction === 'inbound') {
        return contactName.value;
    }

    if (message.automatic) {
        return 'Mensaje automático';
    }

    return (
        message.sender?.name ??
        message.imported?.sender_name ??
        message.integration?.name ??
        'Equipo'
    );
}

function toItem(message: ConversationMessage): ChatPreviewItem {
    const media = ['image', 'sticker', 'audio', 'video', 'document'];
    const attachments: ChatPreviewAttachment[] = message.attachments.map(
        (attachment) => ({
            kind: attachmentKind(message.type),
            label: attachment.file_name ?? typeLabel(message.type),
        }),
    );

    if (attachments.length === 0 && media.includes(message.type)) {
        attachments.push({
            kind: attachmentKind(message.type),
            label: typeLabel(message.type),
        });
    }

    const text =
        message.type === 'text' || media.includes(message.type)
            ? (message.body?.trim() ?? '') || null
            : previewText(message);

    return {
        id: message.id,
        author: author(message),
        mine: message.direction === 'outbound',
        text,
        attachments,
        at: message.sent_at ?? message.created_at,
        note:
            message.status === 'failed'
                ? 'No se pudo entregar'
                : message.template
                  ? `Plantilla ${message.template.name}`
                  : null,
    };
}

const items = computed<ChatPreviewItem[]>(() => messages.value.map(toItem));
const first = computed(() =>
    firstMessage.value ? toItem(firstMessage.value) : null,
);

const STAGES: Record<PreviewStage, { icon: typeof Clock; class: string }> = {
    new: {
        icon: Sparkles,
        class: 'border-info/40 bg-info-soft text-info-soft-foreground',
    },
    pending: {
        icon: Hourglass,
        class: 'border-warning/50 bg-warning-soft text-warning-soft-foreground',
    },
    in_progress: {
        icon: MessagesSquare,
        class: 'border-success/40 bg-success-soft text-success-soft-foreground',
    },
    finished: {
        icon: CircleCheckBig,
        class: 'border-border bg-muted text-muted-foreground',
    },
};

const stage = computed(() =>
    facts.value ? (STAGES[facts.value.stage.value] ?? STAGES.finished) : null,
);

const FILE_LABELS: Record<string, [string, string]> = {
    image: ['imagen', 'imágenes'],
    sticker: ['sticker', 'stickers'],
    audio: ['audio', 'audios'],
    video: ['video', 'videos'],
    document: ['documento', 'documentos'],
};

const filesSummary = computed(() => {
    const files = facts.value?.attachments;

    if (!files || files.total === 0) {
        return 'Sin archivos adjuntos';
    }

    return Object.entries(files.by_type)
        .map(([type, total]) => {
            const [one, many] = FILE_LABELS[type] ?? ['archivo', 'archivos'];

            return `${total} ${total === 1 ? one : many}`;
        })
        .join(', ');
});

/* Availability for attention, counted down like the list row. */
const acceptance = useAcceptance(
    () =>
        ({
            acceptance: facts.value?.acceptance ?? null,
        }) as ConversationSummary,
);

const availability = computed(() => {
    const state = facts.value?.acceptance;

    if (!facts.value || !state) {
        return null;
    }

    if (acceptance.value.blocked && state.available === false) {
        return { available: false, text: acceptance.value.text ?? '' };
    }

    return {
        available: true,
        text:
            acceptance.value.blocked && acceptance.value.text
                ? `Disponible para atención. ${acceptance.value.text}`
                : 'Disponible para atención',
    };
});
</script>

<template>
    <ChatPreviewDialog
        :open="conversation !== null"
        :title="contactName"
        :subtitle="conversation?.status_label ?? null"
        :items="items"
        :loading="http.processing"
        :error="error"
        :has-more="hasMore"
        :colors="appearance?.colors ?? null"
        :unread="unread"
        open-label="Abrir conversación"
        @update:open="(value) => !value && emit('close')"
        @open="conversation && emit('open', conversation.id)"
        @retry="load"
    >
        <template #summary>
            <div
                v-if="facts"
                class="grid gap-2 border-b px-5 py-3 text-xs"
                data-preview-summary
            >
                <div class="flex flex-wrap items-center gap-1.5">
                    <span
                        v-if="stage"
                        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-semibold whitespace-nowrap"
                        :class="stage.class"
                        :data-preview-stage="facts.stage.value"
                    >
                        <component
                            :is="stage.icon"
                            class="size-3 shrink-0"
                            aria-hidden="true"
                        />
                        {{ facts.stage.label }}
                    </span>
                    <ReadinessBadge
                        v-if="
                            facts.stage.value !== 'finished' &&
                            (facts.readiness.value === 'needs_info' ||
                                facts.acceptance !== null)
                        "
                        :value="facts.readiness.value"
                        :label="facts.readiness.label"
                    />
                    <DepartmentTag
                        v-if="facts.department"
                        :name="facts.department.name"
                        :color="facts.department.color"
                        :muted="!facts.department.is_active"
                    />
                    <CategoryBadge
                        v-if="facts.category"
                        :name="facts.category.name"
                        :color="facts.category.color"
                        :deleted="facts.category.is_active === false"
                    />
                </div>
                <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1">
                    <dt class="text-muted-foreground">Recibida</dt>
                    <dd>
                        <time
                            v-if="facts.received_at"
                            :datetime="facts.received_at"
                            >{{ formatDateTime(facts.received_at) }}</time
                        >
                        <span v-else>Sin fecha</span>
                    </dd>
                    <template v-if="!facts.department">
                        <dt class="text-muted-foreground">Área</dt>
                        <dd>Sin departamento</dd>
                    </template>
                    <template v-if="!facts.category">
                        <dt class="text-muted-foreground">Categoría</dt>
                        <dd>Sin categoría</dd>
                    </template>
                    <template v-if="facts.assignee">
                        <dt class="text-muted-foreground">Responsable</dt>
                        <dd class="truncate">{{ facts.assignee }}</dd>
                    </template>
                    <dt class="text-muted-foreground">Archivos</dt>
                    <dd class="flex items-center gap-1">
                        <Paperclip
                            class="size-3 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{ filesSummary }}
                    </dd>
                    <template v-if="availability">
                        <dt class="text-muted-foreground">Atención</dt>
                        <dd
                            class="flex items-start gap-1"
                            :data-preview-available="
                                availability.available ? 'yes' : 'no'
                            "
                        >
                            <component
                                :is="
                                    availability.available
                                        ? CircleCheckBig
                                        : Clock
                                "
                                class="mt-0.5 size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span>{{ availability.text }}</span>
                        </dd>
                    </template>
                </dl>
                <div
                    v-if="first"
                    class="grid gap-0.5 rounded-md border bg-muted/40 px-2.5 py-2"
                    data-preview-first-message
                >
                    <p class="font-semibold">
                        Mensaje inicial
                        <span class="font-normal text-muted-foreground">
                            · {{ first.author
                            }}<template v-if="first.at">
                                · {{ formatDateTime(first.at) }}</template
                            >
                        </span>
                    </p>
                    <p
                        v-if="first.text"
                        class="line-clamp-3 text-sm wrap-anywhere whitespace-pre-line"
                    >
                        {{ first.text }}
                    </p>
                    <p
                        v-for="(file, index) in first.attachments"
                        :key="index"
                        class="flex items-center gap-1 text-muted-foreground"
                    >
                        <Paperclip class="size-3 shrink-0" aria-hidden="true" />
                        <span class="truncate">{{ file.label }}</span>
                    </p>
                </div>
            </div>
        </template>
    </ChatPreviewDialog>
</template>
