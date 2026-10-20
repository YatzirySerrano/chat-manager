<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ColorSwatchPicker from '@/components/ColorSwatchPicker.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/tags';
import type { ColorSwatch, TagSummary } from '@/types';

const props = defineProps<{
    tag: TagSummary | null;
    colors: ColorSwatch[];
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.tag !== null);

const form = useForm<{
    name: string;
    description: string;
    color: ColorSwatch;
}>({
    name: '',
    description: '',
    color: 'blue',
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.defaults({
        name: props.tag?.name ?? '',
        description: props.tag?.description ?? '',
        color: props.tag?.color ?? 'blue',
    });
    form.reset();
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
        :dirty="form.isDirty"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear etiqueta'"
        @submit="submit"
    >
        <div class="grid gap-5">
            <FormField
                id="tag-name"
                label="Nombre"
                :error="form.errors.name"
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
                    :aria-invalid="!!form.errors.name"
                />
            </FormField>

            <FormField
                id="tag-description"
                label="Descripción (opcional)"
                :error="form.errors.description"
                hint="Explica cuándo aplicarla para que todos la usen igual."
            >
                <Textarea
                    id="tag-description"
                    v-model="form.description"
                    rows="2"
                    maxlength="255"
                />
            </FormField>

            <ColorSwatchPicker
                v-model="form.color"
                :colors="colors"
                group-label="Color de la etiqueta"
                :error="form.errors.color"
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
                    <TagBadge :name="previewName" :color="form.color" />
                </div>
            </div>
        </div>
    </FormDialog>
</template>
