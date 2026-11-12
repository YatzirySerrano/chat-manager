<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Archive, BellOff, Eye } from '@lucide/vue';
import { computed } from 'vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import { useLongPress } from '@/composables/useLongPress';
import { useTeamPresence } from '@/composables/useTeamPresence';
import { formatDateTime, formatRelative } from '@/lib/format';
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
    query: Record<string, string | number>;
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

const preview = computed(() => {
    const message = props.thread.last_message;

    if (!message) {
        return props.thread.type === 'group'
            ? 'Grupo sin mensajes'
            : 'Sin mensajes todavía';
    }

    return message.author && !message.system
        ? `${message.author}: ${message.text}`
        : message.text;
});

const { isOnline } = useTeamPresence();
const partnerOnline = computed(
    () =>
        props.thread.partner !== null &&
        !props.thread.partner.deleted &&
        isOnline(props.thread.partner.id),
);

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
                    'flex items-center gap-3 rounded-lg py-2.5 pr-10 pl-3 transition-colors select-none [-webkit-touch-callout:none] hover:bg-accent focus-visible:bg-accent focus-visible:outline-none pointer-coarse:pr-12',
                    active && 'bg-primary-soft hover:bg-primary-soft',
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
                <span class="flex items-center gap-2">
                    <span
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-sm',
                                thread.unread > 0
                                    ? 'font-semibold'
                                    : 'font-medium',
                            )
                        "
                    >
                        {{ thread.title }}
                        <span v-if="partnerOnline" class="sr-only"
                            >(en línea)</span
                        >
                    </span>
                    <time
                        v-if="thread.last_activity_at"
                        class="shrink-0 text-xs text-muted-foreground"
                        :datetime="thread.last_activity_at"
                        :title="formatDateTime(thread.last_activity_at)"
                    >
                        {{ formatRelative(thread.last_activity_at) }}
                    </time>
                </span>
                <span class="mt-0.5 flex items-center gap-1.5">
                    <span
                        :class="
                            cn(
                                'min-w-0 flex-1 truncate text-xs',
                                thread.unread > 0
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                                thread.last_message?.deleted && 'italic',
                            )
                        "
                    >
                        {{ preview }}
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
                    <span
                        v-if="thread.unread > 0"
                        :class="
                            cn(
                                'inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-xs font-semibold',
                                thread.muted
                                    ? 'bg-muted text-muted-foreground'
                                    : 'bg-primary text-primary-foreground',
                            )
                        "
                        :aria-label="`${thread.unread} sin leer`"
                    >
                        {{ unreadLabel }}
                    </span>
                </span>
            </span>
        </Link>
        <button
            type="button"
            class="absolute top-1/2 right-1.5 flex size-8 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground opacity-0 outline-none group-focus-within/thread:opacity-100 group-hover/thread:opacity-100 hover:bg-background hover:text-foreground focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-opacity pointer-coarse:right-1 pointer-coarse:size-10 pointer-coarse:opacity-100"
            :aria-label="`Vista previa de ${thread.title}`"
            title="Vista previa sin marcar como leído"
            data-thread-preview
            @click="emit('preview', thread)"
        >
            <Eye class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
