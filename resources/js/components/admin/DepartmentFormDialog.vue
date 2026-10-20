<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ColorSwatchPicker from '@/components/ColorSwatchPicker.vue';
import FormField from '@/components/FormField.vue';
import FormDialog from '@/components/FormDialog.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/departments';
import type { ColorSwatch, DepartmentSummary } from '@/types';

const props = defineProps<{
    department: DepartmentSummary | null;
    colors: ColorSwatch[];
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.department !== null);

const form = useForm<{
    name: string;
    description: string;
    color: ColorSwatch;
}>({
    name: '',
    description: '',
    color: 'teal',
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        name: props.department?.name ?? '',
        description: props.department?.description ?? '',
        color: props.department?.color ?? 'teal',
    });
    form.reset();
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
        :dirty="form.isDirty"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear departamento'"
        @submit="submit"
    >
        <div class="grid gap-5">
            <FormField
                id="department-name"
                label="Nombre"
                :error="form.errors.name"
            >
                <Input
                    id="department-name"
                    v-model="form.name"
                    maxlength="120"
                    :aria-invalid="!!form.errors.name"
                />
            </FormField>

            <FormField
                id="department-description"
                label="Descripción (opcional)"
                :error="form.errors.description"
            >
                <Textarea
                    id="department-description"
                    v-model="form.description"
                    rows="3"
                    maxlength="500"
                    placeholder="¿Qué tipo de solicitudes atiende?"
                />
            </FormField>

            <ColorSwatchPicker
                v-model="form.color"
                :colors="colors"
                group-label="Color del departamento"
                :error="form.errors.color"
            />
        </div>
    </FormDialog>
</template>
