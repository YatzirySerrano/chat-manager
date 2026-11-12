<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Hand, Inbox, Shuffle } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, watch } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
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

const modeIcons: Record<string, LucideIcon> = {
    shared: Inbox,
    manual: Hand,
    automatic: Shuffle,
};

const modeCards = computed(() =>
    props.modes.map((mode) => ({
        value: mode.value,
        label: mode.label,
        description: mode.description,
        icon: modeIcons[mode.value],
    })),
);

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
            <div class="grid gap-2">
                <p class="flex items-center gap-1.5 text-sm font-medium">
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
                </p>
                <RadioCards
                    v-model="form.distribution_mode"
                    :options="modeCards"
                    name="distribution_mode"
                    aria-label="Forma de distribución"
                    :invalid="!!form.errors.distribution_mode"
                    class="grid-cols-1"
                />
                <InputError :message="form.errors.distribution_mode" />
            </div>
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
                    class="sm:w-64"
                />
                <InputError
                    :message="form.errors.max_open_conversations_per_agent"
                />
            </div>
            <div class="flex flex-wrap justify-end gap-2">
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
                    :disabled="!form.isDirty"
                    :loading="form.processing"
                >
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
