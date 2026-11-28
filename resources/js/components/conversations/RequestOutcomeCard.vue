<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Check,
    CircleSlash,
    Pencil,
    X,
    XCircle,
} from '@lucide/vue';
import { computed, defineAsyncComponent, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Input } from '@/components/ui/input';
import { formatMoney, normalizeAmount } from '@/lib/outcomes';
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
 * The authorized amount of an approved credit is corrected right here
 * («Corregir monto»: Enter saves, Esc cancels); it is the same amount as
 * «Monto autorizado» in the request data, kept in step by the server.
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

const editingAmount = ref(false);
const amountDraft = ref('');
const amountError = ref<string | undefined>(undefined);
const amountInput = ref<InstanceType<typeof Input> | null>(null);

async function startAmountEdit(): Promise<void> {
    amountDraft.value = outcome.value?.amount
        ? formatMoney(outcome.value.amount).replace(/^\$/, '')
        : '';
    amountError.value = undefined;
    editingAmount.value = true;
    await nextTick();
    const element = (amountInput.value?.$el ?? null) as HTMLInputElement | null;
    element?.focus();
    element?.select();
}

function cancelAmountEdit(): void {
    editingAmount.value = false;
    amountError.value = undefined;
}

function saveAmount(): void {
    const normalized = normalizeAmount(amountDraft.value);

    if (normalized === null) {
        amountError.value = 'Solo números, por ejemplo 3000 o 3,000.50.';

        return;
    }

    const current = outcome.value?.amount ?? null;

    if (
        (normalized === '' && current === null) ||
        (normalized !== '' &&
            current !== null &&
            Number(normalized) === Number(current))
    ) {
        cancelAmountEdit();

        return;
    }

    save(
        {
            outcome: 'approved',
            outcome_amount: normalized === '' ? null : normalized,
        },
        () => (editingAmount.value = false),
    );
}

function save(
    payload: {
        outcome: string | null;
        outcome_amount: string | null;
    },
    done: () => void = () => (open.value = false),
): void {
    router.put(updateOutcome.url(props.conversation.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            saving.value = true;
            error.value = undefined;
        },
        onSuccess: done,
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
        class="grid min-w-0 grid-cols-1 gap-1.5"
        data-request-outcome
    >
        <p class="text-xs text-muted-foreground">Resultado del crédito</p>
        <div class="flex flex-wrap items-center gap-x-1.5 gap-y-1">
            <span
                v-if="outcome?.value === 'approved'"
                class="inline-flex items-center gap-1 font-medium text-success-soft-foreground"
            >
                <BadgeCheck class="size-3.5 shrink-0" aria-hidden="true" />
                {{ outcome.label }}
                <span
                    v-if="outcome.amount !== null && !editingAmount"
                    class="font-semibold tabular-nums"
                    data-request-outcome-amount
                    >{{ formatMoney(outcome.amount) }}</span
                >
            </span>
            <form
                v-if="
                    outcome?.value === 'approved' && canChange && editingAmount
                "
                class="flex items-center gap-1"
                data-request-outcome-amount-form
                @submit.prevent="saveAmount"
            >
                <div class="relative">
                    <span
                        class="pointer-events-none absolute inset-y-0 left-2 grid place-items-center text-xs text-muted-foreground"
                        aria-hidden="true"
                        >$</span
                    >
                    <Input
                        ref="amountInput"
                        v-model="amountDraft"
                        inputmode="decimal"
                        autocomplete="off"
                        maxlength="16"
                        aria-label="Monto autorizado"
                        class="h-7 w-28 pl-5 text-sm font-semibold tabular-nums"
                        :aria-invalid="Boolean(amountError)"
                        :disabled="saving"
                        data-request-outcome-amount-input
                        @keydown.esc.stop.prevent="cancelAmountEdit"
                    />
                </div>
                <button
                    type="submit"
                    class="grid size-7 place-items-center rounded text-primary outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/40"
                    :disabled="saving"
                    aria-label="Guardar monto"
                    title="Guardar (Enter)"
                >
                    <Check class="size-4" aria-hidden="true" />
                </button>
                <button
                    type="button"
                    class="grid size-7 place-items-center rounded text-muted-foreground outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/40"
                    :disabled="saving"
                    aria-label="Cancelar"
                    title="Cancelar (Esc)"
                    @click="cancelAmountEdit"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </form>
            <button
                v-else-if="outcome?.value === 'approved' && canChange"
                type="button"
                class="inline-flex items-center gap-1 rounded text-xs font-medium text-muted-foreground outline-none hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/40"
                :disabled="saving"
                title="Corregir el monto autorizado (error de dedo)"
                data-request-outcome-amount-edit
                @click="startAmountEdit"
            >
                <Pencil class="size-3" aria-hidden="true" />
                {{
                    outcome.amount !== null ? 'Corregir monto' : 'Agregar monto'
                }}
            </button>
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
        </div>

        <p
            v-if="amountError && editingAmount"
            class="text-xs text-destructive"
            role="alert"
        >
            {{ amountError }}
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
