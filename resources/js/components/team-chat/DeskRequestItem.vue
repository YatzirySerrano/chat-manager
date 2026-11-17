<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CheckCheck, Clock, Hand } from '@lucide/vue';
import { computed, ref } from 'vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import { listTime } from '@/components/team-chat/teamChatFormat';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show } from '@/routes/team-chat/threads';
import { accept } from '@/routes/team-chat/threads/desk';
import type { TeamDeskRow, TeamDeskTab } from '@/types/team-chat';

/**
 * One request of the «Mesa de control» section of an auditor: who asks,
 * the latest message, how long it has waited and, on «Pendientes», the
 * «Aceptar» shortcut (the same action as the button of the open request).
 * Opening it keeps the section and its tab (`desk` in the URL).
 */
const props = defineProps<{
    row: TeamDeskRow;
    tab: TeamDeskTab;
    active: boolean;
    opening?: boolean;
}>();

const accepting = ref(false);

const author = computed(() => {
    const message = props.row.last_message;

    return message?.author && !message.system ? `${message.author}: ` : '';
});

const unread = computed(() => props.row.unread > 0);

/* When the row's clock starts: waiting since the request, or completed. */
const since = computed(() =>
    props.tab === 'completed'
        ? props.row.desk.completed_at
        : (props.row.desk.requested_at ?? props.row.last_activity_at),
);

const detail = computed(() => {
    const desk = props.row.desk;

    if (props.tab === 'completed') {
        return desk.completed_by
            ? `Completada por ${desk.completed_by}`
            : 'Completada';
    }

    if (props.tab === 'mine') {
        return desk.accepted_at
            ? `Aceptada ${formatRelative(desk.accepted_at)}`
            : 'En atención';
    }

    return since.value
        ? `En espera desde ${formatRelative(since.value)}`
        : 'En espera';
});

function acceptRow(): void {
    router.post(
        accept.url(props.row.id),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (accepting.value = true),
            onFinish: () => (accepting.value = false),
        },
    );
}
</script>

<template>
    <div
        class="group/row relative list-item"
        :data-selected="active || opening ? '' : undefined"
        data-desk-row
    >
        <Link
            :href="show(row.id, { query: { desk: tab } })"
            preserve-state
            preserve-scroll
            :aria-current="active ? 'page' : undefined"
            :data-selected="active || opening ? '' : undefined"
            :class="
                cn(
                    'list-row flex items-center gap-2.5 py-2 pr-2.5 pl-3 outline-none select-none',
                    tab === 'pending' && 'pr-28',
                )
            "
        >
            <span v-if="unread" class="list-unread-dot" aria-hidden="true" />
            <ListAvatar
                :name="row.title"
                :deleted="row.desk.requester?.deleted ?? false"
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
                        >{{ row.title }}</span
                    >
                    <time
                        v-if="row.last_activity_at && tab !== 'pending'"
                        class="list-time shrink-0 text-[0.6875rem] text-muted-foreground tabular-nums"
                        :datetime="row.last_activity_at"
                        :title="formatDateTime(row.last_activity_at)"
                    >
                        {{ listTime(row.last_activity_at) }}
                    </time>
                </span>
                <span
                    :class="
                        cn(
                            'block truncate text-[0.8125rem] leading-5',
                            unread
                                ? 'text-foreground'
                                : 'text-muted-foreground',
                            row.last_message?.deleted && 'italic',
                        )
                    "
                >
                    <span v-if="author" class="font-medium">{{ author }}</span
                    >{{ row.last_message?.text ?? 'Sin mensajes todavía' }}
                </span>
                <span
                    class="flex items-center gap-1 text-[0.6875rem] leading-4 text-muted-foreground"
                >
                    <CheckCheck
                        v-if="tab === 'completed'"
                        class="size-3 shrink-0"
                        aria-hidden="true"
                    />
                    <Clock v-else class="size-3 shrink-0" aria-hidden="true" />
                    <span
                        class="truncate"
                        :title="since ? formatDateTime(since) : undefined"
                        >{{ detail }}</span
                    >
                </span>
            </span>
            <CountBadge v-if="unread" :value="row.unread" tone="primary" />
            <span v-if="unread" class="sr-only"
                >{{ row.unread > 99 ? '99+' : row.unread }} sin leer</span
            >
        </Link>
        <button
            v-if="tab === 'pending'"
            type="button"
            class="absolute top-1/2 right-2 inline-flex h-7 -translate-y-1/2 items-center gap-1 rounded-md border bg-background px-2 text-xs font-medium shadow-(--shadow-control) outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60 pointer-coarse:h-9"
            :disabled="accepting"
            :aria-label="`Aceptar la solicitud de ${row.title}`"
            data-desk-accept
            @click="acceptRow"
        >
            <Hand class="size-3.5" aria-hidden="true" />
            {{ accepting ? 'Aceptando…' : 'Aceptar' }}
        </button>
    </div>
</template>
