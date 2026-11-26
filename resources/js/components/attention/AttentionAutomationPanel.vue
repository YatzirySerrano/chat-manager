<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { update as updateAutomation } from '@/routes/attention/automation';
import type {
    AttentionAutomationLevel,
    AttentionAutomationSettings,
    AttentionEscalationStep,
    AttentionOption,
} from '@/types';

/**
 * Configuration of «Alertas automáticas»: the escalation steps of the two
 * time alerts (Pendientes and En atención) and the «Solicitud completada»
 * broadcast. Only mounted for whoever holds update-settings; saves on its
 * own, the existing flash-toast system shows the confirmation.
 */
const props = defineProps<{
    automation: AttentionAutomationSettings;
    options: {
        alert_levels: AttentionOption<AttentionAutomationLevel>[];
        message_max: number;
        max_steps: number;
    };
}>();

/** The message travels empty instead of null locally (plain text inputs); the server treats both the same. */
type StepForm = {
    minutes: number;
    priority: AttentionAutomationLevel;
    message: string;
};

type AutomationForm = {
    queue_enabled: boolean;
    queue_steps: StepForm[];
    attention_enabled: boolean;
    attention_steps: StepForm[];
    completed_enabled: boolean;
    completed_priority: AttentionAutomationLevel;
    completed_message: string;
};

type StepsKey = 'queue_steps' | 'attention_steps';
type EnabledKey = 'queue_enabled' | 'attention_enabled';

function cloneStep(step: AttentionEscalationStep): StepForm {
    return {
        minutes: step.minutes,
        priority: step.priority,
        message: step.message ?? '',
    };
}

function cloneSettings(): AutomationForm {
    return {
        queue_enabled: props.automation.queue_enabled,
        queue_steps: props.automation.queue_steps.map(cloneStep),
        attention_enabled: props.automation.attention_enabled,
        attention_steps: props.automation.attention_steps.map(cloneStep),
        completed_enabled: props.automation.completed_enabled,
        completed_priority: props.automation.completed_priority,
        completed_message: props.automation.completed_message ?? '',
    };
}

const form = useForm<AutomationForm>(cloneSettings());

/* The server sorts and de-duplicates the steps by minutes; once saved (and
 * not mid-edit) the local copy follows that normalized shape. */
watch(
    () => props.automation,
    () => {
        if (!form.isDirty) {
            form.defaults(cloneSettings());
            form.reset();
        }
    },
    { deep: true },
);

const sections: Array<{
    key: StepsKey;
    enabledKey: EnabledKey;
    title: string;
    help: string;
    idPrefix: string;
}> = [
    {
        key: 'queue_steps',
        enabledKey: 'queue_enabled',
        title: 'Solicitudes esperando en «Pendientes»',
        help: 'Avisa a quien puede tomarlas cuando una solicitud lista lleva demasiado tiempo esperando.',
        idPrefix: 'automation-queue',
    },
    {
        key: 'attention_steps',
        enabledKey: 'attention_enabled',
        title: 'Conversaciones «En atención»',
        help: 'Avisa al agente cuando lleva demasiado tiempo con una conversación sin resolverla, contando desde que la aceptó.',
        idPrefix: 'automation-attention',
    },
];

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function stepError(
    key: StepsKey,
    index: number,
    field: 'minutes' | 'priority' | 'message',
): string | undefined {
    return errors.value[`${key}.${index}.${field}`];
}

function addStep(key: StepsKey): void {
    if (form[key].length >= props.options.max_steps) {
        return;
    }

    form[key].push({ minutes: 5, priority: 'urgent', message: '' });
}

function removeStep(key: StepsKey, index: number): void {
    if (form[key].length <= 1) {
        return;
    }

    form[key].splice(index, 1);
}

function submit(): void {
    form.put(updateAutomation.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(cloneSettings());
            form.reset();
        },
    });
}
</script>

