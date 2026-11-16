<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Archive, BellOff, Eye } from '@lucide/vue';
import { computed } from 'vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import HighlightMatch from '@/components/filters/HighlightMatch.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import { listTime } from '@/components/team-chat/teamChatFormat';
import { useLongPress } from '@/composables/useLongPress';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show } from '@/routes/team-chat/threads';
import type { TeamThreadSummary } from '@/types/team-chat';

/**
 * One chat of the internal chat list. Holding it on a touch screen, right
 * clicking it or its «Vista previa» button shows the latest messages
 * without opening it (`preview`); the read position does not change.
 * The «Vista previa» button appears on hover or focus with a mouse and is
 * always visible on touch screens, where long press is not discoverable.
 */
const props = defineProps<{
    thread: TeamThreadSummary;
    active: boolean;
    /** Being opened right now (the click already landed). */
    opening?: boolean;
    query: Record<string, string | number>;
    /** Applied search: its words are marked in the title. */
    highlight?: string;
}>();

const emit = defineEmits<{ preview: [thread: TeamThreadSummary] }>();

const longPress = useLongPress(() => emit('preview', props.thread));

function onClick(event: MouseEvent): void {
    // Lifting the finger after a long touch must not also open the chat.
    if (longPress.consumeClick()) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}

const author = computed(() => {
    const message = props.thread.last_message;

    return message?.author && !message.system ? `${message.author}: ` : '';
});

const preview = computed(() => {
    const message = props.thread.last_message;

    if (!message) {
        return props.thread.type === 'group'
            ? 'Grupo sin mensajes'
            : 'Sin mensajes todavía';
    }

    return message.text;
});

const { isOnline } = useTeamPresence();
const partnerOnline = computed(
    () =>
        props.thread.partner !== null &&
        !props.thread.partner.deleted &&
        isOnline(props.thread.partner.id),
);

const unread = computed(() => props.thread.unread > 0);
const unreadLabel = computed(() =>
    props.thread.unread > 99 ? '99+' : String(props.thread.unread),
);
</script>

<template>
    <div
        class="group/row relative list-item"
        :data-selected="active || opening ? '' : undefined"
    >
        <Link
            :href="show(thread.id, { query })"
            preserve-state
            preserve-scroll
            :aria-current="active ? 'page' : undefined"
            :data-selected="active || opening ? '' : undefined"
            class="list-row flex items-center gap-2.5 py-2 pr-2.5 pl-3 outline-none select-none [-webkit-touch-callout:none] pointer-coarse:pr-12"
            v-on="longPress.handlers"
            @click.capture="onClick"
        >
            <span
                v-if="unread && !thread.muted"
                class="list-unread-dot"
                aria-hidden="true"
            />
            <ListAvatar
                :name="thread.title"
                :group="thread.type === 'group'"
                :deleted="thread.partner?.deleted ?? false"
                :online="partnerOnline"
            />
            <span class="min-w-0 flex-1">
                <span class="flex items-baseline gap-2">
                    <span
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-sm leading-5',
                                unread
                                    ? 'font-semibold text-foreground'
                                    : 'font-medium text-foreground/90',
                            )
                        "
                    >
                        <HighlightMatch
                            :text="thread.title"
                            :query="highlight ?? ''"
                        />
                        <span v-if="partnerOnline" class="sr-only"
                            >(en línea)</span
                        >
                    </span>
                    <time
                        v-if="thread.last_activity_at"
                        :class="
                            cn(
                                'list-time shrink-0 text-[0.6875rem] tabular-nums',
                                unread && !thread.muted
                                    ? 'font-semibold text-primary'
                                    : 'text-muted-foreground',
                            )
                        "
                        :datetime="thread.last_activity_at"
                        :title="formatDateTime(thread.last_activity_at)"
                    >
                        {{ listTime(thread.last_activity_at) }}
                    </time>
                </span>
                <span class="flex items-center gap-1.5">
                    <span
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-[0.8125rem] leading-5',
                                unread
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                                thread.last_message?.deleted && 'italic',
                            )
                        "
                    >
                        <span v-if="author" class="font-medium">{{
                            author
                        }}</span
                        >{{ preview }}
                    </span>
                    <Archive
                        v-if="thread.archived"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="Archivado"
                    />
                    <BellOff
                        v-if="thread.muted"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="Silenciado"
                    />
                    <!-- Bumps, rolls and sends one ring out when a message
                     arrives (muted chats only bump, in a quiet tone). -->
                    <CountBadge
                        v-if="unread"
                        :value="thread.unread"
                        :tone="thread.muted ? 'muted' : 'primary'"
                    />
                    <span v-if="unread" class="sr-only"
                        >{{ unreadLabel }} sin leer</span
                    >
                </span>
            </span>
        </Link>
        <!-- Hover quick actions: the same floating bar as the inbox rows. -->
        <div
            class="list-quick absolute top-1 right-1.5 flex items-center rounded-md border bg-popover p-px shadow-(--shadow-control) pointer-coarse:top-auto pointer-coarse:right-1 pointer-coarse:bottom-1 pointer-coarse:border-transparent pointer-coarse:bg-transparent pointer-coarse:shadow-none"
        >
            <button
                type="button"
                class="flex size-6 items-center justify-center rounded text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-colors pointer-coarse:size-9"
                :aria-label="`Vista previa de ${thread.title}`"
                title="Vista previa sin marcar como leído"
                data-thread-preview
                @click="emit('preview', thread)"
            >
                <Eye class="size-3.5" aria-hidden="true" />
            </button>
        </div>
    </div>
</template>
