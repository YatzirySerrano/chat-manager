<script setup lang="ts">
import {
    ArrowUpToLine,
    CircleCheck,
    CircleHelp,
    Gauge,
    ListOrdered,
    ShieldAlert,
    Timer,
} from '@lucide/vue';
import { computed, inject } from 'vue';
import {
    noQueueActions,
    QUEUE_ACTIONS,
    useAcceptance,
} from '@/components/conversations/queueRules';
import { Button } from '@/components/ui/button';
import type { ConversationSummary } from '@/types';

/**
 * Why a pending request cannot be taken right now, as visible text next to
 * its disabled «Aceptar» (which is described by it), with what can be done
 * about it: go to the request the viewer can take, confirm the request as
 * complete, or justify an exception, each only for whoever may.
 *
 * The minimum wait counts down by itself against the server clock; it is
 * not a live region (a screen reader reads it on demand, never every second).
 */
const props = defineProps<{
    conversation: ConversationSummary;
    /** Id of the element, referenced by the button's aria-describedby. */
    id: string;
}>();

const actions = inject(QUEUE_ACTIONS, noQueueActions);
const view = useAcceptance(() => props.conversation);

/* The control of pending conversations has its own notice above the list. */
const shown = computed(
    () =>
        view.value.blocked &&
        view.value.code !== 'pending_attention' &&
        view.value.code !== 'not_permitted',
);

const icon = computed(
    () =>
        ({
            needs_info: CircleHelp,
            min_wait: Timer,
            queue_order: ListOrdered,
            capacity: Gauge,
        })[view.value.code as string] ?? CircleHelp,
);
</script>

<template>
    <div
        v-if="shown"
        :id="id"
        class="grid gap-1.5 text-xs text-muted-foreground"
        :data-acceptance-code="view.code"
    >
        <p class="flex items-start gap-1.5">
            <component
                :is="icon"
                class="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
            <span v-if="view.code === 'min_wait'" class="text-foreground">
                Disponible para atención en
                <span class="font-semibold tabular-nums" data-queue-countdown>{{
                    view.countdown
                }}</span>
            </span>
            <span v-else class="text-foreground">{{ view.text }}</span>
        </p>
        <div
            v-if="
                view.blockingId !== null ||
                view.overridable ||
                (view.code === 'needs_info' && conversation.can.confirm_ready)
            "
            class="flex flex-wrap gap-1.5"
        >
            <Button
                v-if="view.blockingId !== null"
                type="button"
                size="sm"
                variant="outline"
                class="h-7 bg-background px-2 text-xs pointer-coarse:h-10"
                data-acceptance-go-to-head
                @click="actions.open(view.blockingId)"
            >
                <ArrowUpToLine aria-hidden="true" />
                Ir a la solicitud que puedes tomar
            </Button>
            <Button
                v-if="
                    view.code === 'needs_info' && conversation.can.confirm_ready
                "
                type="button"
                size="sm"
                variant="outline"
                class="h-7 bg-background px-2 text-xs pointer-coarse:h-10"
                data-readiness-confirm
                @click="actions.readiness(conversation, 'ready')"
            >
                <CircleCheck aria-hidden="true" />
                Confirmar solicitud completa
            </Button>
            <Button
                v-if="view.overridable"
                type="button"
                size="sm"
                variant="outline"
                class="h-7 bg-background px-2 text-xs pointer-coarse:h-10"
                data-acceptance-override
                @click="actions.override(conversation)"
            >
                <ShieldAlert aria-hidden="true" />
                Hacer una excepción
            </Button>
        </div>
    </div>
</template>