<template>
    <SurfaceCard
        title="Alertas automáticas"
        description="Estos avisos se envían solos por tiempo de respuesta. Se entregan en la campana de notificaciones y como notificación push del navegador."
        data-attention-automation-panel
    >
        <div class="grid gap-6 px-4 py-4 sm:px-5">
            <fieldset
                v-for="section in sections"
                :key="section.key"
                class="grid gap-3 border-b pb-6 last:border-b-0 last:pb-0"
            >
                <label
                    :for="`${section.idPrefix}-enabled`"
                    class="flex min-w-0 cursor-pointer items-start gap-3"
                >
                    <Switch
                        :id="`${section.idPrefix}-enabled`"
                        v-model="form[section.enabledKey]"
                        class="mt-0.5"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">
                            {{ section.title }}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ section.help }}
                        </span>
                    </span>
                </label>
                <legend class="sr-only">{{ section.title }}</legend>

                <div
                    :class="
                        cn(
                            'grid gap-2',
                            !form[section.enabledKey] &&
                                'pointer-events-none opacity-50',
                        )
                    "
                >
                    <div
                        v-for="(step, index) in form[section.key]"
                        :key="index"
                        class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[6rem_11rem_1fr_auto] sm:items-start"
                    >
                        <div class="grid gap-1">
                            <Label :for="`${section.idPrefix}-minutes-${index}`">
                                Minutos
                            </Label>
                            <Input
                                :id="`${section.idPrefix}-minutes-${index}`"
                                v-model.number="step.minutes"
                                type="number"
                                min="1"
                                max="1440"
                                inputmode="numeric"
                                :disabled="!form[section.enabledKey]"
                            />
                            <InputError
                                :message="stepError(section.key, index, 'minutes')"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`${section.idPrefix}-priority-${index}`">
                                Prioridad
                            </Label>
                            <Select
                                v-model="step.priority"
                                :disabled="!form[section.enabledKey]"
                            >
                                <SelectTrigger
                                    :id="`${section.idPrefix}-priority-${index}`"
                                    class="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="level in options.alert_levels"
                                        :key="level.value"
                                        :value="level.value"
                                    >
                                        {{ level.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError
                                :message="stepError(section.key, index, 'priority')"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Label :for="`${section.idPrefix}-message-${index}`">
                                Mensaje (opcional)
                            </Label>
                            <Input
                                :id="`${section.idPrefix}-message-${index}`"
                                v-model="step.message"
                                :maxlength="options.message_max"
                                placeholder="Deja vacío para el texto estándar"
                                :disabled="!form[section.enabledKey]"
                            />
                            <InputError
                                :message="stepError(section.key, index, 'message')"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="justify-self-end sm:mt-5"
                            :disabled="
                                form[section.key].length <= 1 ||
                                !form[section.enabledKey]
                            "
                            :aria-label="`Quitar escalón ${index + 1} de ${section.title}`"
                            @click="removeStep(section.key, index)"
                        >
                            <Trash2 />
                        </Button>
                    </div>

                    <InputError :message="errors[section.key]" />

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="
                                !form[section.enabledKey] ||
                                form[section.key].length >= options.max_steps
                            "
                            @click="addStep(section.key)"
                        >
                            <Plus />
                            Agregar escalón
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Ejemplo: 5 min, luego 10 min, luego 15 min. Cada
                            escalón avisa una sola vez.
                        </p>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grid gap-3">
                <label
                    for="automation-completed-enabled"
                    class="flex min-w-0 cursor-pointer items-start gap-3"
                >
                    <Switch
                        id="automation-completed-enabled"
                        v-model="form.completed_enabled"
                        class="mt-0.5"
                    />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">
                            Solicitud marcada como completada
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            Avisa a todo el equipo que puede ver la
                            conversación cuando alguien marca una solicitud
                            como completada.
                        </span>
                    </span>
                </label>
                <legend class="sr-only">
                    Solicitud marcada como completada
                </legend>

                <div
                    :class="
                        cn(
                            'grid gap-3 sm:grid-cols-2',
                            !form.completed_enabled &&
                                'pointer-events-none opacity-50',
                        )
                    "
                >
                    <div class="grid gap-1">
                        <Label for="automation-completed-priority">
                            Prioridad
                        </Label>
                        <Select
                            v-model="form.completed_priority"
                            :disabled="!form.completed_enabled"
                        >
                            <SelectTrigger
                                id="automation-completed-priority"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="level in options.alert_levels"
                                    :key="level.value"
                                    :value="level.value"
                                >
                                    {{ level.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="errors.completed_priority" />
                    </div>
                    <div class="grid gap-1 sm:col-span-2">
                        <Label for="automation-completed-message">
                            Mensaje (opcional)
                        </Label>
                        <Textarea
                            id="automation-completed-message"
                            v-model="form.completed_message"
                            rows="3"
                            :maxlength="options.message_max"
                            placeholder="Deja vacío para el texto estándar"
                            :disabled="!form.completed_enabled"
                        />
                        <p
                            class="text-right text-xs text-muted-foreground tabular-nums"
                        >
                            {{ form.completed_message.length }} /
                            {{ options.message_max }}
                        </p>
                        <InputError :message="errors.completed_message" />
                    </div>
                </div>
            </fieldset>
        </div>

        <template #footer>
            <Button
                type="button"
                :disabled="form.processing"
                :loading="form.processing"
                @click="submit"
            >
                Guardar alertas automáticas
            </Button>
        </template>
    </SurfaceCard>
</template>
