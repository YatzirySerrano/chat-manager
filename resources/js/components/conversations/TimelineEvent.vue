<script setup lang="ts">
import { Bot, History } from '@lucide/vue';
import { computed } from 'vue';
import { QUEUE_RULE_LABELS } from '@/components/conversations/queueRules';
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

/*
 * «Esperando información del cliente» ended by the customer's message: the
 * request came back to «Pendientes» by itself (ResumeAwaitingInformation).
 * Its label says it all, so the stored note is not repeated.
 */
const customerInfo = computed(
    () =>
        props.event.type === 'readiness_confirmed' &&
        props.event.trigger === 'customer_info',
);

/* An unconfirmed delivery is not a "message not sent": it may have arrived. */
const deliveryUnknown = computed(() => props.event.reason === DELIVERY_UNKNOWN);

/* Who did it when no person did: the chatbot's menu, or the system. */
const systemActor = computed(() =>
    props.event.type === 'chatbot_routed' ? 'Chatbot' : 'Sistema',
);
const label = computed(() => {
    if (deliveryUnknown.value) {
        return deliveryUnknownLabel;
    }

    return customerInfo.value
        ? 'El cliente envió más información: la solicitud regresó a «Pendientes» como nueva'
        : props.event.label;
});

const detail = computed(() => {
    const parts: string[] = [];
    const event = props.event;

    if (event.type === 'sla_breached') {
        // Nobody moved the conversation: the event only records who was
        // responsible when the deadline passed (nobody = it was in a queue).
        parts.push(
            event.from_user
                ? `responsable: ${event.from_user}`
                : 'sin responsable (en cola)',
        );
    } else if (event.type === 'closed') {
        // A close ends the request: it does not go to «Pendientes».
        if (event.from_user) {
            parts.push(`atendió: ${event.from_user}`);
        }
    } else if (event.from_user || event.to_user) {
        parts.push(
            [event.from_user, event.to_user]
                .map((name) => name ?? 'Pendientes')
                .join(' → '),
        );
    }

    // The chatbot's routing already names the department in its label.
    if (
        event.type !== 'chatbot_routed' &&
        event.to_department &&
        event.to_department !== event.from_department
    ) {
        parts.push(
            event.from_department
                ? `${event.from_department} → ${event.to_department}`
                : event.to_department,
        );
    }

    if (event.trigger && triggers[event.trigger]) {
        parts.push(triggers[event.trigger]);
    }

    if (event.category) {
        parts.push(
            `${event.category.from ?? 'Sin categoría'} → ${event.category.to ?? 'Sin categoría'}`,
        );
    }

    // Credit result recorded with the completion or corrected afterwards.
    if (event.outcome) {
        parts.push(event.outcome);
    }

    // A justified exception to the queue rules made with this operation.
    if (event.queue_override) {
        const rules = event.queue_override.rules
            .map((rule) => QUEUE_RULE_LABELS[rule]?.toLowerCase() ?? rule)
            .join(', ');

        parts.push(
            `excepción a las reglas de la cola (${rules})${event.queue_override.reason ? `: «${event.queue_override.reason}»` : ''}`,
        );
    }

    return parts.join(' · ');
});
</script>

<template>
    <div class="flex items-center gap-3 px-2">
        <span
            class="h-px min-w-4 flex-1 bg-linear-to-r from-transparent to-border-strong/70"
            aria-hidden="true"
        />
        <p
            class="inline-flex max-w-[min(100%,48rem)] flex-wrap items-center justify-center gap-x-1.5 gap-y-0.5 rounded-2xl border border-border/70 glass py-1 pr-3 pl-1 text-center text-xs wrap-anywhere text-muted-foreground shadow-(--shadow-control)"
        >
            <span
                class="grid size-5 shrink-0 place-items-center rounded-full bg-primary-soft text-primary-soft-foreground"
                aria-hidden="true"
            >
                <component
                    :is="event.type === 'chatbot_routed' ? Bot : History"
                    class="size-3"
                />
            </span>
            <!-- Who did it first: «Jennifer cerró la conversación». -->
            <span v-if="event.actor && event.action && !deliveryUnknown"
                ><strong class="font-semibold text-foreground">{{
                    event.actor
                }}</strong
                >{{ ' '
                }}<span class="text-foreground/80">{{
                    event.action
                }}</span></span
            >
            <span v-else class="font-medium text-foreground/80">{{
                label
            }}</span>
            <span v-if="detail">· {{ detail }}</span>
            <span
                v-if="event.note && !customerInfo"
                class="min-w-0 wrap-anywhere"
                >· «{{ event.note }}»</span
            >
            <span v-if="!(event.actor && event.action) || deliveryUnknown"
                >·
                <strong
                    v-if="event.actor"
                    class="font-semibold text-foreground"
                    >{{ event.actor }}</strong
                ><template v-else>{{ systemActor }}</template></span
            >
            <time
                :datetime="event.created_at"
                :title="formatDateTime(event.created_at)"
                >· {{ formatTime(event.created_at) }}</time
            >
        </p>
        <span
            class="h-px min-w-4 flex-1 bg-linear-to-l from-transparent to-border-strong/70"
            aria-hidden="true"
        />
    </div>
</template>
