<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import ServiceRulesCard from '@/components/service-rules/ServiceRulesCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useServiceRulesForm } from '@/composables/useServiceRulesForm';
import { update } from '@/routes/departments/service-rules';
import type { DepartmentSummary, ServiceRuleSet } from '@/types';

const props = defineProps<{
    department: DepartmentSummary;
    rules: ServiceRuleSet;
    canUpdate: boolean;
}>();

const {
    form,
    values,
    errors,
    generalError,
    revision,
    update: setRule,
    load,
    submit,
} = useServiceRulesForm(() => props.rules, 'department');

function save(): void {
    submit(update(props.department.id));
}
</script>

<template>
    <SurfaceCard
        title="Reglas de servicio"
        description="Este departamento usa los valores generales de Configuración, salvo en las reglas que personalices aquí. Una categoría de solicitud puede tener, a su vez, valores propios que tienen prioridad."
        body-class="p-4 sm:p-5"
        data-department-service-rules
    >
        <form class="grid gap-4" novalidate @submit.prevent="save">
            <ServiceRulesCard
                :key="revision"
                level="department"
                :rules="rules"
                :values="values"
                :errors="errors"
                :readonly="!canUpdate"
                id-prefix="department-rule"
                @update="setRule"
            />
            <InputError :message="generalError" />
            <div v-if="canUpdate" class="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!form.isDirty || form.processing"
                    @click="load()"
                >
                    Descartar
                </Button>
                <Button
                    type="submit"
                    :disabled="!form.isDirty || form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar reglas
                </Button>
            </div>
        </form>
    </SurfaceCard>
</template>
