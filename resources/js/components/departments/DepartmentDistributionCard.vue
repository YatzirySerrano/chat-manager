<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
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

const form = useForm({ distribution_mode: props.department.distribution_mode });

watch(
    () => props.department.distribution_mode,
    (mode) => {
        if (!form.isDirty) {
            form.defaults({ distribution_mode: mode });
            form.reset();
        }
    },
);

function submit(): void {
    form.submit(update(props.department.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({
                distribution_mode: props.department.distribution_mode,
            });
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
                                'Decide si los agentes toman las conversaciones de la cola, si solo las entrega quien tiene permiso de asignar, o si se reparten solas.',
                            howTo: 'La asignación automática elige al agente disponible con menos conversaciones activas; si nadie está disponible, la conversación espera en la cola.',
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
        </div>
    </SurfaceCard>
</template>
