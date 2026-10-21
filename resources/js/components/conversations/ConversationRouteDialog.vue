<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
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
import type {
    ConversationSummary,
    TransferAgentOption,
    TransferOptions,
} from '@/types';

/**
 * Assigns the conversation to an agent of its department, or transfers it to
 * another department and/or agent. The destinations come from the server
 * (only active, authorized departments and people who can attend them) and
 * are loaded when the dialog opens, so they are always current.
 */
const props = defineProps<{
    mode: 'assign' | 'transfer';
    conversation: ConversationSummary;
    options: TransferOptions | undefined;
    loading: boolean;
    processing: boolean;
    reasonRequired: boolean;
    errors: Record<string, string | undefined>;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    submit: [
        payload: {
            department_id: number | null;
            user_id: number | null;
            reason: string;
        },
    ];
}>();

const NO_AGENT = '';

const departmentId = ref<string>('');
const userId = ref<string>(NO_AGENT);
const reason = ref('');

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            departmentId.value = props.conversation.department
                ? String(props.conversation.department.id)
                : '';
            userId.value = NO_AGENT;
            reason.value = '';
        }
    },
    { immediate: true },
);

watch(departmentId, () => (userId.value = NO_AGENT));

const agents = computed<TransferAgentOption[]>(() => {
    if (!props.options) {
        return [];
    }

    if (departmentId.value === '') {
        return props.options.unrouted_agents;
    }

    return (
        props.options.departments.find(
            (department) => String(department.id) === departmentId.value,
        )?.agents ?? []
    );
});

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const isTransfer = computed(() => props.mode === 'transfer');

const canSubmit = computed(() => {
    if (props.processing || props.loading) {
        return false;
    }

    if (!isTransfer.value) {
        return userId.value !== NO_AGENT;
    }

    if (props.reasonRequired && reason.value.trim() === '') {
        return false;
    }

    return departmentId.value !== '' || userId.value !== NO_AGENT;
});

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    const department =
        departmentId.value === '' ? null : Number(departmentId.value);

    emit('submit', {
        department_id:
            isTransfer.value &&
            department !== (props.conversation.department?.id ?? null)
                ? department
                : null,
        user_id: userId.value === NO_AGENT ? null : Number(userId.value),
        reason: reason.value.trim(),
    });
}

function agentLabel(agent: TransferAgentOption): string {
    const availability =
        agent.availability === 'available' ? 'disponible' : 'no disponible';

    return `${agent.name} · ${availability} · ${agent.load} en atención`;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <form class="grid gap-4" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            isTransfer
                                ? 'Transferir conversación'
                                : 'Asignar conversación'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            isTransfer
                                ? 'Se conservan el contacto, los mensajes, las notas, las etiquetas y la procedencia. No se envía ningún mensaje al cliente.'
                                : 'La persona elegida será responsable de atenderla dentro de su departamento.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="loading && !options"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner /> Cargando destinos disponibles…
                </p>

                <template v-else>
                    <FormField
                        v-if="isTransfer"
                        id="transfer-department"
                        label="Departamento de destino"
                        :error="errors.department_id"
                        :help="{
                            description:
                                'Cola a la que pasará la conversación. Solo aparecen departamentos activos autorizados para la conexión.',
                            howTo: 'Deja el departamento actual para cambiar solo de agente.',
                        }"
                    >
                        <select
                            id="transfer-department"
                            v-model="departmentId"
                            :class="selectClass"
                        >
                            <option v-if="!conversation.department" value="">
                                Sin departamento
                            </option>
                            <option
                                v-for="department in options?.departments ?? []"
                                :key="department.id"
                                :value="String(department.id)"
                            >
                                {{ department.name
                                }}{{
                                    department.id ===
                                    conversation.department?.id
                                        ? ' (actual)'
                                        : ''
                                }}
                            </option>
                        </select>
                    </FormField>

                    <FormField
                        id="route-agent"
                        :label="isTransfer ? 'Agente que la recibe' : 'Agente'"
                        :error="errors.user_id"
                        :help="{
                            description:
                                'Persona que quedará como responsable. Solo aparecen agentes activos que pueden atender el departamento elegido, con su disponibilidad y su carga actual.',
                            howTo: isTransfer
                                ? 'Elige «Sin agente» para dejarla pendiente en la cola del departamento.'
                                : 'Elige a quien atenderá la conversación.',
                        }"
                        :hint="
                            agents.length === 0
                                ? 'No hay personas que puedas elegir en este departamento.'
                                : undefined
                        "
                    >
                        <select
                            id="route-agent"
                            v-model="userId"
                            :class="selectClass"
                        >
                            <option :value="NO_AGENT">
                                {{
                                    isTransfer
                                        ? 'Sin agente: queda pendiente en la cola'
                                        : 'Elige un agente'
                                }}
                            </option>
                            <option
                                v-for="agent in agents"
                                :key="agent.id"
                                :value="String(agent.id)"
                                :disabled="
                                    agent.id === conversation.assignee?.id &&
                                    String(
                                        conversation.department?.id ?? '',
                                    ) === departmentId
                                "
                            >
                                {{ agentLabel(agent) }}
                            </option>
                        </select>
                    </FormField>

                    <FormField
                        v-if="isTransfer"
                        id="transfer-reason"
                        :label="reasonRequired ? 'Motivo' : 'Motivo (opcional)'"
                        :error="errors.reason"
                        hint="Queda en el historial interno; el cliente no lo ve."
                    >
                        <Textarea
                            id="transfer-reason"
                            v-model="reason"
                            rows="2"
                            maxlength="255"
                            :required="reasonRequired"
                        />
                    </FormField>
                </template>

                <InputError :message="errors.conversation" />

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="open = false"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="!canSubmit">
                        <Spinner v-if="processing" />
                        {{ isTransfer ? 'Transferir' : 'Asignar' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
