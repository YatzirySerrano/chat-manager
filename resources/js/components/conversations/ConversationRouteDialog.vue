<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength, required, when } from '@/lib/validation';
import type {
    ConversationSummary,
    TransferAgentOption,
    TransferOptions,
} from '@/types';

/**
 * Assigns the conversation to an agent of its department, or transfers it to
 * another department and/or agent, or leaves it in a department's queue.
 * The destinations come from the server (only active, authorized departments
 * and the people who can attend each one, without needing to see their
 * conversations) and are loaded when the dialog opens, so they are current.
 *
 * Each agent shows their availability and whether they already attend their
 * maximum of conversations. Choosing such an agent is an exception reserved
 * to users who can assign: the dialog warns about it and requires an
 * explicit confirmation, which the server checks again and records. Anyone
 * else sees those agents disabled and can still use the department queue.
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
            confirm_unavailable: boolean;
            confirm_over_capacity: boolean;
        },
    ];
}>();

const NO_AGENT = '';

const departmentId = ref<string>('');
const userId = ref<string>(NO_AGENT);
const confirmUnavailable = ref(false);
const confirmOverCapacity = ref(false);

/** Local draft of the reason: the parent sends the request. */
const reasonForm = useForm({ reason: '' });

const validation = useFormValidation(reasonForm, {
    rules: () => ({
        reason: [
            when(
                () => props.mode === 'transfer' && props.reasonRequired,
                [required('Escribe el motivo de la transferencia.')],
            ),
            maxLength(255),
        ],
    }),
});

watch(
    () => props.errors.reason,
    (message) => {
        if (message) {
            reasonForm.setError('reason', message);
        } else {
            reasonForm.clearErrors('reason');
        }
    },
);

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            departmentId.value = props.conversation.department
                ? String(props.conversation.department.id)
                : '';
            userId.value = NO_AGENT;
            validation.load({ reason: '' });
            confirmUnavailable.value = false;
            confirmOverCapacity.value = false;
        }
    },
    { immediate: true },
);

watch(departmentId, () => (userId.value = NO_AGENT));
watch(userId, () => {
    confirmUnavailable.value = false;
    confirmOverCapacity.value = false;
});

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

const selectedAgent = computed<TransferAgentOption | null>(
    () =>
        agents.value.find((agent) => String(agent.id) === userId.value) ?? null,
);

const selectedIsUnavailable = computed(
    () => selectedAgent.value?.availability === 'unavailable',
);

/**
 * Whether handing it to the agent goes past their limit. Keeping the current
 * responsible (e.g. moving it to another department) adds no load, so the
 * backend does not apply the limit to them.
 */
function exceedsCapacity(agent: TransferAgentOption): boolean {
    return agent.at_capacity && agent.id !== props.conversation.assignee?.id;
}

const selectedIsAtCapacity = computed(
    () => selectedAgent.value !== null && exceedsCapacity(selectedAgent.value),
);

const allowsExceptions = computed(
    () => props.options?.allows_exceptions === true,
);

/** Whether choosing the agent would be an exception (unavailable or at their limit). */
function isException(agent: TransferAgentOption): boolean {
    return agent.availability === 'unavailable' || exceedsCapacity(agent);
}

function isCurrentResponsible(agent: TransferAgentOption): boolean {
    return (
        agent.id === props.conversation.assignee?.id &&
        String(props.conversation.department?.id ?? '') === departmentId.value
    );
}

const hasBlockedAgents = computed(
    () =>
        !allowsExceptions.value &&
        agents.value.some(
            (agent) => isException(agent) && !isCurrentResponsible(agent),
        ),
);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const isTransfer = computed(() => props.mode === 'transfer');

const canSubmit = computed(() => {
    if (props.processing || props.loading) {
        return false;
    }

    if (selectedIsUnavailable.value && !confirmUnavailable.value) {
        return false;
    }

    if (selectedIsAtCapacity.value && !confirmOverCapacity.value) {
        return false;
    }

    if (!isTransfer.value) {
        return userId.value !== NO_AGENT;
    }

    return departmentId.value !== '' || userId.value !== NO_AGENT;
});

