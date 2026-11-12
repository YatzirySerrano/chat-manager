<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    QUEUE_RULE_CONSEQUENCES,
    QUEUE_RULE_LABELS,
} from '@/components/conversations/queueRules';
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
import { Textarea } from '@/components/ui/textarea';
import {
    conversationConflictFromErrors,
    withoutConflictCode,
} from '@/lib/conversations/actionErrors';
import { accept } from '@/routes/conversations';
import type { ConversationSummary } from '@/types';

/**
 * Justified exception to the queue rules (only for holders of
 * conversations.override-queue-rules): it names the rule being skipped and
 * what that means, requires a reason and accepts the conversation with it.
 * The server checks the permission again, records the exception in the
 * conversation's history and in the audit log, and still enforces the
 * capacity limit and the control of pending conversations.
 */
const props = defineProps<{
    conversation: ConversationSummary | null;
}>();

const emit = defineEmits<{ close: []; accepted: [id: number] }>();

const REASON_MIN = 10;

const form = useForm({ override_reason: '' });
const processing = ref(false);
const localError = ref<string | null>(null);

const open = computed({
    get: () => props.conversation !== null,
    set: (value: boolean) => {
        if (!value && !processing.value) {
            emit('close');
        }
    },
});

const code = computed(() => props.conversation?.acceptance?.code ?? null);
const ruleLabel = computed(() =>
    code.value ? (QUEUE_RULE_LABELS[code.value] ?? null) : null,
);
const consequence = computed(() =>
    code.value ? (QUEUE_RULE_CONSEQUENCES[code.value] ?? null) : null,
);

watch(
    () => props.conversation?.id ?? null,
    () => {
        form.override_reason = '';
        form.clearErrors();
        localError.value = null;
    },
);

function submit(): void {
    const conversation = props.conversation;

    if (conversation === null || processing.value) {
        return;
    }

    const reason = form.override_reason.trim();

    if (reason.length < REASON_MIN) {
        localError.value = `Explica el motivo de la excepción con al menos ${REASON_MIN} caracteres.`;

        return;
    }

    localError.value = null;

    router.post(
        accept.url(conversation.id),
        { override_reason: reason },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (processing.value = true),
            onSuccess: () => {
                emit('accepted', conversation.id);
                emit('close');
            },
            onError: (errors) => {
                if (errors.override_reason) {
                    localError.value = errors.override_reason;

                    return;
                }

                // Refused for another reason (taken meanwhile, capacity,
                // pending conversations): the list already shows the truth.
                toast.error(
                    conversationConflictFromErrors(errors)?.message ??
                        errors.conversation ??
                        Object.values(withoutConflictCode(errors))[0] ??
                        'No se pudo aceptar la conversación. Inténtalo de nuevo.',
                );
                emit('close');
            },
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto sm:max-w-md"
            data-queue-override-dialog
        >
            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Hacer una excepción</DialogTitle>
                    <DialogDescription>
                        Vas a aceptar la conversación con
                        {{ conversation?.contact.display_name }} aunque una
                        regla de la cola todavía no lo permite.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="grid gap-2 rounded-lg border border-warning/50 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
                >
                    <p class="flex gap-2 font-medium">
                        <ShieldAlert
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span
                            >Regla que se omite:
                            {{ ruleLabel ?? 'regla de la cola' }}</span
                        >
                    </p>
                    <p v-if="consequence">{{ consequence }}</p>
                    <ul class="list-disc pl-5 text-xs">
                        <li>
                            Si aplica más de una regla de la cola a esta
                            solicitud, se omiten todas y cada una queda
                            registrada.
                        </li>
                        <li>
                            Tu nombre, el motivo y las reglas omitidas quedan en
                            el historial de la conversación y en la auditoría.
                        </li>
                        <li>
                            No omite tu límite de conversaciones ni el control
                            de conversaciones pendientes de atención.
                        </li>
                    </ul>
                </div>

                <FormField
                    id="queue-override-reason"
                    label="Motivo de la excepción"
                    :error="localError ?? undefined"
                    hint="Obligatorio, al menos 10 caracteres. El cliente no lo ve."
                >
                    <Textarea
                        id="queue-override-reason"
                        v-model="form.override_reason"
                        rows="3"
                        maxlength="255"
                        required
                        :aria-invalid="localError ? 'true' : undefined"
                    />
                </FormField>

                <DialogFooter class="gap-2 pointer-coarse:*:h-10">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="emit('close')"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing"
                        :loading="processing"
                    >
                        Aceptar con excepción
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
