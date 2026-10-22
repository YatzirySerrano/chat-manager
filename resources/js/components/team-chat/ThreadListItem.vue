<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Archive, BellOff } from '@lucide/vue';
import { computed } from 'vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show } from '@/routes/team-chat/threads';
import type { TeamThreadSummary } from '@/types/team-chat';

const props = defineProps<{
    thread: TeamThreadSummary;
    active: boolean;
    query: Record<string, string | number>;
}>();

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

const unreadLabel = computed(() =>
    props.thread.unread > 99 ? '99+' : String(props.thread.unread),
);
</script>

<template>
    <Link
        :href="show(thread.id, { query })"
        preserve-state
        preserve-scroll
        :aria-current="active ? 'page' : undefined"
        :class="
            cn(
                'flex items-center gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none',
                active && 'bg-primary-soft hover:bg-primary-soft',
            )
        "
    >
        <TeamAvatar
            :name="thread.title"
            :group="thread.type === 'group'"
            :deleted="thread.partner?.deleted ?? false"
            :availability="thread.partner?.availability ?? null"
        />
        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-2">
                <span
                    :class="
                        cn(
                            'min-w-0 flex-1 truncate text-sm',
                            thread.unread > 0 ? 'font-semibold' : 'font-medium',
                        )
                    "
                >
                    {{ thread.title }}
                </span>
                <time
                    v-if="thread.last_activity_at"
                    class="shrink-0 text-[11px] text-muted-foreground"
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
                            'inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold',
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
</template>
