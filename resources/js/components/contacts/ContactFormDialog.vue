<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { CircleCheck, Plus, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFormValidation } from '@/composables/useFormValidation';
import { formatPhone, normalizePhone } from '@/lib/phone';
import {
    email,
    maxLength,
    normalizers,
    phone,
    required,
} from '@/lib/validation';
import type { FieldRule, FieldRules } from '@/lib/validation';
import { show, store, update } from '@/routes/contacts';
import type { Contact, DuplicateCheck } from '@/types';

const MAX_CUSTOM_FIELDS = 10;

const props = defineProps<{
    contact: Contact | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.contact !== null);

type CustomFieldRow = { label: string; value: string };

const form = useForm<{
    name: string;
    phone: string;
    email: string;
    custom_fields: CustomFieldRow[];
    confirm_duplicate: boolean;
}>({
    name: '',
    phone: '',
    email: '',
    custom_fields: [],
    confirm_duplicate: false,
});

const duplicates = ref<DuplicateCheck | null>(null);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    duplicates.value = null;
    validation.load({
        name: props.contact?.name ?? '',
        phone: props.contact?.phone_e164 ?? '',
        email: props.contact?.email ?? '',
        custom_fields: Object.entries(props.contact?.custom_fields ?? {}).map(
            ([label, value]) => ({ label, value }),
        ),
        confirm_duplicate: false,
    });
});

/**
 * A different phone or email needs a new duplicate check.
 */
watch(
    () => [form.phone, form.email],
    () => {
        duplicates.value = null;
        form.confirm_duplicate = false;
    },
);

const stopFlashListener = router.on('flash', (event) => {
    const check = event.detail.flash?.duplicates as DuplicateCheck | undefined;

    if (check && open.value) {
        duplicates.value = check;
    }
});

onUnmounted(stopFlashListener);

const normalizedPhone = computed(() => normalizePhone(form.phone));
const phoneTyped = computed(() => form.phone.trim() !== '');

const squish = (value: string): string => value.trim().replace(/\s+/g, ' ');

const phoneOrEmail: FieldRule = (_value, data) =>
    String(data.phone ?? '').trim() === '' &&
    String(data.email ?? '').trim() === ''
        ? 'Captura al menos un teléfono o un correo electrónico.'
        : null;

const validation = useFormValidation(form, {
    rules: () => {
        const rules: FieldRules = {
            name: [required('Escribe el nombre.'), maxLength(160)],
            phone: [phoneOrEmail, maxLength(32), phone()],
            email: [phoneOrEmail, email(), maxLength(255)],
        };

        form.custom_fields.forEach((_field, index) => {
            rules[`custom_fields.${index}.label`] = [
                required('Escribe el nombre del campo.'),
                maxLength(60),
                (value) => {
                    const label = squish(String(value)).toLowerCase();
                    const firstIndex = form.custom_fields.findIndex(
                        (field) => squish(field.label).toLowerCase() === label,
                    );

                    return firstIndex !== index
                        ? 'Cada campo personalizado debe tener un nombre distinto.'
                        : null;
                },
            ];
            rules[`custom_fields.${index}.value`] = [
                required('Escribe el valor del campo.'),
                maxLength(500),
            ];
        });

        return rules;
    },
    normalize: {
        name: normalizers.squish,
        phone: normalizers.phone,
        email: normalizers.email,
        custom_fields: (value) =>
            normalizers.ordered(
                (value as CustomFieldRow[]).map((field) => ({
                    label: squish(field.label),
                    value: squish(field.value),
                })),
            ),
    },
    ignore: ['confirm_duplicate'],
    requireChanges: isEditing,
});

function addCustomField(): void {
    if (form.custom_fields.length < MAX_CUSTOM_FIELDS) {
        form.custom_fields.push({ label: '', value: '' });
    }
}

function removeCustomField(index: number): void {
    form.custom_fields.splice(index, 1);
}

