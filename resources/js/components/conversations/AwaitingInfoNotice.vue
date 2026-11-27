<script setup lang="ts">
import { CircleCheck, FileClock } from '@lucide/vue';
import { computed, inject } from 'vue';
import {
    awaitingSince,
    noQueueActions,
    QUEUE_ACTIONS,
} from '@/components/conversations/queueRules';
import { formatDateTime } from '@/lib/format';
import type { ConversationSummary } from '@/types';

/**
 * «Esperando información del cliente», once, under the chat header of a
 * request returned for missing information: since when it waits, what the
 * customer was asked for (compact chips, when the server sent the list),
 * that it comes back to «Pendientes» by itself as soon as the customer
 * writes, and, for whoever may (`can.confirm_ready`), the prominent
 * «Confirmar solicitud completa».
 *
 * It replaces the queue-rule notice (AcceptanceNotice) for these requests,
 * so the chat never repeats «todavía necesita información». Its id is the
 * one the header's operations point at (aria-describedby).
 */
const props = defineProps<{
    conversation: ConversationSummary;
    /** Id of the element, referenced by the header's operations. */
    id: string;
}>();

const actions = inject(QUEUE_ACTIONS, noQueueActions);

const since = computed(() =>
    awaitingSince(props.conversation.awaiting_info_at),
);
const sinceTitle = computed(() =>
    formatDateTime(props.conversation.awaiting_info_at),
);
const missing = computed(() => props.conversation.awaiting_info_missing ?? []);
</script>

<template>
    <section
        :id="id"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-warning/45 bg-warning-soft px-3 py-2.5 text-warning-soft-foreground shadow-(--shadow-control)"
        :aria-labelledby="`${id}-title`"
        data-awaiting-info-notice
    >
        <span
            class="grid size-8 shrink-0 place-items-center self-start rounded-full bg-warning text-background"
            aria-hidden="true"
        >
            <FileClock class="size-4" />
        </span>
        <div class="min-w-0 flex-1 basis-56">
            <p :id="`${id}-title`" class="text-sm leading-5 font-semibold">
                Esperando información del cliente
                <time
                    v-if="since"
                    class="font-normal whitespace-nowrap"
                    :datetime="conversation.awaiting_info_at ?? undefined"
                    :title="sinceTitle"
                    >desde {{ since }}</time
                >
            </p>
            <ul
                v-if="missing.length > 0"
                class="mt-1.5 flex flex-wrap gap-1"
                aria-label="Información que se le pidió"
                data-awaiting-info-missing
            >
                <li
                    v-for="item in missing"
                    :key="item"
                    class="max-w-full truncate rounded-full border border-warning/40 bg-background/75 px-2 py-0.5 text-xs font-medium text-foreground"
                    :title="item"
                >
                    {{ item }}
                </li>
            </ul>
            <p class="mt-1 text-xs leading-4">
                Regresará a «Pendientes» en cuanto el cliente escriba.
            </p>
        </div>
        <button
            v-if="conversation.can.confirm_ready"
            type="button"
            class="group/confirm inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full bg-success px-3.5 text-sm font-semibold text-background shadow-(--shadow-control) transition-[box-shadow,scale,filter] duration-(--duration-base) ease-(--ease-spring) outline-none hover:shadow-(--shadow-lift) hover:brightness-110 focus-visible:ring-[3px] focus-visible:ring-success/45 focus-visible:ring-offset-2 focus-visible:ring-offset-warning-soft active:scale-95 pointer-coarse:h-11"
            data-readiness-confirm
            @click="actions.readiness(conversation, 'ready')"
        >
            <CircleCheck
                class="size-4 shrink-0 transition-transform duration-(--duration-panel) ease-(--ease-spring) group-hover/confirm:scale-115"
                aria-hidden="true"
            />
            Confirmar solicitud completa
        </button>
    </section>
</template>
