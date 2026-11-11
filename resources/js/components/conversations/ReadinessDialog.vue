<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConversationReasonDialog from '@/components/conversations/ConversationReasonDialog.vue';
import {
    conversationConflictFromErrors,
    withoutConflictCode,
} from '@/lib/conversations/actionErrors';
import { update as updateReadiness } from '@/routes/conversations/readiness';
import type { ConversationReadinessValue, ConversationSummary } from '@/types';

/**
 * «Confirmar solicitud completa» / «Marcar que necesita información», with
 * confirmation. Returning a request requires saying what is missing. The
 * server authorizes again under lock and records who changed it and why.
 */
const props = defineProps<{
    target: {
        conversation: ConversationSummary;
        readiness: ConversationReadinessValue;
    } | null;
}>();

const emit = defineEmits<{ close: [] }>();

const processing = ref(false);
const error = ref<string | undefined>(undefined);

const open = computed({
    get: () => props.target !== null,
    set: (value: boolean) => {
        if (!value && !processing.value) {
            error.value = undefined;
            emit('close');
        }
    },
});

const confirming = computed(() => props.target?.readiness === 'ready');

const copy = computed(() =>
    confirming.value
        ? {
              title: 'Confirmar solicitud completa',
              description:
                  'La solicitud quedará «Lista para atención» y entrará a la cola desde este momento: el tiempo mínimo de espera y el orden de llegada cuentan a partir de la confirmación.',
              confirm: 'Confirmar como completa',
              label: 'Comentario',
          }
        : {
              title: 'Marcar que necesita información',
              description:
                  'La solicitud quedará como «Necesita información». Donde la confirmación es obligatoria no podrá tomarse de la cola hasta que se confirme como completa. El cliente puede seguir escribiendo y no se pierde nada del historial.',
              confirm: 'Marcar que necesita información',
              label: 'Qué información falta',
          },
);

function submit(reason: string): void {
    const target = props.target;

    if (target === null || processing.value) {
        return;
    }

    if (!confirming.value && reason.length < 5) {
        error.value = 'Indica qué información falta (al menos 5 caracteres).';

        return;
    }

    router.post(
        updateReadiness.url(target.conversation.id),
        {
            readiness: target.readiness,
            reason: reason === '' ? null : reason,
            version: target.conversation.version,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                processing.value = true;
                error.value = undefined;
            },
            onSuccess: () => emit('close'),
            onError: (errors) => {
                if (errors.reason) {
                    error.value = errors.reason;

                    return;
                }

                toast.error(
                    conversationConflictFromErrors(errors)?.message ??
                        errors.conversation ??
                        Object.values(withoutConflictCode(errors))[0] ??
                        'No se pudo cambiar el estado de la solicitud. Inténtalo de nuevo.',
                );
                emit('close');
            },
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <ConversationReasonDialog
        v-model:open="open"
        :title="copy.title"
        :description="copy.description"
        :confirm-label="copy.confirm"
        :reason-label="copy.label"
        :reason-required="!confirming"
        :processing="processing"
        :error="error"
        @confirm="submit"
    />
</template>
