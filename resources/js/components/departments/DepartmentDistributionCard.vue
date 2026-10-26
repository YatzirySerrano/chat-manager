<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/departments/distribution';
import type { DepartmentSummary, DistributionModeValue } from '@/types';

const props = defineProps<{
    department: DepartmentSummary;
    modes: {
        value: DistributionModeValue;
        label: string;
        description: string;
    }[];
    canUpdate: boolean;
}>();

/** Empty string means "inherit the platform limit". */
function currentValues(): {
    distribution_mode: DistributionModeValue;
    max_open_conversations_per_agent: number | '';
} {
    return {
        distribution_mode: props.department.distribution_mode,
        max_open_conversations_per_agent:
            props.department.max_open_conversations_per_agent ?? '',
    };
}

const form = useForm(currentValues());

watch(
    () => [
        props.department.distribution_mode,
        props.department.max_open_conversations_per_agent,
    ],
    () => {
        if (!form.isDirty) {
            form.defaults(currentValues());
            form.reset();
        }
    },
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        max_open_conversations_per_agent:
            data.max_open_conversations_per_agent === ''
                ? null
                : data.max_open_conversations_per_agent,
    })).submit(update(props.department.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(currentValues());
            form.reset();
        },
    });
}

const current = () =>
    props.modes.find(
        (mode) => mode.value === props.department.distribution_mode,
    );
</script>

<template>
    <SurfaceCard
        title="Distribución de conversaciones"
        description="Cómo llegan las conversaciones pendientes de este departamento a sus agentes."
        body-class="p-5"
    >
        <form v-if="canUpdate" class="space-y-4" @submit.prevent="submit">
            <fieldset class="grid gap-2">
                <legend
                    class="mb-2 flex items-center gap-1.5 text-sm font-medium"
                >
                    Forma de distribución
                    <FieldHelp
                        title="Forma de distribución"
                        :help="{
                            description:
                                'Decide si los agentes toman las conversaciones de «Pendientes», si solo las entrega quien tiene permiso de asignar, o si se reparten solas.',
                            howTo: 'La asignación automática elige al agente disponible con menos conversaciones activas; si nadie está disponible, la conversación queda en «Pendientes».',
                            warning:
                                'Cambiar la forma de distribución nunca quita conversaciones ya asignadas.',
                        }"
                    />
                </legend>
                <label
                    v-for="mode in modes"
                    :key="mode.value"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary-soft/60"
                >
                    <input
                        v-model="form.distribution_mode"
                        type="radio"
                        name="distribution_mode"
                        :value="mode.value"
                        class="mt-1 accent-primary"
                    />
                    <span class="grid gap-0.5">
                        <span class="text-sm font-medium">{{
                            mode.label
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            mode.description
                        }}</span>
                    </span>
                </label>
                <InputError :message="form.errors.distribution_mode" />
            </fieldset>
            <div class="grid gap-2">
                <Label
                    for="department-agent-limit"
                    class="flex items-center gap-1.5"
                >
                    Límite de conversaciones por agente (opcional)
                    <FieldHelp
                        title="Límite por agente"
                        :help="{
                            description:
                                'Máximo de conversaciones en atención que un agente puede tener para aceptar o recibir automáticamente una conversación de este departamento. Se cuentan todas sus conversaciones abiertas o en espera, de cualquier departamento.',
                            howTo: 'Déjalo vacío para usar el límite de la plataforma. Un límite propio en la ficha del agente tiene prioridad.',
                            warning:
                                'Bajar el límite no quita conversaciones ya asignadas; subirlo reparte de inmediato las pendientes.',
                        }"
                    />
                </Label>
                <Input
                    id="department-agent-limit"
                    v-model="form.max_open_conversations_per_agent"
                    type="number"
                    min="1"
                    max="200"
                    inputmode="numeric"
                    placeholder="Usa el límite de la plataforma"
                    class="max-w-56"
                />
                <InputError
                    :message="form.errors.max_open_conversations_per_agent"
                />
            </div>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!form.isDirty || form.processing"
                    @click="form.reset()"
                >
                    Descartar
                </Button>
                <Button
                    type="submit"
                    :disabled="!form.isDirty || form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar distribución
                </Button>
            </div>
        </form>
        <div v-else class="grid gap-1">
            <p class="text-sm font-medium">{{ current()?.label }}</p>
            <p class="text-sm text-muted-foreground">
                {{ current()?.description }}
            </p>
            <p
                v-if="department.max_open_conversations_per_agent !== null"
                class="text-sm text-muted-foreground"
            >
                Límite propio: hasta
                {{ department.max_open_conversations_per_agent }}
                conversaciones en atención por agente.
            </p>
        </div>
    </SurfaceCard>
</template>
