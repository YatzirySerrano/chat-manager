<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { watch } from 'vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import { Input } from '@/components/ui/input';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength, normalizers, required } from '@/lib/validation';
import type { RouteDefinition } from '@/wayfinder';

/**
 * "Editar nombre": the official name of the contact, the one the whole team
 * sees in every conversation of the person. It changes the shared record; nothing else of the contact is touched.
 */
const props = defineProps<{
    name: string;
    profileName: string | null;
    action: RouteDefinition<'put'>;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm<{ name: string }>({ name: '' });

const validation = useFormValidation(form, {
    rules: { name: [required('Escribe el nombre.'), maxLength(160)] },
    normalize: { name: normalizers.squish },
});

watch(open, (isOpen) => {
    if (isOpen) {
        validation.load({ name: props.name || props.profileName || '' });
    }
});

function submit(): void {
    form.submit(props.action, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        title="Editar nombre del contacto"
        description="Cambia el nombre oficial del contacto para todo el equipo."
        :processing="form.processing"
        :validation="validation"
        submit-label="Guardar nombre"
        @submit="submit"
    >
        <div class="grid gap-5">
            <p
                class="flex gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    Todos lo verán con este nombre, en esta y en sus demás
                    conversaciones.
                </span>
            </p>
            <FormField
                id="rename-contact-name"
                label="Nombre en el directorio"
                :error="validation.error('name')"
                :hint="
                    profileName
                        ? `Nombre de perfil del cliente: ${profileName}`
                        : undefined
                "
            >
                <Input
                    id="rename-contact-name"
                    v-model="form.name"
                    maxlength="160"
                    autocomplete="off"
                    v-bind="validation.field('name', 'rename-contact-name')"
                />
            </FormField>
        </div>
    </FormDialog>
</template>
