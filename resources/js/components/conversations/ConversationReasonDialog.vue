<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
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
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ confirm: [reason: string] }>();

/** Local draft: the parent sends the request and passes back its error. */
const form = useForm({ reason: '' });

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

    emit('confirm', form.reason.trim());
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
                        :variant="destructive ? 'destructive' : 'default'"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" />
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
