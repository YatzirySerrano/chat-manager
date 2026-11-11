<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ColorPicker from '@/components/ColorPicker.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength, normalizers, required } from '@/lib/validation';
import { store, update } from '@/routes/categories';
import type {
    AppColor,
    ColorSwatch,
    ConversationCategorySummary,
} from '@/types';

const props = defineProps<{
    category: ConversationCategorySummary | null;
    colors: ColorSwatch[];
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.category !== null);

const form = useForm<{
    name: string;
    description: string;
    color: AppColor;
}>({
    name: '',
    description: '',
    color: 'blue',
});

const validation = useFormValidation(form, {
    rules: {
        name: [required('Escribe el nombre.'), maxLength(120)],
        description: [maxLength(255)],
        color: [required('Elige un color.')],
    },
    normalize: { name: normalizers.squish },
    requireChanges: isEditing,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    validation.load({
        name: props.category?.name ?? '',
        description: props.category?.description ?? '',
        color: props.category?.color ?? 'blue',
    });
});

const previewName = computed(
    () => form.name.trim().replace(/\s+/g, ' ') || 'Nombre de la categoría',
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.category) {
        form.submit(update(props.category.id), options);
    } else {
        form.submit(store(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="isEditing ? 'Editar categoría' : 'Nueva categoría'"
        description="Una categoría describe qué pide el cliente. Puede tener reglas de servicio propias, que se editan aparte."
        :processing="form.processing"
        :validation="validation"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear categoría'"
        @submit="submit"
    >
        <div class="grid gap-5">
            <FormField
                id="category-name"
                label="Nombre"
                :error="validation.error('name')"
                :help="{
                    description:
                        'Tipo de solicitud tal como lo verá el equipo al clasificar una conversación.',
                    howTo: 'Usa pocas palabras. No se distinguen mayúsculas de minúsculas: «Garantías» y «garantías» son la misma categoría.',
                    example: 'Garantías',
                }"
            >
                <Input
                    id="category-name"
                    v-model="form.name"
                    maxlength="120"
                    v-bind="validation.field('name', 'category-name')"
                />
            </FormField>

            <FormField
                id="category-description"
                label="Descripción (opcional)"
                :error="validation.error('description')"
                hint="Explica cuándo elegirla para que todos clasifiquen igual."
            >
                <Textarea
                    id="category-description"
                    v-model="form.description"
                    rows="2"
                    maxlength="255"
                    v-bind="
                        validation.field('description', 'category-description')
                    "
                />
            </FormField>

            <ColorPicker
                v-model="form.color"
                :colors="colors"
                group-label="Color de la categoría"
                :error="validation.error('color')"
            />

            <div class="grid gap-2 rounded-lg border bg-muted/40 p-3">
                <p class="text-xs font-medium text-muted-foreground">
                    Vista previa
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <TagBadge
                        :name="previewName"
                        :color="form.color"
                        size="md"
                    />
                </div>
            </div>
        </div>
    </FormDialog>
</template>