function submit(): void {
    if (!canSubmit.value || !validation.validate()) {
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
        reason: reasonForm.reason.trim(),
        confirm_unavailable:
            selectedIsUnavailable.value && confirmUnavailable.value,
        confirm_over_capacity:
            selectedIsAtCapacity.value && confirmOverCapacity.value,
    });
}

function agentLabel(agent: TransferAgentOption): string {
    const availability =
        agent.availability === 'available' ? 'Disponible' : 'No disponible';

    const capacity = agent.at_capacity ? ' · Al límite de carga' : '';

    return `${agent.name} · ${availability}${capacity}`;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <form class="grid gap-4" novalidate @submit.prevent="submit">
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
                                'Persona que quedará como responsable. Solo aparecen personas activas que pueden atender el departamento elegido, con su disponibilidad y si ya atienden su máximo de conversaciones.',
                            howTo: isTransfer
                                ? 'Elige «Sin agente» para dejarla pendiente en la cola del departamento.'
                                : 'Elige a quien atenderá la conversación.',
                        }"
                        :hint="
                            agents.length === 0
                                ? 'No hay personas que puedas elegir en este departamento.'
                                : hasBlockedAgents
                                  ? 'Quien está No disponible o al límite de carga solo puede recibirla de quien asigna conversaciones. Transfiérela a la cola del departamento.'
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
                                    isCurrentResponsible(agent) ||
                                    (isException(agent) && !allowsExceptions)
                                "
                            >
                                {{ agentLabel(agent) }}
                            </option>
                        </select>
                    </FormField>

                    <div
                        v-if="selectedAgent"
                        class="flex flex-wrap items-center gap-2 text-sm"
                        aria-live="polite"
                    >
                        <span class="font-medium">{{
                            selectedAgent.name
                        }}</span>
                        <Badge
                            :variant="
                                selectedIsUnavailable
                                    ? 'destructive'
                                    : 'secondary'
                            "
                        >
                            {{
                                selectedIsUnavailable
                                    ? 'No disponible'
                                    : 'Disponible'
                            }}
                        </Badge>
                        <Badge
                            v-if="selectedIsAtCapacity"
                            variant="destructive"
                        >
                            Al límite de carga
                        </Badge>
                    </div>

                    <div
                        v-if="selectedIsUnavailable"
                        class="grid gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        role="alert"
                    >
                        <p class="flex gap-2">
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span
                                >{{ selectedAgent?.name }} está como No
                                disponible: no acepta conversaciones nuevas ni
                                recibe asignaciones automáticas. Asignársela es
                                una excepción que quedará registrada en el
                                historial; su disponibilidad no cambiará.</span
                            >
                        </p>
                        <label
                            class="flex cursor-pointer items-center gap-2 font-medium"
                        >
                            <Checkbox
                                id="route-confirm-unavailable"
                                :model-value="confirmUnavailable"
                                @update:model-value="
                                    (value) =>
                                        (confirmUnavailable = value === true)
                                "
                            />
                            Confirmo que quiero asignársela aunque no esté
                            disponible
                        </label>
                    </div>

                    <div
                        v-if="selectedIsAtCapacity"
                        class="grid gap-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                        role="alert"
                    >
                        <p class="flex gap-2">
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span
                                >{{ selectedAgent?.name }} ya atiende el máximo
                                de conversaciones permitido. Asignársela supera
                                su límite de carga; es una excepción que quedará
                                registrada en el historial y su límite no
                                cambiará.</span
                            >
                        </p>
                        <label
                            class="flex cursor-pointer items-center gap-2 font-medium"
                        >
                            <Checkbox
                                id="route-confirm-over-capacity"
                                :model-value="confirmOverCapacity"
                                @update:model-value="
                                    (value) =>
                                        (confirmOverCapacity = value === true)
                                "
                            />
                            Confirmo que quiero asignársela aunque supere su
                            límite
                        </label>
                    </div>

                    <FormField
                        v-if="isTransfer"
                        id="transfer-reason"
                        :label="reasonRequired ? 'Motivo' : 'Motivo (opcional)'"
                        :error="validation.error('reason')"
                        hint="Queda en el historial interno; el cliente no lo ve."
                    >
                        <Textarea
                            id="transfer-reason"
                            v-model="reasonForm.reason"
                            rows="2"
                            maxlength="255"
                            :required="reasonRequired"
                            v-bind="
                                validation.field('reason', 'transfer-reason')
                            "
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
