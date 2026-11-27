<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BadgeCheck, CircleSlash, XCircle } from '@lucide/vue';
import { computed, defineAsyncComponent, ref } from 'vue';
import { toast } from 'vue-sonner';
import { formatMoney } from '@/lib/outcomes';
import { update as updateOutcome } from '@/routes/conversations/outcome';
import type { ConversationSummary } from '@/types';

// Loaded the first time it is opened.
const CompleteRequestDialog = defineAsyncComponent(
    () => import('@/components/conversations/CompleteRequestDialog.vue'),
);

/**
 * «Resultado: Crédito aprobado $3,000 · Cambiar» in the details panel of a
 * completed request. Whoever completed it, or manages it, may record or
 * correct the result (can.record_outcome); everyone else only reads it.
 */
const props = defineProps<{
    conversation: ConversationSummary;
}>();

const completed = computed(
    () =>
        props.conversation.status === 'closed' &&
        Boolean(props.conversation.completed_at),
);
const outcome = computed(() => props.conversation.outcome ?? null);
const canChange = computed(() =>
    Boolean(props.conversation.can.record_outcome),
);

const open = ref(false);
const saving = ref(false);
const error = ref<string | undefined>(undefined);

function save(payload: {
    outcome: string | null;
    outcome_amount: string | null;
}): void {
    router.put(updateOutcome.url(props.conversation.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            saving.value = true;
            error.value = undefined;
        },
        onSuccess: () => (open.value = false),
        onError: (errors) => {
            error.value = Object.values(errors)[0];

            if (error.value) {
                toast.error(error.value);
            }
        },
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <div
        v-if="completed && (outcome || canChange)"
        class="grid gap-1.5"
        data-request-outcome
    >
        <p class="text-xs text-muted-foreground">Resultado del crédito</p>
        <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
            <span
                v-if="outcome?.value === 'approved'"
                class="inline-flex items-center gap-1 font-medium text-success-soft-foreground"
            >
                <BadgeCheck class="size-3.5 shrink-0" aria-hidden="true" />
                {{ outcome.label }}
                <span
                    v-if="outcome.amount !== null"
                    class="font-semibold tabular-nums"
                    >{{ formatMoney(outcome.amount) }}</span
                >
            </span>
            <span
                v-else-if="outcome?.value === 'rejected'"
                class="inline-flex items-center gap-1 font-medium text-destructive"
            >
                <XCircle class="size-3.5 shrink-0" aria-hidden="true" />
                {{ outcome.label }}
            </span>
            <span
                v-else
                class="inline-flex items-center gap-1 text-muted-foreground"
            >
                <CircleSlash class="size-3.5 shrink-0" aria-hidden="true" />
                Sin resultado
            </span>
            <template v-if="canChange">
                <span class="text-muted-foreground" aria-hidden="true">·</span>
                <button
                    type="button"
                    class="rounded text-sm font-medium text-primary underline-offset-2 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/40"
                    :disabled="saving"
                    data-request-outcome-change
                    @click="open = true"
                >
                    {{ outcome ? 'Cambiar' : 'Registrar' }}
                </button>
            </template>
        </p>

        <CompleteRequestDialog
            v-if="canChange"
            v-model:open="open"
            mode="correct"
            :conversation-id="conversation.id"
            :current="outcome"
            :processing="saving"
            :error="error"
            @confirm="save"
        />
    </div>
</template>
