<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { BadgeCheck, CircleSlash, Sparkles, XCircle } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { RadioCards } from '@/components/ui/radio-cards';
import type { RadioCardOption } from '@/components/ui/radio-cards';
import { formatMoney, normalizeAmount } from '@/lib/outcomes';
import { PHONE_SHEET_DIALOG } from '@/lib/phoneSheet';
import { suggestion as suggestionRoute } from '@/routes/conversations/outcome';
import type {
    ConversationOutcome,
    ConversationOutcomeSuggestion,
    ConversationOutcomeValue,
} from '@/types';

/**
 * The credit result of a request: «Crédito aprobado» with its «Monto
 * autorizado», «Rechazada», or none yet. Opened when completing the
 * request (`complete`) and to correct it afterwards (`correct`).
 *
 * It is quick on purpose: the result the conversation already announces
 * (the team's «Se autoriza …» / «Solicitud rechazada» reply, or the
 * captured «Monto autorizado») is pre-selected as soon as it arrives, and
 * Enter confirms. Nothing is forced: «Sin resultado» completes the request
 * as before, and the result can be recorded later from the details panel.
 */
const props = defineProps<{
    conversationId: number;
    mode: 'complete' | 'correct';
    /** Completing sends the connection's farewell (complete mode only). */
    farewell?: boolean;
    /** The result recorded now (correct mode). */
    current?: ConversationOutcome | null;
    processing?: boolean;
    error?: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    confirm: [
        payload: {
            outcome: ConversationOutcomeValue | null;
            outcome_amount: string | null;
        },
    ];
}>();

type Choice = ConversationOutcomeValue | 'none';

const choice = ref<Choice>('none');
const amount = ref('');
const amountError = ref<string | undefined>(undefined);
/* The person chose something: a late suggestion never overrides it. */
const touched = ref(false);
const suggestion = ref<ConversationOutcomeSuggestion | null>(null);

const options = computed<RadioCardOption<Choice>[]>(() => [
    {
        value: 'approved',
        label: 'Crédito aprobado',
        description: 'Cuenta para la comisión',
        icon: BadgeCheck,
    },
    {
        value: 'rejected',
        label: 'Rechazada',
        description: 'Se atendió, pero no se aprobó',
        icon: XCircle,
    },
    {
        value: 'none',
        label: 'Sin resultado',
        description: 'Lo registras después',
        icon: CircleSlash,
    },
]);

const request = useHttp<
    Record<string, never>,
    { suggestion: ConversationOutcomeSuggestion | null }
>({});

function apply(value: Choice, money: string | null): void {
    choice.value = value;
    amount.value = value === 'approved' && money ? String(Number(money)) : '';
}

async function loadSuggestion(): Promise<void> {
    suggestion.value = null;
    request.cancel();

    try {
        const result = await request.get(
            suggestionRoute.url(props.conversationId),
        );

        if (!open.value) {
            return;
        }

        suggestion.value = result.suggestion;

        // Correcting a recorded result: the suggestion is only a hint.
        if (
            result.suggestion &&
            !touched.value &&
            !(props.mode === 'correct' && props.current)
        ) {
            apply(result.suggestion.outcome, result.suggestion.amount);
        }
    } catch {
        // Only a convenience: without it the person chooses by hand.
    }
}

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            request.cancel();

            return;
        }

        touched.value = false;
        amountError.value = undefined;
        apply(props.current?.value ?? 'none', props.current?.amount ?? null);
        void loadSuggestion();
    },
    { immediate: true },
);

/* Enter confirms: the dialog opens with the main button focused. */
function focusConfirm(event: Event): void {
    event.preventDefault();
    void nextTick(() =>
        document
            .querySelector<HTMLButtonElement>('[data-outcome-confirm]')
            ?.focus(),
    );
}

watch(choice, (value) => {
    if (value === 'approved') {
        void nextTick(() => document.getElementById('outcome-amount')?.focus());
    }
});

function pick(): void {
    touched.value = true;
}

const suggestionText = computed(() => {
    const found = suggestion.value;

    if (!found) {
        return null;
    }

    const result =
        found.outcome === 'approved' && found.amount
            ? `${found.label} por ${formatMoney(found.amount)}`
            : found.label;

    return found.source === 'message'
        ? `Sugerido por el mensaje «${found.excerpt ?? ''}»: ${result}.`
        : `Sugerido por el «Monto autorizado» de los datos de la solicitud: ${result}.`;
});

const title = computed(() =>
    props.mode === 'complete'
        ? 'Solicitud completada'
        : 'Resultado de la solicitud',
);

const description = computed(() =>
    props.mode === 'complete'
        ? `¿Cuál fue el resultado del crédito? ${props.farewell ? 'Se envía al cliente el mensaje de despedida.' : 'No se envía ningún mensaje al cliente.'} Si vuelve a escribir, entra como solicitud nueva.`
        : 'Corrige el resultado si cambió o se registró mal. Queda en el historial de la conversación.',
);

const confirmLabel = computed(() =>
    props.mode === 'complete' ? 'Completar' : 'Guardar resultado',
);

function submit(): void {
    if (props.processing) {
        return;
    }

    let money: string | null = null;

    if (choice.value === 'approved') {
        const normalized = normalizeAmount(amount.value);

        if (normalized === null) {
            amountError.value =
                'Escribe el monto solo con números, por ejemplo 3000 o 3000.50.';

            return;
        }

        money = normalized === '' ? null : normalized;
    }

    amountError.value = undefined;
    emit('confirm', {
        outcome: choice.value === 'none' ? null : choice.value,
        outcome_amount: money,
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :class="[
                'max-h-[90dvh] overflow-y-auto sm:max-w-lg',
                PHONE_SHEET_DIALOG,
            ]"
            data-request-outcome-dialog
            @open-auto-focus="focusConfirm"
        >
            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>

                <RadioCards
                    v-model="choice"
                    :options="options"
                    aria-label="Resultado del crédito"
                    class="[--col-min:9.5rem]"
                    :disabled="processing"
                    data-outcome-choice
                    @change="pick"
                />

                <p
                    v-if="suggestionText"
                    class="flex items-start gap-1.5 text-xs text-muted-foreground"
                    data-outcome-suggestion
                >
                    <Sparkles
                        class="mt-0.5 size-3.5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span class="min-w-0 wrap-anywhere">{{
                        suggestionText
                    }}</span>
                </p>

                <FormField
                    v-if="choice === 'approved'"
                    id="outcome-amount"
                    label="Monto autorizado (opcional)"
                    :error="amountError ?? error"
                    hint="En pesos, por ejemplo 3000 o 3,000.50."
                >
                    <div class="relative">
                        <span
                            class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-sm text-muted-foreground"
                            aria-hidden="true"
                            >$</span
                        >
                        <Input
                            id="outcome-amount"
                            v-model="amount"
                            inputmode="decimal"
                            autocomplete="off"
                            maxlength="16"
                            class="pl-7"
                            :aria-invalid="Boolean(amountError ?? error)"
                            data-outcome-amount
                            @input="pick"
                        />
                    </div>
                </FormField>
                <p
                    v-else-if="error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ error }}
                </p>

                <DialogFooter class="gap-2 pointer-coarse:*:h-10">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="open = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :class="
                            mode === 'complete' &&
                            'bg-success bg-none text-background'
                        "
                        :disabled="processing"
                        :loading="processing"
                        data-outcome-confirm
                    >
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
