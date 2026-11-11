<script setup lang="ts">
import { watch } from 'vue';
import FormDialog from '@/components/FormDialog.vue';
import InputError from '@/components/InputError.vue';
import ServiceRulesCard from '@/components/service-rules/ServiceRulesCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import { useServiceRulesForm } from '@/composables/useServiceRulesForm';
import { update } from '@/routes/categories/rules';
import type { ConversationCategorySummary, ServiceRuleSet } from '@/types';

const props = defineProps<{
    category: ConversationCategorySummary;
}>();

const open = defineModel<boolean>('open', { required: true });

const {
    form,
    values,
    errors,
    generalError,
    revision,
    update: setRule,
    load,
    submit,
} = useServiceRulesForm((): ServiceRuleSet => props.category.rules, 'category');

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    }
});

function save(): void {
    submit(update(props.category.id), () => {
        open.value = false;
    });
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        size="xl"
        :title="`Reglas de servicio de «${category.name}»`"
        description="Valores propios de esta categoría. Tienen prioridad sobre los del departamento y sobre los generales."
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-disabled="!form.isDirty"
        submit-label="Guardar reglas"
        @submit="save"
    >
        <div class="grid gap-4">
            <StatusNote tone="info">
                Lo que no personalices se hereda. Aquí se muestra el valor
                general; si el departamento de una solicitud tiene un valor
                propio, ese es el que hereda la categoría.
            </StatusNote>
            <ServiceRulesCard
                :key="revision"
                level="category"
                :rules="category.rules"
                :values="values"
                :errors="errors"
                id-prefix="category-rule"
                @update="setRule"
            />
            <InputError :message="generalError" />
        </div>
    </FormDialog>
</template>
