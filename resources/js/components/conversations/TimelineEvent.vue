<script setup lang="ts">
import { History } from '@lucide/vue';
import { computed } from 'vue';
import { formatTime } from '@/components/conversations/messageFormat';
import { formatDateTime } from '@/lib/format';
import type { ConversationEvent } from '@/types';

/**
 * An operational event (assignment, transfer, pause, closing, reopening)
 * shown as a centered line between messages.
 */
const props = defineProps<{
    event: ConversationEvent;
}>();

const triggers: Record<string, string> = {
    inbound: 'el cliente volvió a escribir',
    inactivity: 'por inactividad',
    access_change: 'por un cambio en el acceso del agente',
};

const detail = computed(() => {
    const parts: string[] = [];
    const event = props.event;

    if (event.from_user || event.to_user) {
        parts.push(
            [event.from_user, event.to_user]
                .map((name) => name ?? 'cola')
                .join(' → '),
        );
    }

    if (event.to_department && event.to_department !== event.from_department) {
        parts.push(
            event.from_department
                ? `${event.from_department} → ${event.to_department}`
                : event.to_department,
        );
    }

    if (event.trigger && triggers[event.trigger]) {
        parts.push(triggers[event.trigger]);
    }

    return parts.join(' · ');
});
</script>

<template>
    <div class="flex justify-center px-2">
        <p
            class="inline-flex max-w-full flex-wrap items-center justify-center gap-x-1.5 gap-y-0.5 rounded-full border bg-muted/60 px-3 py-1 text-center text-[11px] text-muted-foreground"
        >
            <History class="size-3 shrink-0" aria-hidden="true" />
            <span class="font-medium text-foreground/80">{{
                event.label
            }}</span>
            <span v-if="detail">· {{ detail }}</span>
            <span v-if="event.note" class="break-words"
                >· «{{ event.note }}»</span
            >
            <span>· {{ event.actor ?? 'Sistema' }}</span>
            <time
                :datetime="event.created_at"
                :title="formatDateTime(event.created_at)"
                >· {{ formatTime(event.created_at) }}</time
            >
        </p>
    </div>
</template>
