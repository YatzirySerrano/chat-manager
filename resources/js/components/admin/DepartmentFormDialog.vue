<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import FormDialog from '@/components/FormDialog.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { swatchClasses } from '@/lib/swatches';
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

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Color</legend>
                <div
                    class="flex flex-wrap gap-2"
                    role="radiogroup"
                    aria-label="Color del departamento"
                >
                    <button
                        v-for="color in colors"
                        :key="color"
                        type="button"
                        role="radio"
                        :aria-checked="form.color === color"
                        :aria-label="swatchClasses[color].label"
                        :title="swatchClasses[color].label"
                        class="flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="[
                            swatchClasses[color].dot,
                            form.color === color && 'ring-2 ring-foreground/60',
                        ]"
                        @click="form.color = color"
                    >
                        <Check
                            v-if="form.color === color"
                            class="size-4 text-white"
                            aria-hidden="true"
                        />
                    </button>
                </div>
                <InputError :message="form.errors.color" />
            </fieldset>
        </div>
    </FormDialog>
</template>
