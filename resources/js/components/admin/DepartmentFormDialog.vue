<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ColorPicker from '@/components/ColorPicker.vue';
import FormField from '@/components/FormField.vue';
import FormDialog from '@/components/FormDialog.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength, required } from '@/lib/validation';
import { store, update } from '@/routes/departments';
import type { AppColor, ColorSwatch, DepartmentSummary } from '@/types';

const props = defineProps<{
    department: DepartmentSummary | null;
    colors: ColorSwatch[];
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.department !== null);

const form = useForm<{
    name: string;
    description: string;
    color: AppColor;
}>({
    name: '',
    description: '',
    color: 'teal',
});

const validation = useFormValidation(form, {
    rules: {
        name: [required('Escribe el nombre.'), maxLength(120)],
        description: [maxLength(500)],
        color: [required('Elige un color.')],
    },
    requireChanges: isEditing,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    validation.load({
        name: props.department?.name ?? '',
        description: props.department?.description ?? '',
        color: props.department?.color ?? 'teal',
    });
});

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.department) {
        form.submit(update(props.department.id), options);
    } else {
        form.submit(store(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="isEditing ? 'Editar departamento' : 'Nuevo departamento'"
        description="Los departamentos agrupan a las personas y las conversaciones que atienden."
        :processing="form.processing"
        :validation="validation"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear departamento'"
        @submit="submit"
    >
        <div class="grid gap-5">
            <FormField
                id="department-name"
                label="Nombre"
                :error="validation.error('name')"
            >
                <Input
                    id="department-name"
                    v-model="form.name"
                    maxlength="120"
                    v-bind="validation.field('name', 'department-name')"
                />
            </FormField>

            <FormField
                id="department-description"
                label="Descripción (opcional)"
                :error="validation.error('description')"
            >
                <Textarea
                    id="department-description"
                    v-model="form.description"
                    rows="3"
                    maxlength="500"
                    v-bind="
                        validation.field(
                            'description',
                            'department-description',
                        )
                    "
                    placeholder="¿Qué tipo de solicitudes atiende?"
                />
            </FormField>

            <ColorPicker
                v-model="form.color"
                :colors="colors"
                group-label="Color del departamento"
                :error="validation.error('color')"
            />
        </div>
    </FormDialog>
</template>
