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
 * Completing is one click: three big buttons complete right away
 * («Crédito aprobado · $3,000», «Rechazada», «Sin resultado»). The amount
 * comes from the «Monto autorizado» of the request data (typed in
 * «Resolución» or taken from the authorization sent) or from the
 * suggestion, and is shown on the button. The suggested result (the
 * team's «Se autoriza …» / «Solicitud rechazada» reply, or the captured
 * amount) is highlighted and focused, so Enter completes with it. Only an
 * approval with no known amount offers a small optional amount field
 * beside it, which never blocks completing.
 *
 * Correcting keeps the choice, the amount field and «Guardar resultado».
 * Nothing is forced: the result can be recorded later from the details
 * panel.
 */
const props = defineProps<{
    conversationId: number;
    mode: 'complete' | 'correct';
    /** Completing sends the connection's farewell (complete mode only). */
    farewell?: boolean;
    /** The result recorded now (correct mode). */
    current?: ConversationOutcome | null;
    /** «Monto autorizado» of the request data, as written («$3,000»). */
    authorizedAmount?: string | null;
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
/* Complete mode: the optional amount of an approval with none known. */
const quickAmount = ref('');
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
        quickAmount.value = '';
        apply(props.current?.value ?? 'none', props.current?.amount ?? null);
        void loadSuggestion();
    },
    { immediate: true },
);

/* Complete mode: the amount already known, as the server expects it («3000»). */
const knownAmount = computed<string | null>(() => {
    const typed = props.authorizedAmount
        ? normalizeAmount(props.authorizedAmount)
        : null;

    if (typed) {
        return typed;
    }

    const found = suggestion.value;

    return found?.outcome === 'approved' && found.amount
        ? String(Number(found.amount))
        : null;
});

/* What Enter completes with: the suggestion, an approval with a known amount, or none. */
const suggested = computed<Choice>(() => {
    if (suggestion.value) {
        return suggestion.value.outcome;
    }

    return knownAmount.value ? 'approved' : 'none';
});

function focusQuick(value: Choice): void {
    void nextTick(() =>
        document
            .querySelector<HTMLButtonElement>(`[data-outcome-quick="${value}"]`)
            ?.focus(),
    );
}

/* Enter confirms: the dialog opens with the main (or suggested) button focused. */
function focusConfirm(event: Event): void {
    event.preventDefault();

    if (props.mode === 'complete') {
        focusQuick(suggested.value);

        return;
    }

    void nextTick(() =>
        document
            .querySelector<HTMLButtonElement>('[data-outcome-confirm]')
            ?.focus(),
    );
}

/* A late suggestion moves the focus to it, unless the person already chose. */
watch(suggested, (value) => {
    if (open.value && props.mode === 'complete' && !touched.value) {
        focusQuick(value);
    }
});

watch(choice, (value) => {
    if (props.mode === 'correct' && value === 'approved') {
        void nextTick(() => document.getElementById('outcome-amount')?.focus());
    }
});

function pick(): void {
    touched.value = true;
}

const approvedLabel = computed(() =>
    knownAmount.value
        ? `Crédito aprobado · ${formatMoney(knownAmount.value)}`
        : 'Crédito aprobado',
);

const quickChoices = computed(() =>
    options.value.map((option) => ({
        ...option,
        label: option.value === 'approved' ? approvedLabel.value : option.label,
    })),
);

function quickClass(value: Choice): string[] {
    return [
        'relative flex w-full min-w-0 items-center gap-3 rounded-lg border bg-card px-4 py-3 text-left text-sm outline-none transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/45 disabled:pointer-events-none disabled:opacity-50 pointer-coarse:min-h-12',
        suggested.value === value
            ? 'border-primary ring-1 ring-primary'
            : 'border-input',
    ];
}

/* One click completes: an approval carries the known (or typed) amount. */
function complete(value: Choice): void {
    if (props.processing) {
        return;
    }

    let money: string | null = null;

    if (value === 'approved') {
        if (knownAmount.value) {
            money = knownAmount.value;
        } else {
            const typed = normalizeAmount(quickAmount.value);

            if (typed === null) {
                amountError.value =
                    'Escribe el monto solo con números, por ejemplo 3000, o déjalo vacío.';

                return;
            }

            money = typed === '' ? null : typed;
        }
    }

    amountError.value = undefined;
    emit('confirm', {
        outcome: value === 'none' ? null : value,
        outcome_amount: money,
    });
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
        ? `Elige el resultado del crédito y se completa. ${props.farewell ? 'Se envía al cliente el mensaje de despedida.' : 'No se envía ningún mensaje al cliente.'} Si vuelve a escribir, entra como solicitud nueva.`
        : 'Corrige el resultado si cambió o se registró mal. Queda en el historial de la conversación.',
);

function submit(): void {
    if (props.processing) {
        return;
    }

    if (props.mode === 'complete') {
        complete(suggested.value);

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

                <div
                    v-if="mode === 'complete'"
                    class="grid gap-2"
                    role="group"
                    aria-label="Resultado del crédito"
                    data-outcome-quick-choices
                >
                    <div
                        v-for="option in quickChoices"
                        :key="option.value"
                        class="flex flex-col gap-2 sm:flex-row sm:items-stretch"
                    >
                        <button
                            type="button"
                            :class="quickClass(option.value)"
                            :disabled="processing"
                            :data-outcome-quick="option.value"
                            :aria-describedby="
                                suggested === option.value
                                    ? `outcome-suggested-${conversationId}`
                                    : undefined
                            "
                            @click="complete(option.value)"
                        >
                            <component
                                :is="option.icon"
                                :class="[
                                    'size-5 shrink-0',
                                    option.value === 'approved'
                                        ? 'text-success'
                                        : option.value === 'rejected'
                                          ? 'text-destructive'
                                          : 'text-muted-foreground',
                                ]"
                                aria-hidden="true"
                            />
                            <span class="grid min-w-0">
                                <span class="font-semibold wrap-anywhere">{{
                                    option.label
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    option.description
                                }}</span>
                            </span>
                            <span
                                v-if="suggested === option.value"
                                :id="`outcome-suggested-${conversationId}`"
                                class="ml-auto shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                >Sugerido</span
                            >
                        </button>
                        <div
                            v-if="option.value === 'approved' && !knownAmount"
                            class="relative sm:w-32"
                            data-outcome-quick-amount
                        >
                            <span
                                class="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-sm text-muted-foreground"
                                aria-hidden="true"
                                >$</span
                            >
                            <Input
                                v-model="quickAmount"
                                inputmode="decimal"
                                autocomplete="off"
                                maxlength="16"
                                placeholder="Monto"
                                aria-label="Monto autorizado (opcional)"
                                class="h-full min-h-10 pl-7"
                                :aria-invalid="Boolean(amountError)"
                                :disabled="processing"
                                @input="pick"
                                @keydown.enter.prevent="complete('approved')"
                            />
                        </div>
                    </div>
                </div>

                <RadioCards
                    v-else
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
                    v-if="mode === 'correct' && choice === 'approved'"
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
                    v-else-if="amountError ?? error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ amountError ?? error }}
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
                        v-if="mode === 'correct'"
                        type="submit"
                        :disabled="processing"
                        :loading="processing"
                        data-outcome-confirm
                    >
                        Guardar resultado
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
