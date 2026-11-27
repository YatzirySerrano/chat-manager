<script setup lang="ts">
import { CircleCheck, XCircle } from '@lucide/vue';
import { computed } from 'vue';
import { formatDateTime } from '@/lib/format';
import { formatMoney, outcomeText } from '@/lib/outcomes';
import type { ConversationOutcome } from '@/types';

/**
 * «Completada»: the conversation was closed as «Solicitud completada»
 * (`completed_at`), so the customer's next message starts a new request.
 * Text and icon in the success color, never color alone; quiet enough for
 * the dense list rows and any header that shows the conversation's state.
 *
 * With the credit result (optional) it says what became of the request:
 * «Aprobado $3,000» or «Rechazada» (attended, not approved).
 */
const props = defineProps<{
    /** When it was completed (ISO 8601), for the tooltip. */
    at?: string | null;
    /** Credit result recorded on the request, when the badge shows it. */
    outcome?: ConversationOutcome | null;
}>();

const title = computed(() => {
    const base = props.at
        ? `Cerrada como solicitud completada el ${formatDateTime(props.at)}`
        : 'Cerrada como solicitud completada';
    const result = outcomeText(props.outcome);

    return result ? `${base} · ${result}` : base;
});

const rejected = computed(() => props.outcome?.value === 'rejected');

const label = computed(() => {
    if (props.outcome?.value === 'approved') {
        return props.outcome.amount !== null
            ? `Aprobado ${formatMoney(props.outcome.amount)}`
            : 'Aprobado';
    }

    return rejected.value ? 'Rechazada' : 'Completada';
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 text-xs font-medium whitespace-nowrap"
        :class="
            rejected ? 'text-muted-foreground' : 'text-success-soft-foreground'
        "
        :title="title"
        data-completed-badge
        :data-outcome="outcome?.value"
    >
        <component
            :is="rejected ? XCircle : CircleCheck"
            class="size-3 shrink-0"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
