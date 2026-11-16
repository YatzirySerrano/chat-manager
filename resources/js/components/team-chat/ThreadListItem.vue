<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Archive, BellOff, Eye } from '@lucide/vue';
import { computed } from 'vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import HighlightMatch from '@/components/filters/HighlightMatch.vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
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
    <div class="group/thread relative">
        <Link
            :href="show(thread.id, { query })"
            preserve-state
            preserve-scroll
            :aria-current="active ? 'page' : undefined"
            :class="
                cn(
                    'flex items-center gap-3 rounded-xl py-2 pr-3 pl-2.5 transition-[background-color] duration-(--duration-fast) ease-(--ease-standard) outline-none select-none [-webkit-touch-callout:none] hover:bg-accent focus-visible:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/40 pointer-coarse:py-2.5',
                    (active || opening) &&
                        'bg-primary-soft hover:bg-primary-soft',
                )
            "
            v-on="longPress.handlers"
            @click.capture="onClick"
        >
            <TeamAvatar
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
                                'min-w-0 flex-1 truncate font-display text-[0.95rem] leading-snug',
                                unread ? 'font-semibold' : 'font-medium',
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
                                'shrink-0 text-[0.7rem] tabular-nums transition-opacity group-focus-within/thread:opacity-0 group-hover/thread:opacity-0 pointer-coarse:opacity-100!',
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
                <span
                    class="mt-0.5 flex items-center gap-1.5 pointer-coarse:pr-9"
                >
                    <span
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-[0.8rem] leading-snug',
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
                    <!-- Bumps and sends one ring out when a message arrives
                     (muted chats only bump, in a quiet tone). -->
                    <CountBadge
                        v-if="unread"
                        :value="thread.unread"
                        :tone="thread.muted ? 'muted' : 'primary'"
                        :halo="!thread.muted"
                        class="h-5 min-w-5"
                    />
                    <span v-if="unread" class="sr-only"
                        >{{ unreadLabel }} sin leer</span
                    >
                </span>
            </span>
        </Link>
        <button
            type="button"
            class="absolute top-1.5 right-1.5 flex size-7 items-center justify-center rounded-full bg-background text-muted-foreground opacity-0 shadow-(--shadow-control) ring-1 ring-border outline-none group-focus-within/thread:opacity-100 group-hover/thread:opacity-100 hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-opacity pointer-coarse:top-auto pointer-coarse:right-1 pointer-coarse:bottom-1 pointer-coarse:size-9 pointer-coarse:bg-transparent pointer-coarse:opacity-100 pointer-coarse:shadow-none pointer-coarse:ring-0"
            :aria-label="`Vista previa de ${thread.title}`"
            title="Vista previa sin marcar como leído"
            data-thread-preview
            @click="emit('preview', thread)"
        >
            <Eye class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
