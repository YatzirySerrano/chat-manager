<script setup lang="ts">
import { History } from '@lucide/vue';
import { computed } from 'vue';
import {
    DELIVERY_UNKNOWN,
    deliveryUnknownLabel,
    formatTime,
} from '@/components/conversations/messageFormat';
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

/* An unconfirmed delivery is not a "message not sent": it may have arrived. */
const label = computed(() =>
    props.event.reason === DELIVERY_UNKNOWN
        ? deliveryUnknownLabel
        : props.event.label,
);

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
            class="inline-flex max-w-full flex-wrap items-center justify-center gap-x-1.5 gap-y-0.5 rounded-2xl border bg-muted/60 px-3 py-1 text-center text-xs text-muted-foreground"
        >
            <History class="size-3.5 shrink-0" aria-hidden="true" />
            <span class="font-medium text-foreground/80">{{ label }}</span>
            <span v-if="detail">· {{ detail }}</span>
            <span v-if="event.note" class="min-w-0 wrap-anywhere"
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
