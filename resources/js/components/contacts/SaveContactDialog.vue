<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed, watch } from 'vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { formatPhone } from '@/lib/phone';
import { email, maxLength, normalizers, required } from '@/lib/validation';
import type { RouteDefinition } from '@/wayfinder';

/**
 * "Guardar contacto": puts a provisional contact (someone who wrote in and
 * was registered automatically) into the shared contacts directory. The
 * name and email typed here update the official record; the phone and the
 * channel identity never change, so the conversation keeps working.
 */
const props = defineProps<{
    contact: {
        id: number;
        name: string;
        profile_name: string | null;
        phone_e164: string | null;
        email: string | null;
    };
    action: RouteDefinition<'post'>;
    canAddNote: boolean;
    /** Where focus goes when the dialog closes (its trigger may be gone). */
    returnFocus?: () => HTMLElement | null | undefined;
}>();

const open = defineModel<boolean>('open', { required: true });

const NOTE_MAX = 2000;

const form = useForm<{
    name: string;
    email: string;
    note: string;
    confirm_duplicate: boolean;
}>({
    name: '',
    email: '',
    note: '',
    confirm_duplicate: false,
});

const validation = useFormValidation(form, {
    rules: {
        name: [required('Escribe el nombre.'), maxLength(160)],
        email: [email(), maxLength(255)],
        note: [maxLength(NOTE_MAX)],
    },
    normalize: {
        name: normalizers.squish,
        email: normalizers.email,
    },
    ignore: ['confirm_duplicate'],
});

watch(open, (isOpen) => {
    if (isOpen) {
        validation.load({
            name: props.contact.name || props.contact.profile_name || '',
            email: props.contact.email ?? '',
            note: '',
            confirm_duplicate: false,
        });
    }
});

/* Refusals not tied to a field (e.g. someone saved it a moment ago). */
const generalError = computed(
    () => (form.errors as Record<string, string | undefined>).contact,
);

watch(
    () => form.email,
    () => {
        form.confirm_duplicate = false;
    },
);

function submit(confirmDuplicate = false): void {
    form.confirm_duplicate = confirmDuplicate;
    form.transform((data) => ({
        name: data.name,
        email: data.email,
        confirm_duplicate: data.confirm_duplicate,
        ...(props.canAddNote ? { note: data.note } : {}),
    }));
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
        title="Guardar contacto"
        description="Agrega a esta persona al directorio de Contactos de la empresa para encontrarla, etiquetarla y verla en los reportes."
        :processing="form.processing"
        :validation="validation"
        submit-label="Guardar contacto"
        :return-focus="returnFocus"
        @submit="submit(false)"
    >
        <div class="grid gap-5">
            <p
                class="flex gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                <Info class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    Esto actualiza el directorio compartido; para un nombre solo
                    visible para ti usa Alias personal.
                </span>
            </p>

            <FormField
                id="save-contact-name"
                label="Nombre en el directorio"
                :error="validation.error('name')"
                :hint="
                    contact.profile_name
                        ? `Nombre de perfil del cliente: ${contact.profile_name}`
                        : undefined
                "
            >
                <Input
                    id="save-contact-name"
                    v-model="form.name"
                    maxlength="160"
                    autocomplete="off"
                    v-bind="validation.field('name', 'save-contact-name')"
                />
            </FormField>

            <FormField
                id="save-contact-phone"
                label="Teléfono"
                hint="Es el dato con el que el canal identifica a la persona; no se puede cambiar aquí."
            >
                <Input
                    id="save-contact-phone"
                    :model-value="
                        contact.phone_e164
                            ? formatPhone(contact.phone_e164)
                            : 'Sin teléfono'
                    "
                    readonly
                    aria-readonly="true"
                    class="bg-muted/50"
                />
            </FormField>

            <FormField
                id="save-contact-email"
                label="Correo electrónico (opcional)"
                :error="validation.error('email')"
            >
                <Input
                    id="save-contact-email"
                    v-model="form.email"
                    type="email"
                    maxlength="255"
                    autocomplete="off"
                    v-bind="validation.field('email', 'save-contact-email')"
                />
            </FormField>

            <div
                v-if="form.errors.confirm_duplicate"
                class="grid gap-2 rounded-lg border border-transparent bg-warning-soft p-3 text-sm"
                role="alert"
            >
                <InputError :message="form.errors.confirm_duplicate" />
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.processing"
                        @click="submit(true)"
                    >
                        Es otra persona, guardar de todos modos
                    </Button>
                </div>
            </div>

            <FormField
                v-if="canAddNote"
                id="save-contact-note"
                label="Nota privada (opcional)"
                :error="validation.error('note')"
                hint="Se agrega a las notas internas del contacto; el cliente nunca la ve."
            >
                <Textarea
                    id="save-contact-note"
                    v-model="form.note"
                    :maxlength="NOTE_MAX"
                    rows="3"
                    v-bind="validation.field('note', 'save-contact-note')"
                />
            </FormField>

            <InputError :message="generalError" />
        </div>
    </FormDialog>
</template>