function submit(confirmDuplicate = false): void {
    form.confirm_duplicate = confirmDuplicate;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.contact) {
        form.submit(update(props.contact.id), options);
    } else {
        form.submit(store(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        size="lg"
        :title="isEditing ? 'Editar contacto' : 'Nuevo contacto'"
        description="Registra a la persona con su teléfono o correo. Si ya existe, te avisaremos antes de guardar."
        :processing="form.processing"
        :validation="validation"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear contacto'"
        @submit="submit(false)"
    >
        <div class="grid gap-5">
            <div
                v-if="duplicates"
                class="space-y-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
                role="alert"
            >
                <div class="flex gap-2">
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <div class="space-y-1">
                        <p class="font-medium">Posible contacto duplicado</p>
                        <p class="text-muted-foreground">
                            Revisa si se trata de la misma persona. Los
                            contactos nunca se combinan automáticamente.
                        </p>
                    </div>
                </div>

                <ul
                    v-if="duplicates.contacts.length > 0"
                    class="divide-y rounded-md border bg-card"
                >
                    <li
                        v-for="duplicate in duplicates.contacts"
                        :key="duplicate.id"
                        class="flex items-center justify-between gap-3 px-3 py-2"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ duplicate.name }}
                                <span
                                    v-if="duplicate.status === 'archived'"
                                    class="text-xs font-normal text-muted-foreground"
                                    >(eliminado)</span
                                >
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                <span v-if="duplicate.matches.includes('phone')"
                                    >Mismo teléfono:
                                    {{
                                        formatPhone(duplicate.phone_e164)
                                    }}</span
                                >
                                <span v-if="duplicate.matches.length > 1">
                                    ·
                                </span>
                                <span v-if="duplicate.matches.includes('email')"
                                    >Mismo correo: {{ duplicate.email }}</span
                                >
                            </p>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <a
                                :href="show.url(duplicate.id)"
                                target="_blank"
                                rel="noopener"
                                >Ver</a
                            >
                        </Button>
                    </li>
                </ul>

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

            <InputError
                v-if="!duplicates"
                :message="form.errors.confirm_duplicate"
            />

            <FormField
                id="contact-name"
                label="Nombre"
                :error="validation.error('name')"
            >
                <Input
                    id="contact-name"
                    v-model="form.name"
                    maxlength="160"
                    autocomplete="off"
                    v-bind="validation.field('name', 'contact-name')"
                />
            </FormField>

            <div class="grid gap-5 sm:grid-cols-2">
                <FormField
                    id="contact-phone"
                    label="Teléfono"
                    :error="validation.error('phone')"
                    :help="{
                        description:
                            'Se guarda en formato internacional (E.164) para reconocer a la persona cuando escriba por WhatsApp.',
                        howTo: 'Escribe 10 dígitos para un número de México, o el número completo con + y lada internacional para otros países.',
                        example: '55 1234 5678  ·  +1 415 555 2671',
                        warning:
                            'Si otro contacto ya usa este teléfono te pediremos confirmar antes de guardar.',
                    }"
                >
                    <Input
                        id="contact-phone"
                        v-model="form.phone"
                        type="tel"
                        inputmode="tel"
                        maxlength="32"
                        autocomplete="off"
                        v-bind="
                            validation.field(
                                'phone',
                                'contact-phone',
                                'contact-phone-preview',
                            )
                        "
                    />
                    <p
                        v-if="phoneTyped && !validation.error('phone')"
                        id="contact-phone-preview"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        aria-live="polite"
                    >
                        <template v-if="normalizedPhone">
                            <CircleCheck
                                class="size-3.5 text-success"
                                aria-hidden="true"
                            />
                            Se guardará como
                            <span class="font-mono text-foreground">{{
                                normalizedPhone
                            }}</span>
                        </template>
                        <template v-else>
                            Escribe 10 dígitos para México, o + y la lada
                            internacional para otros países.
                        </template>
                    </p>
                </FormField>

                <FormField
                    id="contact-email"
                    label="Correo electrónico"
                    :error="validation.error('email')"
                    hint="Opcional si capturas un teléfono."
                >
                    <Input
                        id="contact-email"
                        v-model="form.email"
                        type="email"
                        maxlength="255"
                        autocomplete="off"
                        v-bind="validation.field('email', 'contact-email')"
                    />
                </FormField>
            </div>

            <fieldset class="grid gap-3">
                <div class="flex items-center justify-between gap-2">
                    <legend class="text-sm font-medium">
                        Campos personalizados
                    </legend>
                    <span class="text-xs text-muted-foreground"
                        >{{ form.custom_fields.length }} /
                        {{ MAX_CUSTOM_FIELDS }}</span
                    >
                </div>
                <p
                    v-if="form.custom_fields.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Agrega datos propios de tu negocio, como número de cliente o
                    sucursal. Solo texto.
                </p>
                <div
                    v-for="(field, index) in form.custom_fields"
                    :key="index"
                    class="grid grid-cols-[1fr_1.5fr_auto] items-start gap-2"
                >
                    <div class="grid gap-1">
                        <Input
                            :id="`contact-field-${index}-label`"
                            v-model="field.label"
                            maxlength="60"
                            placeholder="Nombre del campo"
                            :aria-label="`Nombre del campo ${index + 1}`"
                            v-bind="
                                validation.field(
                                    `custom_fields.${index}.label`,
                                    `contact-field-${index}-label`,
                                )
                            "
                        />
                        <InputError
                            :id="`contact-field-${index}-label-error`"
                            :message="
                                validation.error(`custom_fields.${index}.label`)
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            :id="`contact-field-${index}-value`"
                            v-model="field.value"
                            maxlength="500"
                            placeholder="Valor"
                            :aria-label="`Valor del campo ${index + 1}`"
                            v-bind="
                                validation.field(
                                    `custom_fields.${index}.value`,
                                    `contact-field-${index}-value`,
                                )
                            "
                        />
                        <InputError
                            :id="`contact-field-${index}-value-error`"
                            :message="
                                validation.error(`custom_fields.${index}.value`)
                            "
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Quitar campo ${index + 1}`"
                        @click="removeCustomField(index)"
                    >
                        <Trash2 />
                    </Button>
                </div>
                <InputError :message="validation.error('custom_fields')" />
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                            form.custom_fields.length >= MAX_CUSTOM_FIELDS
                        "
                        @click="addCustomField"
                    >
                        <Plus />
                        Agregar campo
                    </Button>
                </div>
            </fieldset>
        </div>
    </FormDialog>
</template>
