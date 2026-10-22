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
import { store, update } from '@/routes/tags';
import type { AppColor, ColorSwatch, TagSummary } from '@/types';

const props = defineProps<{
    tag: TagSummary | null;
    colors: ColorSwatch[];
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.tag !== null);

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
        name: [required('Escribe el nombre.'), maxLength(60)],
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
        name: props.tag?.name ?? '',
        description: props.tag?.description ?? '',
        color: props.tag?.color ?? 'blue',
    });
});

const previewName = computed(
    () => form.name.trim().replace(/\s+/g, ' ') || 'Nombre de la etiqueta',
);

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.tag) {
        form.submit(update(props.tag.id), options);
    } else {
        form.submit(store(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="isEditing ? 'Editar etiqueta' : 'Nueva etiqueta'"
        description="Las etiquetas clasifican contactos y conversaciones. No otorgan permisos ni cambian estados."
        :processing="form.processing"
        :validation="validation"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear etiqueta'"
        @submit="submit"
    >
        <div class="grid gap-5">
            <FormField
                id="tag-name"
                label="Nombre"
                :error="validation.error('name')"
                :help="{
                    description:
                        'Texto corto que verá el equipo en contactos y conversaciones.',
                    howTo: 'Usa una o dos palabras. No se distinguen mayúsculas de minúsculas: «VIP» y «vip» son la misma etiqueta.',
                    example: 'Cliente frecuente',
                }"
            >
                <Input
                    id="tag-name"
                    v-model="form.name"
                    maxlength="60"
                    v-bind="validation.field('name', 'tag-name')"
                />
            </FormField>

            <FormField
                id="tag-description"
                label="Descripción (opcional)"
                :error="validation.error('description')"
                hint="Explica cuándo aplicarla para que todos la usen igual."
            >
                <Textarea
                    id="tag-description"
                    v-model="form.description"
                    rows="2"
                    maxlength="255"
                    v-bind="validation.field('description', 'tag-description')"
                />
            </FormField>

            <ColorPicker
                v-model="form.color"
                :colors="colors"
                group-label="Color de la etiqueta"
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
