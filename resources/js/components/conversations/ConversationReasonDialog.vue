<script setup lang="ts">
import { ref, watch } from 'vue';
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

const reason = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        reason.value = '';
    }
});

function submit(): void {
    if (props.reasonRequired && reason.value.trim() === '') {
        return;
    }

    emit('confirm', reason.value.trim());
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="grid gap-4" @submit.prevent="submit">
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
                    :error="error"
                    hint="Queda en el historial interno; el cliente no lo ve."
                >
                    <Textarea
                        id="conversation-reason"
                        v-model="reason"
                        rows="3"
                        maxlength="255"
                        :required="reasonRequired"
                    />
                </FormField>
                <DialogFooter class="gap-2">
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
                        :disabled="
                            processing ||
                            (reasonRequired && reason.trim() === '')
                        "
                    >
                        <Spinner v-if="processing" />
                        {{ confirmLabel }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
