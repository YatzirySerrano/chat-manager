<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { BadgeCheck, RotateCcw } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength, required, when } from '@/lib/validation';

/**
 * Confirms an operation that may carry a reason (hold, release, close).
 * The reason is kept in the conversation's history and never sent to the
 * customer.
 */
const props = defineProps<{
    title: string;
    description: string;
    confirmLabel: string;
    reasonLabel?: string;
    reasonRequired?: boolean;
    destructive?: boolean;
    processing?: boolean;
    error?: string;
    /** Offers «La solicitud fue completada» (closing only). */
    completedOption?: boolean;
    /** Opens with «La solicitud fue completada» already ticked. */
    completedDefault?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ confirm: [reason: string, completed: boolean] }>();

/** Local draft: the parent sends the request and passes back its error. */
const form = useForm({ reason: '' });
const completed = ref(false);
/* Closing as completed: the confirmation takes the success tone. */
const completing = computed(
    () => Boolean(props.completedOption) && completed.value,
);

const validation = useFormValidation(form, {
    rules: () => ({
        reason: [
            when(
                () => Boolean(props.reasonRequired),
                [required('Escribe el motivo.')],
            ),
            maxLength(255),
        ],
    }),
});

watch(open, (isOpen) => {
    if (isOpen) {
        validation.load({ reason: '' });
        completed.value = Boolean(props.completedDefault);
    }
});

watch(
    () => props.error,
    (message) => {
        if (message) {
            form.setError('reason', message);
        } else {
            form.clearErrors('reason');
        }
    },
);

function submit(): void {
    if (props.processing || !validation.validate()) {
        return;
    }

    emit(
        'confirm',
        form.reason.trim(),
        props.completedOption ? completed.value : false,
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-md">
            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>{{ description }}</DialogDescription>
                </DialogHeader>
                <FormField
                    v-if="reasonLabel"
                    id="conversation-reason"
                    :label="
                        reasonRequired
                            ? reasonLabel
                            : `${reasonLabel} (opcional)`
                    "
                    :error="validation.error('reason')"
                    hint="Queda en el historial interno; el cliente no lo ve."
                >
                    <Textarea
                        id="conversation-reason"
                        v-model="form.reason"
                        rows="3"
                        maxlength="255"
                        :required="reasonRequired"
                        v-bind="
                            validation.field('reason', 'conversation-reason')
                        "
                    />
                </FormField>
                <label
                    v-if="completedOption"
                    for="conversation-completed"
                    class="group/done flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-[background-color,border-color,box-shadow] duration-(--duration-base) hover:bg-muted/50 has-[[data-state=checked]]:border-success/50 has-[[data-state=checked]]:bg-success-soft/70 has-[[data-state=checked]]:shadow-(--shadow-control)"
                >
                    <Checkbox
                        id="conversation-completed"
                        v-model="completed"
                        class="mt-0.5"
                        data-conversation-completed
                    />
                    <span class="grid gap-1">
                        <span
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            <BadgeCheck
                                class="size-4 text-success transition-transform duration-(--duration-panel) ease-(--ease-spring) group-has-[[data-state=checked]]/done:scale-125"
                                aria-hidden="true"
                            />
                            La solicitud fue completada
                        </span>
                        <span
                            class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            title="Si este número vuelve a escribir, no se reabre esta conversación: entra como solicitud nueva a «Pendientes»."
                        >
                            <RotateCcw
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            Si vuelve a escribir, entra como nueva
                        </span>
                    </span>
                </label>
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
                        :variant="
                            destructive && !completing
                                ? 'destructive'
                                : 'default'
                        "
                        :class="
                            completing &&
                            'bg-success bg-none text-background hover:shadow-[inset_0_1px_0_rgb(255_255_255/0.22),0_6px_16px_-6px_color-mix(in_oklch,var(--success)_70%,transparent)]'
                        "
                        :disabled="processing"
                        :loading="processing"
                    >
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
